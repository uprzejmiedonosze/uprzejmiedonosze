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

    // Output schema: `status` is an enum of every known status (from
    // statuses.json); other fields pass through via additionalProperties.
    // Per-status meaning + transitions are in the server instructions, not here.
    global $STATUSES, $CATEGORIES;
    $reportSchema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => array_keys($STATUSES ?? []),
                'description' => 'Current report status id; see the status legend in the '
                    . 'server instructions for what each id means and its allowed transitions.',
            ],
            // This report's category, expanded in the returned data by enrich().
            'categoryInfo' => [
                'type' => 'object',
                'description' => 'The violation category: title, formal wording, legal '
                    . 'basis (law), fine amount in PLN and demerit points.',
            ],
            // The recipient authority, resolved from smCity by enrich().
            'recipientInfo' => [
                'type' => 'object',
                'description' => 'The authority the report is addressed to (resolved from '
                    . 'smCity): name, address, email, and whether it is police.',
            ],
        ],
        'additionalProperties' => true,
    ];
    $listSchema = [
        'type' => 'object',
        'properties' => ['reports' => ['type' => 'array', 'items' => $reportSchema]],
        'additionalProperties' => true,
    ];

    // Input filter for list_reports: the two special values plus any status.
    $listInputSchema = [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => array_merge(['all', 'allWithDrafts'], array_keys($STATUSES ?? [])),
                'default' => 'all',
                'description' => 'Which reports to return: "all" (excludes drafts), '
                    . '"allWithDrafts", or a specific status id.',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 50,
                'description' => 'Maximum number of reports to return.',
            ],
        ],
        'additionalProperties' => false,
    ];

    // Full status legend (id — label — meaning; allowed transitions) for the
    // server instructions, and a recordable-outcomes list for the update tool.
    // Both derive from statuses.json so they stay in sync with the domain.
    $recordable = array_column(ReportStatus::cases(), 'value');
    $meanings = array_map(function ($id) use ($STATUSES) {
        $status = $STATUSES[$id] ?? null;
        $label = $status?->name ?? $id;
        $meaning = $status?->action ?? ($status?->comment ?? '');
        return "$id — $label" . ($meaning !== '' ? " ($meaning)" : '');
    }, $recordable);
    $statusLegend = implode("\n", array_map(function ($id, $status) {
        $label = $status->name ?? $id;
        $meaning = $status->action ?? ($status->comment ?? '');
        $next = implode(', ', array_values($status->allowed ?? []));
        return "- $id — $label" . ($meaning !== '' ? " ($meaning)" : '')
            . ($next !== '' ? "; may become: $next" : '');
    }, array_keys($STATUSES ?? []), array_values($STATUSES ?? [])));
    $statusDescription = 'The outcome to record. A report can only move to certain statuses '
        . 'from its current one (see the status legend in the server instructions); an invalid '
        . 'transition is rejected with an explanatory error. '
        . 'Values: ' . implode('; ', $meanings) . '.';

    // Input for update_report_status. additionalProperties:false so unknown
    // fields (e.g. a `comment`/`note` the client hoped to persist) are rejected
    // rather than silently accepted and dropped — this tool only sets status.
    $updateInputSchema = [
        'type' => 'object',
        'properties' => [
            'reportId' => [
                'type' => 'string',
                'description' => 'The report id.',
            ],
            'status' => [
                'type' => 'string',
                'enum' => $recordable,
                'description' => $statusDescription,
            ],
        ],
        'required' => ['reportId', 'status'],
        'additionalProperties' => false,
    ];

    // Input for set_report_notes. Both annotations are private to the user and
    // never sent to the authorities; at least one must be provided.
    $notesInputSchema = [
        'type' => 'object',
        'properties' => [
            'reportId' => [
                'type' => 'string',
                'description' => 'The report id.',
            ],
            'caseNumber' => [
                'type' => 'string',
                'description' => 'Authority (city guard / police) case number, e.g. "RSOW 123/24". '
                    . 'Private to the user; not sent to anyone.',
            ],
            'privateNote' => [
                'type' => 'string',
                'description' => 'A free-text private note. Private to the user; not sent to anyone.',
            ],
        ],
        'required' => ['reportId'],
        'additionalProperties' => false,
    ];

    $categoriesOutputSchema = [
        'type' => 'object',
        'properties' => ['categories' => ['type' => 'array', 'items' => ['type' => 'object']]],
        'additionalProperties' => true,
    ];

    // Input for create_report_draft — every field optional (an empty draft is
    // valid); the human completes it via the returned editUrl.
    $createInputSchema = [
        'type' => 'object',
        'properties' => [
            'category' => [
                'type' => 'integer',
                // Valid ids come from categories.json (data, not code), so the
                // enum is generated dynamically — no static list to drift.
                'enum' => array_keys($CATEGORIES ?? []),
                'description' => 'Violation category id (see list_categories).',
            ],
            'plateId' => ['type' => 'string', 'description' => 'Licence plate, e.g. "ZS1234A".'],
            'description' => ['type' => 'string', 'description' => 'Free-text description of the violation.'],
            'address' => ['type' => 'string', 'description' => 'Street address where it happened.'],
            'lat' => ['type' => 'number', 'description' => 'Latitude.'],
            'lng' => ['type' => 'number', 'description' => 'Longitude.'],
            'datetime' => ['type' => 'string', 'description' => 'When it happened, ISO 8601 local time (e.g. 2026-01-08T14:30:00); any timezone offset is ignored.'],
            'carImage' => ['type' => 'string', 'description' => 'Optional vehicle/plate photo as a base64 data URI (JPEG/PNG, ≤ 2 MB); runs plate recognition.'],
            'contextImage' => ['type' => 'string', 'description' => 'Optional wider-scene photo as a base64 data URI (JPEG/PNG, ≤ 2 MB).'],
            'thirdImage' => ['type' => 'string', 'description' => 'Optional third photo as a base64 data URI (JPEG/PNG, ≤ 2 MB).'],
        ],
        'additionalProperties' => false,
    ];
    $createOutputSchema = [
        'type' => 'object',
        'properties' => [
            'report' => ['type' => 'object'],
            'editUrl' => [
                'type' => 'string',
                'description' => 'Open this to add the required photos and send the report.',
            ],
        ],
        'additionalProperties' => true,
    ];

    return Server::builder()
        ->setServerInfo(
            'Uprzejmie Donoszę',
            '0.1.0',
            'Odczyt Twoich zgłoszeń w serwisie Uprzejmie Donoszę.'
        )
        ->setInstructions(
            'Browse the signed-in user\'s reports (zgłoszenia) with list_reports, '
            . 'fetch one with get_report, record the authority\'s response with '
            . 'update_report_status, and save a private case number or note with '
            . 'set_report_notes. Use list_categories to see the violation types, and '
            . 'create_report_draft to start a new report — it creates a draft the user must '
            . 'open (editUrl) to add photos and send; the server cannot send reports itself. '
            . 'Each report has a `status` id (see the legend below) and a categoryInfo object '
            . '(the violation type, its formal wording and legal basis). Use the Polish status '
            . 'labels when talking to the user, and only set a status the current one is '
            . 'allowed to move to.'
            . "\n\nStatus legend (id — label — meaning; allowed transitions):\n" . $statusLegend
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
            ),
            inputSchema: $listInputSchema,
            outputSchema: $listSchema
        )
        ->addTool(
            [$tools, 'getReport'],
            'get_report',
            description: 'Fetch one of the signed-in user\'s reports by its id.',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                idempotentHint: true,
                openWorldHint: false
            ),
            outputSchema: $reportSchema
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
            ),
            inputSchema: $updateInputSchema,
            outputSchema: $reportSchema
        )
        ->addTool(
            [$tools, 'setReportNotes'],
            'set_report_notes',
            description: 'Set private annotations on one of the signed-in user\'s reports: the '
                . 'authority case number and/or a free-text note. Both are private to the user '
                . 'and never sent to anyone. Requires the reports:notes:write scope.',
            annotations: new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false
            ),
            inputSchema: $notesInputSchema,
            outputSchema: $reportSchema
        )
        ->addTool(
            [$tools, 'listCategories'],
            'list_categories',
            description: 'List the violation categories (id, title, formal wording, legal basis, '
                . 'fine, demerit points) — pick one for create_report_draft or to interpret a '
                . 'report\'s category number.',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                idempotentHint: true,
                openWorldHint: false
            ),
            outputSchema: $categoriesOutputSchema
        )
        ->addTool(
            [$tools, 'createReportDraft'],
            'create_report_draft',
            description: 'Create a new DRAFT report for the signed-in user, pre-filled from the '
                . 'given fields. The draft is NOT sent: a human must open the returned editUrl to '
                . 'add the required photos and send it. Requires the reports:create scope.',
            annotations: new ToolAnnotations(
                readOnlyHint: false,
                destructiveHint: false,
                idempotentHint: false,
                // A car image is run through plate recognition, which calls an
                // external ALPR service — an open-world interaction.
                openWorldHint: true
            ),
            inputSchema: $createInputSchema,
            outputSchema: $createOutputSchema
        )
        ->build();
}
