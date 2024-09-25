<?

class Crypto {
    private static string $key = '';
    private static string $initVector = '';
    private static string $method = 'AES-256-CBC';
    
    public function __construct() {
        Crypto::$key = hash('sha256', CRYPTO_KEY);
        Crypto::$initVector = substr(hash('sha256', CRYPTO_IV), 0, 16);
    }

    public function encode(string $message): string {
        return base64_encode(
            openssl_encrypt($message,
                Crypto::$method,
                Crypto::$key,
                0,
                Crypto::$initVector));
    }

    public function decode(string $message): string {
        return openssl_decrypt(
            base64_decode($message),
            Crypto::$method,
            Crypto::$key,
            0,
            Crypto::$initVector);
    }
}
