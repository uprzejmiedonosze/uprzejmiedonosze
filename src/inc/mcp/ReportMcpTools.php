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
        return ['reports' => json_decode(json_encode($apps), true) ?? []];
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

        return json_decode(json_encode($application), true) ?? [];
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

        return json_decode(json_encode($application), true) ?? [];
    }
}
