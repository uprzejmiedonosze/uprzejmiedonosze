<?PHP namespace crypto;

define('CRYPTO_KEY', '30e0d8ab42e47383100df63ba59aea4c');
define('CRYPTO_IV', '176da7f7de4e788e09b8c4fd3655cf7c');

const CIPHER = 'AES-256-CBC';
const IV_LENGTH = 16; // openssl_cipher_iv_length(CIPHER);

function encode(string $message, string $uuid, string $salt): string {
    return __base64urlEncode(
        openssl_encrypt(
            data: $message,
            cipher_algo: CIPHER,
            passphrase: __passphrase($uuid),
            options: 0,
            iv: __initVector($salt)));
}

function decode(string $message, string $uuid, string $salt): string {
    return openssl_decrypt(
        data: __base64urlDecode($message),
        cipher_algo: CIPHER,
        passphrase: __passphrase($uuid),
        options: 0,
        iv: __initVector($salt));
}

function __passphrase(string $passphrase): string {
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
