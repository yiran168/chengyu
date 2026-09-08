<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Problem extends \RuntimeException
{
    public int $status;
    public function __construct(string $message, int $status = 400) { parent::__construct($message); $this->status = $status; }
}

final class Input
{
    public static function text($value, int $max = 255): string
    {
        if (!is_scalar($value) && $value !== null) { throw new Problem('Invalid input.'); }
        $value = trim((string)$value);
        if (!preg_match('//u', $value) || strlen($value) > $max * 4 || preg_match_all('/./us', $value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            throw new Problem('Invalid text or text is too long.');
        }
        return $value;
    }
    /** Truncate a previously validated UTF-8 display label without requiring mbstring. */
    public static function clip(string $value, int $max): string
    {
        if ($max < 1 || $max > 10000) { throw new \InvalidArgumentException('Invalid display length'); }
        if (!preg_match('/^.{0,' . $max . '}/us', $value, $matches)) { throw new Problem('Invalid input.'); }
        return $matches[0];
    }
    public static function required($value, int $max = 255): string
    {
        $value = self::text($value, $max);
        if ($value === '') { throw new Problem('Please complete the required fields.'); }
        return $value;
    }
    public static function integer($value, int $min = 0, int $max = 2147483647): int
    {
        if (!is_scalar($value) || !preg_match('/^-?\d{1,12}$/D', (string)$value)) { throw new Problem('Invalid integer.'); }
        $value = (int)$value;
        if ($value < $min || $value > $max) { throw new Problem('Value is outside the allowed range.'); }
        return $value;
    }
    /** Never use binary floats for prices or balances. */
    public static function cents($value, bool $allowZero = false): int
    {
        if (!is_scalar($value) || !preg_match('/^(0|[1-9]\d{0,6})(?:\.(\d{1,2}))?$/D', (string)$value, $m)) {
            throw new Problem('Enter an amount with at most two decimal places.');
        }
        $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if (!$allowZero && $cents === 0) { throw new Problem('Amount must be positive.'); }
        return $cents;
    }
    public static function choice($value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) { throw new Problem('Invalid selection.'); }
        return $value;
    }
    public static function url($value, bool $required = false): string
    {
        $value = self::text($value, 1000);
        if ($value === '' && !$required) { return ''; }
        $parts = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new Problem('A valid HTTP or HTTPS URL is required.');
        }
        return $value;
    }
    public static function passwordValue($value): string
    {
        if (!is_string($value) || strlen($value)>72 || strpos($value, "\0")!==false) { throw new Problem('Invalid password.'); }
        return $value;
    }
    public static function password($value): string
    {
        if (!is_string($value) || strpos($value, "\0") !== false) { throw new Problem('Invalid password.'); }
        if (strlen($value) < 12 || strlen($value) > 72 || !preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value)) {
            throw new Problem('Use 12-72 bytes with letters and numbers for the password.');
        }
        return $value;
    }
}

final class Crypto
{
    private string $key;
    public function __construct(string $secret) { $this->key = hash('sha256', $secret, true); }
    public function seal(string $plain): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'chengyu:v1');
        if ($cipher === false) { throw new \RuntimeException('Encryption failed'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('Invalid encrypted data'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'chengyu:v1');
        if ($plain === false) { throw new \RuntimeException('Encrypted data could not be authenticated'); }
        return $plain;
    }
    public function digest(string $value): string { return hash_hmac('sha256', $value, $this->key); }
}

final class Security
{
    public static function headers(string $storageOrigin=''): void
    {
        if($storageOrigin!==''&&!preg_match('~^https://[a-zA-Z0-9.-]+$~D',$storageOrigin)){throw new \InvalidArgumentException('Unsafe storage origin');}
        $storageOrigin=$storageOrigin!==''?' '.$storageOrigin:'';
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'$storageOrigin; media-src 'self'$storageOrigin; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        header('Cache-Control: private, no-store');
    }
    public static function session(string $basePath = '/', bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('CYSESSID');
        session_set_cookie_params(['lifetime' => 0, 'path' => $basePath ?: '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    }
    public static function csrf(): string { return $_SESSION['csrf'] ?? ''; }
    public static function verify($token): void
    {
        if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new Problem('Security token expired. Reload the page and try again.', 419);
        }
    }
    public static function ip(): string
    {
        // Never trust client-supplied forwarding headers by default.
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
    public static function token(): string { return bin2hex(random_bytes(16)); }
}
