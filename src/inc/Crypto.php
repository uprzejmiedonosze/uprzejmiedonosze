<?PHP namespace crypto;

const CIPHER = 'AES-256-CBC';
const IV_LENGTH = 16; // openssl_cipher_iv_length(CIPHER);

class DecodingException extends \Exception {}

function encode(string $message, string $uuid, string $salt): string {
    return __base64urlEncode(
        openssl_encrypt(
            data: $message,
            cipher_algo: CIPHER,
            passphrase: passphrase($uuid),
            options: 0,
            iv: __initVector($salt)));
}

function decode(string $message, string $uuid, string $salt): string {
    return __openssl_decrypt(
        data: __base64urlDecode($message),
        uuid: $uuid,
        salt: $salt);
}

function __openssl_decrypt(
    string $data,
    string $uuid,
    string $salt
): string {
    $ret = openssl_decrypt(
        data: $data,
        cipher_algo: CIPHER,
        passphrase: passphrase($uuid),
        options: 0,
        iv:  __initVector($salt)
    );
    if ($ret === false) {
        throw new DecodingException("Decryption failed $salt $uuid");
    }
    return $ret;
}

function passphrase(string $passphrase): string {
    return hash('sha256', $passphrase);
}

function __initVector(string $salt): string {
    return substr(hash('sha256', $salt), 0, IV_LENGTH);
}

function __base64urlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
    
function __base64urlDecode($data) {  
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}
