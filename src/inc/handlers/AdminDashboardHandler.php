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
        $funnel = [];
        foreach ($funnelEvents as $event) {
            $stmt = $db->prepare("SELECT COUNT(DISTINCT session_id) FROM events WHERE event_name = ?");
            $stmt->execute([$event]);
            $funnel[$event] = (int)$stmt->fetchColumn();
        }

        // 2. Daily reports (last 30 days)
        $dailyReports = $db->query("
            SELECT substr(timestamp, 1, 10) as day, COUNT(*) as cnt
            FROM events
            WHERE event_name = 'report_sent'
            AND timestamp >= date('now', '-30 days')
            GROUP BY day ORDER BY day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 3. Delivery status distribution
        $deliveryStats = $db->query("
            SELECT json_extract(data, '$.status') as status, COUNT(*) as cnt
            FROM events
            WHERE event_name = 'delivery_status'
            AND json_extract(data, '$.is_cc') = 0
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 4. Top delivery errors
        $deliveryErrors = $db->query("
            SELECT json_extract(data, '$.reason') as reason, COUNT(*) as cnt
            FROM events
            WHERE event_name = 'delivery_status'
            AND json_extract(data, '$.status') IN ('failed', 'problem')
            GROUP BY reason ORDER BY cnt DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        return AbstractHandler::renderHtml($request, $response, 'dashboard', [

            'title' => 'Admin Dashboard',
            'funnel' => $funnel,
            'dailyReports' => $dailyReports,
            'deliveryStats' => $deliveryStats,
            'deliveryErrors' => $deliveryErrors
        ]);
    }
}
