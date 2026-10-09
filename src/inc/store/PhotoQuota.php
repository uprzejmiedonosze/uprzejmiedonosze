<?PHP namespace quota;

use cache\Type;
use user\User;

/**
 * Limit UNIKALNYCH zdjęć przetworzonych przez płatnych dostawców (ALPR, LLM) na użytkownika w kroczącym oknie
 * PHOTO_QUOTA_DAYS. Liczy się zdjęcie (sha1 bajtów), nie zgłoszenie – to samo zdjęcie wrzucone ponownie (także z appki
 * i z MCP naprzemiennie) zużywa limit raz, a zdjęcie, którego wynik leży już w cache ALPR, nic nie kosztuje.
 * Dotyczy appki Pro (REST: etap `wip`, vision/candidate) i MCP (create_report_draft); web bez zmian.
 */

const TABLE = 'photo_usage';

final class QuotaExceededException extends \RuntimeException {
    /** @param array<string,mixed> $status wynik status() */
    public function __construct(public readonly array $status) {
        $resets = $status['resetsAt'] ? ', następne miejsce zwolni się ' . date('Y-m-d', $status['resetsAt']) : '';
        parent::__construct(
            "Wykorzystano limit {$status['limit']} przetworzonych zdjęć w ostatnich {$status['windowDays']} dniach$resets. "
            . 'Limit rośnie z patronatem: https://patronite.pl/uprzejmiedonosze',
            402
        );
    }
}

/** Limit dla użytkownika; null = bez limitu. @return array{limit:?int,tier:string} */
function tierFor(User $user): array {
    $patron = \patronite\get()[$user->getEmail()] ?? null;
    $amount = ($patron && ($patron['active'] ?? false)) ? (float)($patron['amount'] ?? 0) : 0.0;
    $tiers = PHOTO_QUOTA_TIERS;
    krsort($tiers); // od najwyższego progu
    foreach ($tiers as $min => $limit) {
        if ($amount >= $min) return ['limit' => $limit, 'tier' => "patron-$min"];
    }
    return ['limit' => PHOTO_QUOTA_FREE, 'tier' => $amount > 0 ? 'patron-below-min' : 'free'];
}

function windowStart(): int {
    return time() - PHOTO_QUOTA_DAYS * 86400;
}

/** @return array{used:int,limit:?int,remaining:?int,windowDays:int,resetsAt:?int,tier:string} */
function status(User $user): array {
    $stmt = \store\prepare('SELECT COUNT(*), MIN(created_at) FROM ' . TABLE . ' WHERE user_email = :e AND created_at > :s');
    $stmt->execute([':e' => $user->getEmail(), ':s' => windowStart()]);
    [$used, $oldest] = $stmt->fetch(\PDO::FETCH_NUM);
    ['limit' => $limit, 'tier' => $tier] = tierFor($user);
    return [
        'used' => (int)$used,
        'limit' => $limit,
        'remaining' => $limit === null ? null : max(0, $limit - (int)$used),
        'windowDays' => PHOTO_QUOTA_DAYS,
        'resetsAt' => $oldest ? (int)$oldest + PHOTO_QUOTA_DAYS * 86400 : null,
        'tier' => $tier,
    ];
}

/**
 * Rezerwuje miejsce na zdjęcie PRZED wywołaniem dostawcy (równoległe żądania nie przekroczą limitu).
 * @return bool true = naliczono (wołający zwraca przez release() przy błędzie dostawcy), false = darmowe
 *              (cache ALPR albo to samo zdjęcie już naliczone w oknie)
 * @throws QuotaExceededException
 */
function reserve(User $user, string $imageBytes): bool {
    $sha1 = sha1($imageBytes);
    // wynik ALPR już w cache (te same klucze co alpr\get_platerecognizer / _use_openAlpr) → przetworzenie nic nie kosztuje
    if (\cache\get(Type::Platerecognizer, $sha1) || \cache\get(Type::OpenAlpr, $sha1)) return false;

    $email = $user->getEmail();
    $stmt = \store\prepare('SELECT 1 FROM ' . TABLE . ' WHERE user_email = :e AND sha1 = :h AND created_at > :s');
    $stmt->execute([':e' => $email, ':h' => $sha1, ':s' => windowStart()]);
    if ($stmt->fetchColumn()) return false;

    \store\prepare('INSERT OR REPLACE INTO ' . TABLE . ' (user_email, sha1, created_at) VALUES (:e, :h, :t)')
        ->execute([':e' => $email, ':h' => $sha1, ':t' => time()]);

    $status = status($user);
    if ($status['limit'] !== null && $status['used'] > $status['limit']) {
        release($user, $imageBytes);
        \telemetry\log('photo_quota_exceeded', null, ['status' => 'error']);
        throw new QuotaExceededException(status($user));
    }
    return true;
}

/** Zwrot miejsca po błędzie dostawcy – awaria ALPR nie zjada limitu. */
function release(User $user, string $imageBytes): void {
    \store\prepare('DELETE FROM ' . TABLE . ' WHERE user_email = :e AND sha1 = :h')
        ->execute([':e' => $user->getEmail(), ':h' => sha1($imageBytes)]);
}

/** Kasuje wpisy spoza okna (cron: tools/cleanup.php). Zwraca liczbę usuniętych. */
function purge(): int {
    $stmt = \store\prepare('DELETE FROM ' . TABLE . ' WHERE created_at <= :s');
    $stmt->execute([':s' => windowStart()]);
    return $stmt->rowCount();
}
