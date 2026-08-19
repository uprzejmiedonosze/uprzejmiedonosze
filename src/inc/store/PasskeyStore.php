<?PHP namespace passkey;

const TABLE = 'passkeys';
const USERS_TABLE = 'passkey_users';
const MAX_PER_USER = 10;

/**
 * Human labels for common authenticator AAGUIDs, used to name a passkey
 * automatically instead of asking the user to type one. Not exhaustive —
 * unknown AAGUIDs (or 0000...0000, used by some platform authenticators)
 * fall back to a "Klucz z <date>" label built by the caller.
 * @see https://github.com/passkeydeveloper/passkey-authenticator-aaguids
 */
const AAGUID_LABELS = [
    'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
    'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome (Mac)',
    '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
    '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
    '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
    'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
    'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
    'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
    'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
    'b84e4048-15dc-4dd0-8640-f4f60813c8af' => 'NordPass',
    '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
    'fbc4dfef-2ca6-4374-baf5-0be7e8dee6f1' => 'Kaspersky Password Manager',
];

/**
 * Resolves (or creates) the opaque, random handle a given email is known as
 * to authenticators. This is deliberately NOT the Firebase uid: the
 * authenticator returns it to the browser (and thus the server) on every
 * login, while the uid is the passphrase User::encode() uses to encrypt the
 * user's data — it must never round-trip through the client this way.
 */
function userHandle(string $email): string {
    $stmt = \store\prepare('SELECT user_handle FROM ' . USERS_TABLE . ' WHERE user_email = :e');
    $stmt->execute([':e' => $email]);
    $handle = $stmt->fetch(\PDO::FETCH_COLUMN);
    if ($handle) {
        return $handle;
    }

    $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $stmt = \store\prepare(
        'INSERT INTO ' . USERS_TABLE . ' (user_handle, user_email, created_at) VALUES (:h, :e, :c)'
    );
    $stmt->execute([':h' => $handle, ':e' => $email, ':c' => date('c')]);
    return $handle;
}

function emailForHandle(string $handle): ?string {
    $stmt = \store\prepare('SELECT user_email FROM ' . USERS_TABLE . ' WHERE user_handle = :h');
    $stmt->execute([':h' => $handle]);
    $email = $stmt->fetch(\PDO::FETCH_COLUMN);
    return $email ?: null;
}

function labelFor(?string $aaguidHex): string {
    if ($aaguidHex && $aaguidHex !== '00000000000000000000000000000000') {
        $uuid = implode('-', [
            substr($aaguidHex, 0, 8),
            substr($aaguidHex, 8, 4),
            substr($aaguidHex, 12, 4),
            substr($aaguidHex, 16, 4),
            substr($aaguidHex, 20, 12),
        ]);
        if (isset(AAGUID_LABELS[$uuid])) {
            return AAGUID_LABELS[$uuid];
        }
    }
    return 'Klucz z ' . (new \DateTime())->format('d.m.Y');
}

/** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
function add(
    string $credentialId,
    string $email,
    string $userId,
    string $publicKey,
    ?string $aaguidHex,
    array $transports,
    string $label
): void {
    $stmt = \store\prepare(
        'INSERT INTO ' . TABLE . '
            (credential_id, user_email, user_id, public_key, sign_count, aaguid, transports, label, created_at)
         VALUES (:id, :email, :uid, :pk, 0, :aaguid, :transports, :label, :created)'
    );
    $stmt->execute([
        ':id' => $credentialId,
        ':email' => $email,
        ':uid' => $userId,
        ':pk' => $publicKey,
        ':aaguid' => $aaguidHex,
        ':transports' => json_encode($transports),
        ':label' => $label,
        ':created' => date('c'),
    ]);
}

function byCredentialId(string $credentialId): ?array {
    $stmt = \store\prepare('SELECT * FROM ' . TABLE . ' WHERE credential_id = :id');
    $stmt->execute([':id' => $credentialId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function forEmail(string $email): array {
    $stmt = \store\prepare('SELECT * FROM ' . TABLE . ' WHERE user_email = :e ORDER BY created_at DESC');
    $stmt->execute([':e' => $email]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function countForEmail(string $email): int {
    $stmt = \store\prepare('SELECT COUNT(*) FROM ' . TABLE . ' WHERE user_email = :e');
    $stmt->execute([':e' => $email]);
    return (int)$stmt->fetchColumn();
}

/** Only ever deletes a credential owned by $email — this IS the authorization check. */
function remove(string $credentialId, string $email): bool {
    $stmt = \store\prepare('DELETE FROM ' . TABLE . ' WHERE credential_id = :id AND user_email = :e');
    $stmt->execute([':id' => $credentialId, ':e' => $email]);
    return $stmt->rowCount() > 0;
}

function touch(string $credentialId, int $signCount): void {
    $stmt = \store\prepare(
        'UPDATE ' . TABLE . ' SET sign_count = :c, last_used_at = :t WHERE credential_id = :id'
    );
    $stmt->execute([':c' => $signCount, ':t' => date('c'), ':id' => $credentialId]);
}
