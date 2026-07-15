<?PHP

namespace mcp;

use Mcp\Server;

/**
 * Builds the MCP server. Tools are registered explicitly (no attribute
 * discovery) so the exposed surface is always the list below.
 */
function buildServer(): Server {
    $tools = new ReportMcpTools();

    return Server::builder()
        ->setServerInfo(
            'Uprzejmie Donoszę',
            '0.1.0',
            'Odczyt Twoich zgłoszeń w serwisie Uprzejmie Donoszę.'
        )
        ->setInstructions(
            'Use list_reports to browse the signed-in user\'s reports (zgłoszenia) '
            . 'and get_report to fetch a single report by id. Read-only.'
        )
        ->setSession(new McpMemcacheSessionStore())
        ->addTool(
            [$tools, 'listReports'],
            'list_reports',
            description: 'List the signed-in user\'s reports (zgłoszenia), newest first.'
        )
        ->addTool(
            [$tools, 'getReport'],
            'get_report',
            description: 'Fetch one of the signed-in user\'s reports by its id.'
        )
        ->addTool(
            [$tools, 'updateReportStatus'],
            'update_report_status',
            description: 'Update the status of one of the signed-in user\'s reports, e.g. to '
                . 'record the authority\'s response. Requires the reports:status:write scope.'
        )
        ->build();
}
