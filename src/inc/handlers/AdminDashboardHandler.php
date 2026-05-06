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

        // 2. Daily stats (last 30 days) for dual-axis chart
        $dailyStats = $db->query("
            WITH days_series AS (
                SELECT date('now', '-' || n || ' days') as day
                FROM days
            ),
            entries AS (
                SELECT substr(timestamp, 1, 10) as day, COUNT(DISTINCT session_id) as cnt
                FROM events WHERE event_name = 'visitor_entry' AND timestamp >= date('now', '-30 days')
                GROUP BY day
            ),
            started AS (
                SELECT substr(timestamp, 1, 10) as day, COUNT(*) as cnt
                FROM events WHERE event_name = 'report_started' AND timestamp >= date('now', '-30 days')
                GROUP BY day
            ),
            sent AS (
                SELECT substr(timestamp, 1, 10) as day, COUNT(*) as cnt
                FROM events WHERE event_name = 'report_sent' AND timestamp >= date('now', '-30 days')
                GROUP BY day
            )
            SELECT 
                d.day, 
                IFNULL(e.cnt, 0) as entries,
                IFNULL(st.cnt, 0) as started,
                IFNULL(sn.cnt, 0) as sent
            FROM days_series d
            LEFT JOIN entries e ON d.day = e.day
            LEFT JOIN started st ON d.day = st.day
            LEFT JOIN sent sn ON d.day = sn.day
            ORDER BY d.day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 3. Hourly stats (last 24 hours)
        $hourlyStats = $db->query("
            WITH hours_series AS (
                SELECT strftime('%Y-%m-%d %H:00:00', datetime('now', '-' || n || ' hours')) as hour
                FROM hours
            ),
            entries AS (
                SELECT strftime('%Y-%m-%d %H:00:00', timestamp) as hour, COUNT(DISTINCT session_id) as cnt
                FROM events WHERE event_name = 'visitor_entry' AND timestamp >= datetime('now', '-24 hours')
                GROUP BY hour
            ),
            started AS (
                SELECT strftime('%Y-%m-%d %H:00:00', timestamp) as hour, COUNT(*) as cnt
                FROM events WHERE event_name = 'report_started' AND timestamp >= datetime('now', '-24 hours')
                GROUP BY hour
            ),
            sent AS (
                SELECT strftime('%Y-%m-%d %H:00:00', timestamp) as hour, COUNT(*) as cnt
                FROM events WHERE event_name = 'report_sent' AND timestamp >= datetime('now', '-24 hours')
                GROUP BY hour
            )
            SELECT 
                h.hour, 
                IFNULL(e.cnt, 0) as entries,
                IFNULL(st.cnt, 0) as started,
                IFNULL(sn.cnt, 0) as sent
            FROM hours_series h
            LEFT JOIN entries e ON h.hour = e.hour
            LEFT JOIN started st ON h.hour = st.hour
            LEFT JOIN sent sn ON h.hour = sn.hour
            ORDER BY h.hour ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 4. Delivery status distribution
// Or all statuses, but make sure they exist.
$deliveryStats = $db->query("
    SELECT json_extract(data, '$.status') as status, COUNT(*) as cnt 
    FROM events 
    WHERE event_name = 'delivery_status'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Top delivery errors (Only REAL errors, not accepted/delivered messages)
$deliveryErrors = $db->query("
    SELECT json_extract(data, '$.reason') as reason, COUNT(*) as cnt 
    FROM events 
    WHERE event_name = 'delivery_status' 
    AND json_extract(data, '$.status') IN ('failed', 'problem')
    GROUP BY reason ORDER BY cnt DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);


        // 6. Top application errors
        $appErrors = $db->query("
            SELECT json_extract(data, '$.msg') as msg, COUNT(*) as cnt 
            FROM events 
            WHERE event_name = 'app_error' 
            GROUP BY msg ORDER BY cnt DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        return AbstractHandler::renderHtml($request, $response, 'dashboard', [
            'title' => 'Admin Dashboard',
            'funnel' => $funnel,
            'dailyStats' => $dailyStats,
            'hourlyStats' => $hourlyStats,
            'deliveryStats' => $deliveryStats,
            'deliveryErrors' => $deliveryErrors,
            'appErrors' => $appErrors
        ]);

    }
}
