<?php
require_once(__DIR__ . '/../inc/include.php');

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI.\n");
}

$db = \store\store();

echo "Szukam zawieszonych zgłoszeń...\n";

// Szukamy zgłoszeń w "starych" statusach, które mają "mailgun webhook error" w komentarzu lub 'sent.body'
$stmt = $db->query("SELECT key FROM applications WHERE status IN ('draft', 'ready', 'confirmed')");
$apps = $stmt->fetchAll(\PDO::FETCH_COLUMN);

$fixed = 0;
foreach ($apps as $appId) {
    \semaphore\acquire($appId, "fix-hanging-apps");
    try {
        $app = \app\get($appId);
    } catch (\Exception $e) {
        \semaphore\release($appId, "fix-hanging-apps");
        continue;
    }

    try {
        $isBroken = false;
        
        // 1. Sprawdźmy pole sent
        if (isset($app->sent->body) && strpos(strtolower($app->sent->body), 'mailgun webhook error') !== false) {
            $isBroken = true;
        }
        
        // 2. Sprawdźmy komentarze
        $lastWebhookStatus = null;
        if (isset($app->comments)) {
            foreach ($app->comments as $comment) {
                if (isset($comment->source) && $comment->source === 'mailer') {
                    $lastWebhookStatus = $comment->status ?? null; // addComment stores mailEvent status as 3rd arg
                }
                if (isset($comment->comment) && strpos(strtolower($comment->comment), 'mailgun webhook error') !== false) {
                    $isBroken = true;
                }
            }
        }
        
        if ($isBroken) {
            echo "Znaleziono zawieszone zgłoszenie: {$app->getNumber()} ($appId) w statusie '{$app->status}'.\n";
            
            // Decydujemy o nowym statusie na podstawie ostatniego zdarzenia z mailera
            if ($lastWebhookStatus === 'delivered') {
                $app->setStatus('confirmed-waiting', true);
                echo " -> Ustawiono status na 'confirmed-waiting' (dostarczono).\n";
            } elseif ($lastWebhookStatus === 'problem') {
                $app->setStatus('sending-problem', true);
                echo " -> Ustawiono status na 'sending-problem'.\n";
            } else {
                // Domyślnie failed, żeby użytkownik mógł ponowić wysyłkę
                $app->setStatus('sending-failed', true);
                unset($app->sent);
                echo " -> Ustawiono status na 'sending-failed' (niepowodzenie/brak info). Można ponowić wysyłkę.\n";
            }

            // Usunięcie fałszywych wpisów z komentarzy (zostawiamy właściwe statusy)
            if (isset($app->comments)) {
                $newComments = [];
                foreach ($app->comments as $comment) {
                    if (!isset($comment->comment) || strpos(strtolower($comment->comment), 'mailgun webhook error') === false) {
                        $newComments[] = $comment;
                    }
                }
                $app->comments = $newComments;
            }

            \app\save($app);
            $fixed++;
        }
    } finally {
        \semaphore\release($appId, "fix-hanging-apps");
    }
}

echo "Zakończono. Naprawiono $fixed zgłoszeń.\n";
