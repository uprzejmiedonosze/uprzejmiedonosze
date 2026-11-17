<?PHP namespace chat;

/**
 * Rozmowa z asystentem AI: jedna bieżąca konwersacja na użytkownika (klucz: e-mail), zapisana w store.sqlite.
 * „Nowa rozmowa” (\chat\archive()) nie kasuje wiadomości, tylko ustawia im archived_at — historia (i koszty w meta)
 * zostają w bazie, a odczyty poniżej widzą wyłącznie bieżącą rozmowę (archived_at IS NULL).
 * Ta sama tabela jest skrzynką powiadomień systemowych — \chat\notify() dorzuca wiadomość od systemu do rozmowy,
 * a asystent widzi ją w kontekście, więc użytkownik może dopytać „co to znaczy?”. Schemat: src/sql/base_schema.sql.
 */

const TABLE = 'chat_messages';
const ROLES = ['user', 'assistant', 'notification'];

/**
 * Dopisuje wiadomość do rozmowy. Powiadomienia startują jako nieprzeczytane; reszta jest „przeczytana”
 * od razu (użytkownik właśnie ją napisał albo dostał odpowiedź synchronicznie).
 * @param array<string,mixed> $meta
 * @return int id wiadomości
 */
function add(string $email, string $role, string $content, array $meta = []): int {
    if (!in_array($role, ROLES, true))
        throw new \InvalidArgumentException("Nieznana rola wiadomości: $role");
    $now = time();
    \store\prepare('INSERT INTO ' . TABLE . ' (user_email, role, content, meta, created_at, read_at)
        VALUES (:e, :r, :c, :m, :t, :read)')->execute([
        ':e' => $email,
        ':r' => $role,
        ':c' => $content,
        ':m' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ':t' => $now,
        ':read' => $role === 'notification' ? null : $now,
    ]);
    return (int)\store\store()->lastInsertId();
}

/**
 * Powiadomienie od systemu w rozmowie użytkownika (przyszłe „centrum powiadomień”).
 * $link: opcjonalna ścieżka względna lub https, pokazywana pod treścią.
 */
function notify(string $email, string $text, ?string $link = null): int {
    return add($email, 'notification', $text, $link ? ['link' => $link] : []);
}

/**
 * Ostatnie $limit wiadomości bieżącej rozmowy, od najstarszej do najnowszej. $beforeId = starsza strona (przewijanie w górę).
 * @return list<array{id:int,role:string,content:string,meta:array<string,mixed>,createdAt:int,unread:bool}>
 */
function history(string $email, int $limit = 50, ?int $beforeId = null): array {
    $stmt = \store\prepare('SELECT id, role, content, meta, created_at, read_at FROM ' . TABLE
        . ' WHERE user_email = :e AND archived_at IS NULL' . ($beforeId !== null ? ' AND id < :b' : '') . ' ORDER BY id DESC LIMIT :l');
    $stmt->bindValue(':e', $email);
    if ($beforeId !== null) $stmt->bindValue(':b', $beforeId, \PDO::PARAM_INT);
    $stmt->bindValue(':l', max(1, $limit), \PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));
    return array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'role' => $r['role'],
        'content' => $r['content'],
        'meta' => $r['meta'] ? (json_decode($r['meta'], true) ?: []) : [],
        'createdAt' => (int)$r['created_at'],
        'unread' => $r['read_at'] === null,
    ], $rows);
}

/** Liczba nieprzeczytanych powiadomień w bieżącej rozmowie (do plakietki na przycisku czatu). */
function unread(string $email): int {
    $stmt = \store\prepare('SELECT COUNT(*) FROM ' . TABLE . ' WHERE user_email = :e AND read_at IS NULL AND archived_at IS NULL');
    $stmt->execute([':e' => $email]);
    return (int)$stmt->fetchColumn();
}

function markRead(string $email): void {
    \store\prepare('UPDATE ' . TABLE . ' SET read_at = :t WHERE user_email = :e AND read_at IS NULL')
        ->execute([':t' => time(), ':e' => $email]);
}

/** Ile pytań zadał użytkownik od $since (unix ts) — limit nadużyć kosztownego wywołania LLM. Liczy też zarchiwizowane:
 * „Nowa rozmowa” nie zeruje limitu. */
function userMessagesSince(string $email, int $since): int {
    $stmt = \store\prepare('SELECT COUNT(*) FROM ' . TABLE . " WHERE user_email = :e AND role = 'user' AND created_at > :s");
    $stmt->execute([':e' => $email, ':s' => $since]);
    return (int)$stmt->fetchColumn();
}

/**
 * „Nowa rozmowa”: archiwizuje bieżącą rozmowę (asystent i widżet przestają ją widzieć, wiersze zostają w bazie).
 * Nieprzeczytane powiadomienia dostają też read_at, żeby nie wisiały jako nieprzeczytane w archiwum.
 * @return int liczba zarchiwizowanych wiadomości
 */
function archive(string $email): int {
    $now = time();
    $stmt = \store\prepare('UPDATE ' . TABLE . ' SET archived_at = :t, read_at = COALESCE(read_at, :t)
        WHERE user_email = :e AND archived_at IS NULL');
    $stmt->execute([':t' => $now, ':e' => $email]);
    return $stmt->rowCount();
}

/** Kasuje wszystkie wiadomości użytkownika, także archiwalne (usunięcie konta). @return int liczba usuniętych wiadomości */
function removeAllForEmail(string $email): int {
    $stmt = \store\prepare('DELETE FROM ' . TABLE . ' WHERE user_email = :e');
    $stmt->execute([':e' => $email]);
    return $stmt->rowCount();
}
