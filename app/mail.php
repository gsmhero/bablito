<?php
declare(strict_types=1);

// Minimal SMTP client (no libraries): SSL on port 465, STARTTLS when the server offers it,
// AUTH LOGIN with SMTP_USER / SMTP_PASS from .env. Every attempt is logged to storage/mail.log.

function mail_log(string $line): void
{
    @file_put_contents(ROOT . '/storage/mail.log', '[' . date('c') . '] ' . $line . "\n", FILE_APPEND);
}

/** Sends a plain-text e-mail. Returns null on success or an error text. */
function smtp_send(string $to, string $subject, string $text, ?string $html = null, array $attachments = []): ?string
{
    if (str_ends_with(strtolower(trim($to)), '.invalid')) {
        return null; // technical address of a VK / OK user: nobody to write to
    }
    $host = env('SMTP_HOST');
    $port = (int)(env('SMTP_PORT') ?: 587);
    $user = env('SMTP_USER');
    $pass = env('SMTP_PASS');
    $from = trim(env('SMTP_FROM') ?: $user);
    if ($host === '' || $from === '') {
        return 'SMTP_HOST / SMTP_FROM are not set';
    }
    // SMTP_FROM may be an address, "Name <address>", or only a display name such as "Bablito | Notification".
    // The envelope address must be a real address: the one in SMTP_FROM, else the SMTP login.
    if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/', $from, $fm)) {
        $fromName = trim($fm[1], " \"");
        $addr = trim($fm[2]);
    } elseif (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $fromName = '';
        $addr = $from;
    } else {
        $fromName = $from;
        $addr = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : '';
    }
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        return 'no sender address: set SMTP_FROM to an e-mail address (or "Name <address>"), or use an e-mail address as SMTP_USER';
    }
    $fromHeader = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $addr . '>' : $addr;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'invalid recipient address';
    }
    $secure = $port === 465;
    $fp = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12);
    if (!$fp) {
        $err = "cannot connect to the mail server ($errstr)";
        mail_log("FAILED to $to: $err");
        return $err;
    }
    stream_set_timeout($fp, 12);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (?string $c, ?string $expect) use ($fp, $read): string {
        if ($c !== null) {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        if ($expect !== null && strpos($r, $expect) !== 0) {
            throw new RuntimeException(trim(substr($r, 0, 200)) ?: 'no answer from the mail server');
        }
        return $r;
    };
    try {
        $helo = parse_url(env('SITE_URL'), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, '220');
        $ehlo = $cmd('EHLO ' . $helo, '250');
        if (!$secure && stripos($ehlo, 'STARTTLS') !== false) {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed');
            }
            $cmd('EHLO ' . $helo, '250');
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode($user), '334');
            $cmd(base64_encode($pass), '235');
        }
        $cmd('MAIL FROM:<' . $addr . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '250');
        $cmd('DATA', '354');
        $b64 = fn(string $d): string => chunk_split(base64_encode($d), 76, "\r\n");
        if ($html === null) {
            $ct = 'text/plain; charset=UTF-8';
            $part = "Content-Transfer-Encoding: base64\r\n\r\n" . $b64($text);
        } else {
            $alt = 'bbl_a' . bin2hex(random_bytes(8));
            $ct = 'multipart/alternative; boundary="' . $alt . '"';
            $part = "\r\n--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text)
                . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
                . "--$alt--\r\n";
        }
        if ($attachments) {
            $mix = 'bbl_m' . bin2hex(random_bytes(8));
            $inner = "Content-Type: $ct\r\n" . $part;
            $part = "\r\n--$mix\r\n" . $inner;
            foreach ($attachments as $a) {
                $fn = '=?UTF-8?B?' . base64_encode((string)$a['name']) . '?=';
                $part .= "--$mix\r\nContent-Type: " . $a['type'] . "; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n" . $b64((string)$a['data']);
            }
            $part .= "--$mix--\r\n";
            $ct = 'multipart/mixed; boundary="' . $mix . '"';
        }
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $helo . '>',
            'MIME-Version: 1.0',
        ];
        $headers[] = 'Content-Type: ' . $ct;
        $body = $part;
        // dot-stuffing is not needed: base64 lines never start with a dot
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $r = $read();
        if (strpos($r, '250') !== 0) {
            throw new RuntimeException(trim(substr($r, 0, 200)));
        }
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        mail_log("sent to $to: $subject");
        return null;
    } catch (Throwable $e) {
        @fclose($fp);
        mail_log("FAILED to $to: " . $e->getMessage());
        return $e->getMessage();
    }
}
