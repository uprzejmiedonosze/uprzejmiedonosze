<?PHP

class Crypto {
    private static string $key = '';
    private static string $initVector = '';
    private static string $cipher = 'AES-256-CBC';
    private static int $ivLength;
    
    public function __construct() {
        Crypto::$key = hash('sha256', CRYPTO_KEY);
        Crypto::$ivLength = openssl_cipher_iv_length(Crypto::$cipher);
        Crypto::$initVector = substr(hash('sha256', CRYPTO_IV), 0, Crypto::$ivLength);
    }

    private function base64urlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
      
    private function base64urlDecode($data) {  
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
      }

    public function encode(string $message): string {
        return $this->base64urlEncode(
            openssl_encrypt($message,
                Crypto::$cipher,
                Crypto::$key,
                0,
                Crypto::$initVector
            ));
    }

    public function decode(string $message): string {
        return openssl_decrypt(
            $this->base64urlDecode($message),
            Crypto::$cipher,
            Crypto::$key,
            0,
            Crypto::$initVector
        );
    }
}
