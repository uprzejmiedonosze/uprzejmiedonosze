<?PHP

namespace mcp;

/**
 * Statuses an MCP client may set via update_report_status — the outcomes a
 * user records after a report has been sent (the authority's response). The
 * domain layer still validates the transition is allowed from the report's
 * current status. Values match src/api/config/statuses.json.
 */
enum ReportStatus: string {
    case ConfirmedSm = 'confirmed-sm';                 // przyjęte przez SM/policję
    case ConfirmedFined = 'confirmed-fined';           // kierowca dostał mandat
    case ConfirmedInstructed = 'confirmed-instructed'; // pouczenie
    case ConfirmedIgnored = 'confirmed-ignored';       // zgłoszenie zignorowane
    case ConfirmedComplaint = 'confirmed-complaint';   // złożone zażalenie
    case Archived = 'archived';                        // zarchiwizowane
}

/**
 * MCP tools over a user's own reports (zgłoszenia). They call the domain/store
 * functions directly. The authenticated user comes from {@see McpIdentity},
 * set by the entry-point middleware before the SDK dispatches the tool call.
 */
final class ReportMcpTools {

    /**
     * List the current user's reports.
     *
     * @param string $status Filter: 'all' (default, excludes drafts), a concrete
     *                        status id (e.g. 'confirmed-waiting'), or 'allWithDrafts'.
     * @param int    $limit  Maximum number of reports to return (default 50).
     * @return array{reports: array} The reports, newest first.
     */
    public function listReports(string $status = 'all', int $limit = 50): array {
        McpIdentity::requireScope('reports:read');
        $user = McpIdentity::currentUser();
        $apps = \user\apps($user, $status, 'all', $limit, 0);
        // Wrap in an object: MCP structuredContent must be an object, not an
        // array. Normalise the domain objects to plain arrays too.
        return ['reports' => array_map([$this, 'enrich'], $apps)];
    }

    /**
     * Fetch a single report owned by the current user.
     *
     * @param string $reportId The report id.
     * @return array The report as a structured object.
     */
    public function getReport(string $reportId): array {
        McpIdentity::requireScope('reports:read');
        $user = McpIdentity::currentUser();

        // \app\get throws (rather than returning null) for an unknown id; surface
        // it — and a wrong-owner report — as the same readable "not found", so we
        // never confirm another user's report exists. Ownership is by email.
        try {
            $application = \app\get($reportId);
        } catch (\Throwable $e) {
            throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found", 0, $e);
        }
        if ($application->email !== $user->getEmail()) {
            throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found");
        }

        return $this->enrich($application);
    }

    /**
     * Serialise a report to a plain array and expand its category into
     * categoryInfo (title, formal wording, legal basis, penalty).
     */
    private function enrich(\app\Application $application): array {
        global $CATEGORIES;
        $report = json_decode(json_encode($application), true) ?? [];

        $category = $CATEGORIES[$application->category] ?? null;
        if ($category) {
            $report['categoryInfo'] = self::categorySummary((int) $application->category, $category);
        }

        // Expand the recipient authority: `smCity` is only a key (e.g. "Szczecin"),
        // so resolve it to the unit the report is addressed to — name, address,
        // email, and whether it's police. Only once a recipient has been resolved
        // (a geocoded/sent report has smCity set).
        if (!empty($application->smCity)) {
            $sm = $application->guessSMData();
            $report['recipientInfo'] = [
                'name' => $sm->getName(),
                'address' => $sm->getAddress(),
                'email' => $sm->getEmail(),
                'isPolice' => $sm->isPolice(),
            ];
        }

        return $report;
    }

    /**
     * The public shape of a violation category, shared by categoryInfo (on a
     * report) and list_categories. `fine`/`demeritPoints` are English-friendly
     * names for the Polish "mandat" (PLN) / "punkty karne".
     */
    private static function categorySummary(int $id, \Category $category): array {
        return [
            'id' => $id,
            'title' => $category->getTitle(),
            'formal' => $category->getFormal(),
            'law' => $category->getLaw(),
            'fine' => $category->getMandate(),
            'demeritPoints' => $category->getPoints(),
        ];
    }

