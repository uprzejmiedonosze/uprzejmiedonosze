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

        // A fresh draft's address starts as an empty object; keep it object-shaped
        // in the output (an empty PHP array would re-encode as a list `[]`, which
        // is the inconsistent shape the first MCP release shipped).
        if (empty($report['address'])) {
            $report['address'] = new \stdClass();
        }

        // The notes tool accepts caseNumber/privateNote (set_report_notes) but the
        // report stores them as externalId/privateComment. Mirror the public names
        // here (when non-empty) so reads round-trip with writes; the internal keys
        // stay for backwards compatibility.
        foreach (['externalId' => 'caseNumber', 'privateComment' => 'privateNote'] as $internal => $public) {
            if (isset($report[$internal]) && $report[$internal] !== '') {
                $report[$public] = $report[$internal];
            }
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
     * Category ids in ascending numeric order — the canonical order for the
     * category enum, its description, and list_categories.
     *
     * @param array<int|string, \Category> $categories
     * @return list<string>
     */
    public static function sortedCategoryIds(array $categories): array {
        $ids = array_keys($categories);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * The extensions the web editor actually offers — entries from
     * extensions.json with `disabled` unset/false, keyed by id.
     *
     * @param array<string, \Extension> $extensions
     * @return array<string, string> id => extension title
     */
    public static function allowedExtensions(array $extensions): array {
        $allowed = [];
        foreach ($extensions as $id => $ext) {
            if (!($ext->disabled ?? false)) {
                $allowed[$id] = $ext->title ?? '';
            }
        }
        ksort($allowed, SORT_NUMERIC);
        return $allowed;
    }

    /**
     * "11 — …, 25 — …" list of allowed extensions for schema descriptions
     * and error messages.
     *
     * @param array<string, string> $allowed id => title
     */
    public static function extensionNameList(array $allowed): string {
        $parts = [];
        foreach ($allowed as $id => $title) {
            $parts[] = "$id — $title";
        }
        return implode(', ', $parts);
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
        // Same ascending-id order as the create_report_draft category enum.
        foreach (self::sortedCategoryIds($CATEGORIES ?? []) as $id) {
            $categories[] = self::categorySummary((int) $id, $CATEGORIES[$id]);
        }

        return ['categories' => $categories];
    }

    /**
     * Create a new DRAFT report for the signed-in user, pre-filled from whatever
     * the caller can supply. The draft stays in the 'draft' status: a human must
     * open the returned editUrl to review it and send — MCP cannot send a report.
     * Up to three optional images (base64 data URIs) are run through the same
     * processing pipeline as the web upload.
     *
     * Location mirrors the web form: coordinates are the source of truth. When
     * lat/lng are supplied (or read from the car photo's EXIF GPS), the address
     * is reverse-geocoded via Nominatim (same endpoint the web uses) to fill the
     * structured fields and resolve the recipient unit; a caller-supplied address
     * string is kept as the display address. A bare address string without
     * coordinates is stored as-is (the web never forward-geocodes).
     *
     * @param int|null    $category    Violation category id (see list_categories).
     * @param int[]|null  $extensions  Additional category ids stacked on the primary category.
     * @param bool|null   $witness     Whether the reporter witnessed the moment of parking.
     * @param string|null $destination "police" or "sm" — the authority the draft is
     *                                 addressed to; defaults to the user's saved preference.
     * @param string|null $plateId     Licence plate.
     * @param string|null $description Free-text description of the violation.
     * @param string|null $address     Display address of the violation.
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
        ?array $extensions = null,
        ?bool $witness = null,
        ?string $destination = null,
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

        global $CATEGORIES, $EXTENSIONS;
        if ($category !== null && !isset($CATEGORIES[$category])) {
            throw new \Mcp\Exception\ToolCallException(
                "Unknown category id $category — call list_categories for valid ids."
            );
        }
        if ($extensions !== null) {
            // Mirror the web editor, which only offers the extensions from
            // extensions.json (disabled ones are hidden): no arbitrary category
            // ids, no duplicates, no stacking the primary category on itself.
            $allowed = self::allowedExtensions($EXTENSIONS ?? []);
            $seen = [];
            foreach ($extensions as $extId) {
                if (!isset($allowed[$extId])) {
                    throw new \Mcp\Exception\ToolCallException(
                        "Unknown extension category id $extId — valid extensions: "
                        . self::extensionNameList($allowed) . '.'
                    );
                }
                if ($category !== null && (int)$extId === $category) {
                    throw new \Mcp\Exception\ToolCallException(
                        "Extension id $extId is the report's primary category — pick another extension."
                    );
                }
                if (in_array((int)$extId, $seen, true)) {
                    throw new \Mcp\Exception\ToolCallException("Duplicate extension id $extId.");
                }
                $seen[] = (int)$extId;
            }
        }
        if ($destination !== null && !in_array($destination, ['police', 'sm'], true)) {
            throw new \Mcp\Exception\ToolCallException(
                "Invalid destination '$destination' — use 'police' or 'sm'."
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
        if ($extensions !== null) {
            // int-cast like the web flow (API::updateApplication) so "11" and 11 agree.
            $draft->extensions = array_map('intval', $extensions);
        }
        if ($witness !== null) {
            $draft->initStatements();
            $draft->statements->witness = $witness;
        }
        if ($destination !== null) {
            // Mirror the web editor's SM/Policja radio: "police" = stopAgresji.
            $draft->stopAgresji = ($destination === 'police');
        }
        // Whether the SM/Policja choice was forced by the category (see below);
        // always present in the serialised draft, like API::updateApplication.
        $draft->stopAgresjiForced = false;
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

        // ── Location, mirroring the web form ──────────────────────────────────
        // The web derives coordinates from the map click or the photo's EXIF GPS
        // and only then reverse-geocodes. Do the same here; geocoding failure is
        // non-fatal (the caller's data alone is kept, like the web's geo fallback).
        $geocoded = null;
        if (($lat === null || $lng === null) && isset($images['carImage'])) {
            $gps = \geo\exifGps($images['carImage']);
            if ($gps !== null) {
                $lat ??= $gps[0];
                $lng ??= $gps[1];
                $draft->address->lat = $lat;
                $draft->address->lng = $lng;
            }
        }
        if ($lat !== null && $lng !== null) {
            $nominatim = $this->reverseGeocode($lat, $lng);
            if ($nominatim !== null) {
                $geocoded = $nominatim;
                $addr = $nominatim['address'] ?? [];
                foreach (['city', 'voivodeship', 'postcode', 'county', 'municipality', 'district'] as $field) {
                    if (!empty($addr[$field])) {
                        $draft->address->{$field} = $addr[$field];
                    }
                }
                // The caller's string (if any) stays the display address; the
                // geocoded string is kept separately — mirrors the web's
                // lokalizacja vs addressGPS split (ApplicationHandler::confirm).
                if (empty($draft->address->address) && !empty($addr['address'])) {
                    $draft->address->address = $addr['address'];
                }
                if (!empty($addr['address'])) {
                    $draft->address->addressGPS = $addr['address'];
                }
            }
        }

        // Resolve the recipient authority like the web does at confirm time.
        // Needs a structured address (city etc.), so only after geocoding.
        $resolvedUnit = null;
        if (!empty($draft->address->city)) {
            $resolvedUnit = $draft->guessSMData(true); // stores smCity
            // stopAgresjiOnly categories force the report to the police, exactly
            // like API::updateApplication (the editor disables the SM radio).
            if ($category !== null && $CATEGORIES[$category]->isStopAgresjiOnly() && !$resolvedUnit->isPolice()) {
                $draft->stopAgresji = true;
                $draft->stopAgresjiForced = true;
                $resolvedUnit = $draft->guessSMData(true);
            }
        }

        \app\save($draft);

        if ($images) {
            // Reuse the web upload pipeline (resize, thumbnail, and plate
            // recognition for carImage) so MCP-supplied photos are processed
            // identically to the web.
            foreach ($images as $pictureType => $bytes) {
                // Pass the MCP identity so ALPR provider selection (premium/
                // patron routing) matches the web; \user\current() would see a
                // sessionless guest cached at module load.
                \uploadImage($draft->id, $pictureType, $bytes, $draft->date ?? null, false, null, null, $user);
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

        $report = $this->enrich($draft);
        $report['destination'] = $draft->stopAgresji() ? 'police' : 'sm';
        // Only advertise a recipient once a real unit was resolved; an unknown
        // unit means the draft needs a proper address before it can be routed.
        $sm = $draft->guessSMData();
        if ($sm->unknown()) {
            unset($report['recipientInfo']);
        }
        if ($geocoded !== null) {
            // Both radio options the web editor shows, pre-resolved.
            $report['destinationOptions'] = self::destinationOptions($geocoded);
        }

        return [
            'report' => $report,
            'editUrl' => \BASE_URL . 'app/new?edit=' . $draft->id,
        ];
    }

    // Matches SessionApiHandler::MAX_IMAGE_UPLOAD_BYTES (the web upload cap).
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /**
     * Reverse geocoder override for tests (Nominatim is a plain function and
     * cannot be stubbed). Defaults to \geo\Nominatim — the same endpoint the
     * web form calls. Returns the Nominatim response shape:
     * ['address' => [...], 'sm' => \SM, 'sa' => \SM], or null on failure.
     *
     * @var callable(float,float): array|null
     */
    private static $reverseGeocoder = null;

    public static function setReverseGeocoder(?callable $geocoder): void {
        self::$reverseGeocoder = $geocoder;
    }

    private function reverseGeocode(float $lat, float $lng): ?array {
        try {
            $result = self::$reverseGeocoder !== null
                ? call_user_func(self::$reverseGeocoder, $lat, $lng)
                : \geo\Nominatim($lat, $lng);
            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            // Non-fatal: keep whatever the caller supplied; the web's geo
            // endpoints also degrade without blocking the report.
            logger("MCP create_report_draft: geocoding failed for $lat,$lng: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Both recipient options the web editor shows (SM and Policja radios),
     * pre-resolved from the geocoded address.
     */
    private static function destinationOptions(array $nominatim): array {
        $summarize = function (?\SM $unit): ?array {
            if (!$unit || $unit->unknown()) {
                return null;
            }
            return [
                'name' => $unit->getName(),
                'address' => $unit->getAddress(),
                'email' => $unit->getEmail(),
                'isPolice' => $unit->isPolice(),
            ];
        };
        return [
            'sm' => $summarize($nominatim['sm'] ?? null),
            'police' => $summarize($nominatim['sa'] ?? null),
        ];
    }

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
