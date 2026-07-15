<?PHP

namespace mcp;

use Mcp\Schema\ToolAnnotations;
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
            'Browse the signed-in user\'s reports (zgłoszenia) with list_reports, '
            . 'fetch one with get_report, and record the authority\'s response with '
            . 'update_report_status.'
        )
        ->setSession(new McpMemcacheSessionStore())
        ->addTool(
            [$tools, 'listReports'],
            'list_reports',
            description: 'List the signed-in user\'s reports (zgłoszenia), newest first.',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                idempotentHint: true,
                openWorldHint: false
            )
        )
        ->addTool(
            [$tools, 'getReport'],
            'get_report',
            description: 'Fetch one of the signed-in user\'s reports by its id.',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                idempotentHint: true,
                openWorldHint: false
            )
        )
        ->addTool(
            [$tools, 'updateReportStatus'],
            'update_report_status',
            description: 'Update the status of one of the signed-in user\'s reports, e.g. to '
                . 'record the authority\'s response. Requires the reports:status:write scope.',
            annotations: new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false
            )
        )
        ->build();
}
