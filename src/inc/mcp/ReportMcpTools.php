<?PHP

namespace mcp;

/**
 * Read-only MCP tools over a user's own reports (zgłoszenia). They call the
 * domain/store functions directly. The authenticated user comes from
 * {@see McpIdentity}, set by the entry-point middleware before the SDK
 * dispatches the tool call.
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
}
