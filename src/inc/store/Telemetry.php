<?php namespace telemetry;


/**
 * Logs a telemetry event directly to Netdata (StatsD).
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function log(string $eventName, ?string $appId = null, array $data = []): void {
    try {
        // Netdata wbudowany serwer StatsD domyślnie nasłuchuje na 127.0.0.1:8125 UDP
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            return;
        }

        // Ustandaryzowana nazwa metryki, np. uprzejmiedonosze.visitor_entry
        $metricName = "uprzejmiedonosze." . str_replace('-', '_', $eventName);
        
        // Zliczanie podstawowych eventów
        $message = "{$metricName}:1|c";
        socket_sendto($sock, $message, strlen($message), 0, '127.0.0.1', 8125);

        // Zliczanie statusów (np. delivery_status.failed)
        if (isset($data['status'])) {
            $statusMetric = $metricName . "." . $data['status'];
            $msgStatus = "{$statusMetric}:1|c";
            socket_sendto($sock, $msgStatus, strlen($msgStatus), 0, '127.0.0.1', 8125);
        }

        // Zliczanie źródeł błędów (np. app_error.API_saveImgAndThumb)
        if ($eventName === 'app_error' && isset($data['source'])) {
            $sourceMetric = $metricName . "." . str_replace([':', '\\', ' '], '_', $data['source']);
            $msgSource = "{$sourceMetric}:1|c";
            socket_sendto($sock, $msgSource, strlen($msgSource), 0, '127.0.0.1', 8125);
        }

        socket_close($sock);

    } catch (\Exception $e) {
        // We don't want telemetry to break the app
        if (function_exists('logger')) {
            logger("Telemetry error: " . $e->getMessage());
        } else {
            error_log("Telemetry error: " . $e->getMessage());
        }
    }
}
