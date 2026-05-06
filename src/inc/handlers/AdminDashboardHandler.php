<?php

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

class AdminDashboardHandler extends AbstractHandler {

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function dashboard(Request $request, Response $response): Response {
        $db = \telemetry\db();
// 1. Funnel data (Total unique sessions per event type)
$funnelEvents = ['visitor_entry', 'user_login', 'report_started', 'report_finished', 'report_sent'];
$funnelLabels = [
    'visitor_entry' => 'Visitor entry',
    'user_login' => 'User login',
    'report_started' => 'App started',
    'report_finished' => 'App finished',
    'report_sent' => 'App sent'
];
$funnel = [];
foreach ($funnelEvents as $event) {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT session_id) FROM events WHERE event_name = ?");
    $stmt->execute([$event]);
    $funnel[$funnelLabels[$event]] = (int)$stmt->fetchColumn();
}

// 2. Daily apps (last 30 days) - based on report_sent, NOT delivery_status
$dailyReports = $db->query("
    SELECT substr(timestamp, 1, 10) as day, COUNT(*) as cnt 
    FROM events 
    WHERE event_name = 'report_sent' 
    AND timestamp >= date('now', '-30 days')
    GROUP BY day ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Delivery status distribution (Only failed/problem statuses for donut?) 
// Or all statuses, but make sure they exist.
$deliveryStats = $db->query("
    SELECT json_extract(data, '$.status') as status, COUNT(*) as cnt 
    FROM events 
    WHERE event_name = 'delivery_status'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Top delivery errors (Only REAL errors, not accepted/delivered messages)
$deliveryErrors = $db->query("
    SELECT json_extract(data, '$.reason') as reason, COUNT(*) as cnt 
    FROM events 
    WHERE event_name = 'delivery_status' 
    AND json_extract(data, '$.status') IN ('failed', 'problem')
    GROUP BY reason ORDER BY cnt DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);


        // 5. Top application errors
        $appErrors = $db->query("
            SELECT json_extract(data, '$.msg') as msg, COUNT(*) as cnt 
            FROM events 
            WHERE event_name = 'app_error' 
            GROUP BY msg ORDER BY cnt DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        return AbstractHandler::renderHtml($request, $response, 'dashboard', [
            'title' => 'Admin Dashboard',
            'funnel' => $funnel,
            'dailyReports' => $dailyReports,
            'deliveryStats' => $deliveryStats,
            'deliveryErrors' => $deliveryErrors,
            'appErrors' => $appErrors
        ]);

    }
}
