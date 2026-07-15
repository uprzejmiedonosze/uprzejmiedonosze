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
     * @return array List of report summaries, newest first.
     */
    public function listReports(string $status = 'all', int $limit = 50): array {
        McpIdentity::requireScope('reports:read');
        $user = McpIdentity::currentUser();
        $apps = \user\apps($user, $status, '%', $limit, 0);
        // Normalise the domain objects to plain arrays for structured MCP output.
        return json_decode(json_encode($apps), true) ?? [];
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
        $application = \app\get($reportId);

        // Ownership is by email throughout the app (see src/inc/API.php).
        if (!$application || $application->email !== $user->getEmail()) {
            throw new \RuntimeException("Report '$reportId' not found");
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

        $application = \app\get($reportId);
        if (!$application || $application->email !== $user->getEmail()) {
            throw new \RuntimeException("Report '$reportId' not found");
        }

        try {
            $application = \setStatus($status->value, $reportId, $user);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return json_decode(json_encode($application), true) ?? [];
    }
}
