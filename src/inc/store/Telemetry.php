<?php namespace telemetry;

use PDO;

function db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new \PDO('sqlite:' . ROOT . 'db/telemetry.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode = WAL;");
        $db->exec("PRAGMA synchronous = OFF;");
    }
    return $db;
}

/**
 * Logs a telemetry event.
 * user_id is hashed using SHA256 of the user's email.
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function log(string $eventName, ?string $appId = null, array $data = []): void {
    try {
        $email = $_SESSION['user_email'] ?? null;
        $userId = $email ? hash('sha256', $email) : null;
        $sessionId = session_id();

        $stmt = db()->prepare("INSERT INTO events (event_name, user_id, session_id, app_id, data) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $eventName,
            $userId,
            $sessionId,
            $appId,
            $data ? json_encode($data) : null
        ]);
    } catch (\Exception $e) {
        // We don't want telemetry to break the app
        if (function_exists('logger')) {
            logger("Telemetry error: " . $e->getMessage());
        } else {
            error_log("Telemetry error: " . $e->getMessage());
        }
    }
}