    /**
     * Update the status of one of the signed-in user's reports — e.g. to
     * record the authority's response. The transition must be allowed for the
     * report's current status (enforced by the domain layer).
     *
     * @param string       $reportId The report id.
     * @param ReportStatus $status   The outcome to record.
     * @return array The updated report.
     */
    public function updateReportStatus(string $reportId, ReportStatus $status): array {
        McpIdentity::requireScope('reports:status:write');
        $user = McpIdentity::currentUser();

        // \app\get throws for an unknown id; report it (and a wrong-owner report)
        // as a readable "not found" rather than an opaque internal error.
        try {
            $application = \app\get($reportId);
        } catch (\Throwable $e) {
            throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found", 0, $e);
        }
        if ($application->email !== $user->getEmail()) {
            throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found");
        }

        try {
            $application = \setStatus($status->value, $reportId, $user);
        } catch (\Throwable $e) {
            // Surface the domain reason (e.g. an illegal status transition) to the
            // client instead of an opaque -32603; ToolCallException is returned as
            // a tool error result with the message.
            throw new \Mcp\Exception\ToolCallException($e->getMessage(), 0, $e);
        }

        return $this->enrich($application);
    }

    /**
     * Set the private annotations on one of the signed-in user's reports: the
     * authority case number and/or a free-text note. Both are private to the
     * user and are never sent to the authorities (they mirror the "NUMER SPRAWY"
     * and "UWAGI" fields). At least one must be provided; each given value
     * overwrites the current one.
     *
     * @param string      $reportId    The report id.
     * @param string|null $caseNumber  Authority (SM/Police) case number, e.g. "RSOW 123/24".
     * @param string|null $privateNote Free-text private note.
     * @return array The updated report.
     */
    public function setReportNotes(string $reportId, ?string $caseNumber = null, ?string $privateNote = null): array {
        McpIdentity::requireScope('reports:notes:write');
        $user = McpIdentity::currentUser();

        if ($caseNumber === null && $privateNote === null) {
            throw new \Mcp\Exception\ToolCallException('Provide caseNumber and/or privateNote.');
        }

        $updated = \semaphore\withLock($reportId, 'mcpSetNotes', function () use ($reportId, $user, $caseNumber, $privateNote) {
            // \app\get throws (rather than returning null) for an unknown id;
            // surface it as a readable "not found" instead of an opaque error.
            // ($e is chained as the previous exception for server-side logs; the
            // client only ever sees this ToolCallException's own message.)
            try {
                $application = \app\get($reportId);
            } catch (\Throwable $e) {
                throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found", 0, $e);
            }
            // Intentional, not a mistake: a report that exists but belongs to
            // someone else is reported as "not found" (not "forbidden") so we
            // never confirm that another user's report exists. Ownership is by
            // email, matching get_report / update_report_status and the app's
            // other ownership checks.
            if ($application->email !== $user->getEmail()) {
                throw new \Mcp\Exception\ToolCallException("Report '$reportId' not found");
            }
            if ($caseNumber !== null) {
                $application->externalId = $caseNumber;
            }
            if ($privateNote !== null) {
                $application->privateComment = $privateNote;
            }
            return \app\save($application);
        });

        return $this->enrich($updated);
    }

    /**
     * List the violation categories (id + title, formal wording, legal basis,
     * fine and demerit points) so the caller can pick one for create_report_draft
     * or interpret a report's `category` number.
     *
     * @return array{categories: array} The categories.
     */
    public function listCategories(): array {
        McpIdentity::requireScope('reports:read');
        global $CATEGORIES;

        $categories = [];
        foreach (($CATEGORIES ?? []) as $id => $category) {
            $categories[] = self::categorySummary((int) $id, $category);
        }

        return ['categories' => $categories];
    }

    /**
     * Create a new DRAFT report for the signed-in user, pre-filled from whatever
     * the caller can supply. The draft stays in the 'draft' status: a human must
     * open the returned editUrl to add the required photos and send it — MCP
     * cannot send a report. Up to three optional images (base64 data URIs) are
     * run through the same processing pipeline as the web upload.
     *
     * @param int|null    $category    Violation category id (see list_categories).
     * @param string|null $plateId     Licence plate.
     * @param string|null $description Free-text description of the violation.
     * @param string|null $address     Street address of the violation.
     * @param float|null  $lat         Latitude.
     * @param float|null  $lng         Longitude.
     * @param string|null $datetime     When it happened (ISO 8601).
     * @param string|null $carImage     Optional vehicle/plate photo (base64 data URI); runs plate recognition.
     * @param string|null $contextImage Optional wider-scene photo (base64 data URI).
     * @param string|null $thirdImage   Optional third photo (base64 data URI).
     * @return array{report: array, editUrl: string} The draft and the URL to finish it.
     */
    public function createReportDraft(
        ?int $category = null,
        ?string $plateId = null,
        ?string $description = null,
        ?string $address = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $datetime = null,
        ?string $carImage = null,
        ?string $contextImage = null,
        ?string $thirdImage = null
    ): array {
        McpIdentity::requireScope('reports:create');
        $user = McpIdentity::currentUser();

        global $CATEGORIES;
        if ($category !== null && !isset($CATEGORIES[$category])) {
            throw new \Mcp\Exception\ToolCallException(
                "Unknown category id $category — call list_categories for valid ids."
            );
        }
        // Decode/validate every supplied image up front (keyed by the pipeline's
        // picture-type name) so a bad one doesn't leave an orphaned draft behind.
        $images = [];
        foreach (['carImage' => $carImage, 'contextImage' => $contextImage, 'thirdImage' => $thirdImage] as $slot => $dataUri) {
            if ($dataUri !== null) {
                $images[$slot] = self::decodeImageDataUri($dataUri);
            }
        }

        // withUser records the creating client's User-Agent; a programmatic MCP
        // client may not send one, and the entry point turns the resulting
        // undefined-key warning into an error. Default it so headerless clients
        // can still create a draft.
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            $_SERVER['HTTP_USER_AGENT'] = 'MCP';
        }

