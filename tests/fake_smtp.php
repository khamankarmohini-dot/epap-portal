<?php
/**
 * Minimal local fake SMTP server for automated testing of PHPMailer delivery.
 * Usage: php tests/fake_smtp.php 127.0.0.1 2525 /path/to/capture.log
 */
declare(strict_types=1);

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 2525);
$outFile = $argv[3] ?? sys_get_temp_dir() . '/epap_fake_smtp.log';

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to bind {$host}:{$port}: {$errstr}\n");
    exit(1);
}

while ($conn = @stream_socket_accept($server, -1)) {
    fwrite($conn, "220 localhost ESMTP FakeSMTP\r\n");
    $inData = false;
    $buf = '';
    while (!feof($conn)) {
        $line = fgets($conn);
        if ($line === false) {
            break;
        }
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                file_put_contents($outFile, $buf . "\n---END_MSG---\n", FILE_APPEND | LOCK_EX);
                $buf = '';
                fwrite($conn, "250 2.0.0 OK\r\n");
            } else {
                $buf .= $line;
            }
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($conn, "250-localhost\r\n250 OK\r\n");
        } elseif ($cmd === 'MAIL' || $cmd === 'RCPT' || $cmd === 'NOOP' || $cmd === 'RSET') {
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'DATA') {
            $inData = true;
            fwrite($conn, "354 End data with <CR><LF>.<CR><LF>\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($conn, "221 Bye\r\n");
            break;
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
}
