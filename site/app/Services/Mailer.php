<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Settings, Problem};
final class Mailer
{
    private Settings $settings;
    public function __construct(Settings $settings) { $this->settings = $settings; }
    public function send(string $to, string $subject, string $body): void
    {
        $s = $this->settings; $s->requireModule('smtp');
        $from = (string)$s->get('smtp_from'); $host = (string)$s->get('smtp_host'); $port = (int)$s->get('smtp_port');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z0-9.\-]+$/D', $host)) { throw new Problem('Invalid SMTP configuration.'); }
        $mode = $s->get('smtp_security');
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'allow_self_signed' => false]]);
        $socket = @stream_socket_client(($mode === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) { throw new \RuntimeException('SMTP connection failed'); }
        stream_set_timeout($socket, 8);
        try {
            $this->expect($socket, [220]); $this->command($socket, 'EHLO chengyu.local', [250]);
            if ($mode === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new \RuntimeException('SMTP TLS failed'); }
                $this->command($socket, 'EHLO chengyu.local', [250]);
            }
            $user = (string)$s->get('smtp_user');
            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($user), [334]);
                $this->command($socket, base64_encode((string)$s->get('smtp_password')), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $headers = ['From: <' . $from . '>', 'To: <' . $to . '>', 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', 'Date: ' . gmdate('r'), 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $host . '>'];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . "\r\n.\r\n";
            $this->write($socket, $data); $this->expect($socket, [250]);
            // A server accepting DATA has accepted the message; a lost QUIT is not a delivery failure.
            try { $this->command($socket, 'QUIT', [221]); } catch (\Throwable $ignored) {}
        } finally { fclose($socket); }
    }
    private function write($socket, string $data): void
    {
        while ($data !== '') { $sent = fwrite($socket, $data); if ($sent === false || $sent === 0) { throw new \RuntimeException('SMTP write failed'); } $data = substr($data, $sent); }
    }
    private function command($socket, string $command, array $codes): void { $this->write($socket, $command . "\r\n"); $this->expect($socket, $codes); }
    private function expect($socket, array $codes): void
    {
        for ($i = 0; $i < 50; $i++) {
            $line = fgets($socket, 1024);
            if ($line === false || strlen($line) < 4) { throw new \RuntimeException('SMTP response missing'); }
            if ($line[3] === ' ') { if (!in_array((int)substr($line, 0, 3), $codes, true)) { throw new \RuntimeException('SMTP rejected request'); } return; }
        }
        throw new \RuntimeException('SMTP response too long');
    }
}