        $draft = \app\Application::withUser($user);
        // A fresh draft has no history/comments/extensions yet; initialise them
        // so the stored record round-trips cleanly on re-read (Application::
        // withJson normalises these to arrays).
        $draft->statusHistory = [];
        $draft->comments = [];
        $draft->extensions = [];
        if ($category !== null) {
            $draft->category = $category;
        }
        if ($plateId !== null) {
            $carInfo = new \stdClass();
            $carInfo->plateId = strtoupper(\cleanWhiteChars($plateId));
            $draft->carInfo = $carInfo;
        }
        if ($description !== null) {
            $draft->userComment = \capitalizeSentence($description);
        }
        if ($address !== null) {
            $draft->address->address = $address;
        }
        if ($lat !== null) {
            $draft->address->lat = $lat;
        }
        if ($lng !== null) {
            $draft->address->lng = $lng;
        }
        if ($datetime !== null) {
            try {
                $draft->date = (new \DateTime($datetime))->format(\DT_FORMAT);
            } catch (\Throwable $e) {
                throw new \Mcp\Exception\ToolCallException(
                    "Invalid datetime '$datetime' — use ISO 8601, e.g. 2026-01-08T14:30:00."
                );
            }
        }
        \app\save($draft);

        if ($images) {
            // Reuse the web upload pipeline (resize, thumbnail, and plate
            // recognition for carImage) so MCP-supplied photos are processed
            // identically to the web.
            foreach ($images as $pictureType => $bytes) {
                \uploadImage($draft->id, $pictureType, $bytes, $draft->date ?? null, false, null);
            }
            $draft = \app\get($draft->id);
            // carImage's plate recognition resets carInfo; re-apply the caller's
            // explicit plate so it wins over ALPR (preserving the other
            // recognised fields).
            if ($plateId !== null && isset($images['carImage'])) {
                if (!isset($draft->carInfo)) {
                    $draft->carInfo = new \stdClass();
                }
                $draft->carInfo->plateId = strtoupper(\cleanWhiteChars($plateId));
                \app\save($draft);
            }
        }

        return [
            'report' => json_decode(json_encode($draft), true) ?? [],
            'editUrl' => \BASE_URL . 'app/new?edit=' . $draft->id,
        ];
    }

    // Matches SessionApiHandler::MAX_IMAGE_UPLOAD_BYTES (the web upload cap).
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** Decode a base64 image data URI to raw bytes, or fail with a readable error. */
    private static function decodeImageDataUri(string $dataUri): string {
        if (!preg_match('#^data:image/[a-z.+-]+;base64,#i', $dataUri, $matches)) {
            throw new \Mcp\Exception\ToolCallException(
                'image must be a base64 data URI, e.g. "data:image/jpeg;base64,...".'
            );
        }
        $bytes = base64_decode(substr($dataUri, strlen($matches[0])), true);
        if ($bytes === false || $bytes === '') {
            throw new \Mcp\Exception\ToolCallException('image is not valid base64 data.');
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new \Mcp\Exception\ToolCallException('image is too large (max 2 MB).');
        }
        // Only JPEG/PNG are handled by the upload pipeline. Validate here (before
        // any draft is created) so an unsupported type is a readable tool error
        // rather than an orphaned draft + opaque internal error later.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
            throw new \Mcp\Exception\ToolCallException('image must be a JPEG or PNG.');
        }
        return $bytes;
    }
}
