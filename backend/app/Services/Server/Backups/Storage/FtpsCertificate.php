<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use Throwable;

/**
 * The fingerprint of the certificate an FTPS server presents (FTP-01).
 *
 * FTPS destinations verified nothing: PHP's `ftp_ssl_connect` performs no
 * certificate or hostname check, so anyone between the panel and the backup
 * server could answer instead and receive the FTP password and every archive.
 * SFTP pins the host key on first use; this gives FTPS the same — recorded
 * the first time, compared before every connection after.
 *
 * Read on a connection of its own, because the ftp extension never exposes
 * the certificate of the connection it makes: open the control channel, ask
 * `AUTH TLS`, complete the handshake with the certificate captured, hang up.
 * That stops an impostor who is in the way all the time — the realistic case
 * — but not one who switches in the moment between this check and the real
 * connection; closing that needs a transport that exposes its certificate,
 * which the ftp extension does not.
 */
class FtpsCertificate
{
    /** @var Closure(string, int, int): ?string */
    private Closure $reader;

    /**
     * @param  (callable(string, int, int): ?string)|null  $reader  Tests inject one; production does the handshake.
     */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader !== null
            ? Closure::fromCallable($reader)
            : fn (string $host, int $port, int $timeout): ?string => $this->handshake($host, $port, $timeout);
    }

    /**
     * `sha256:<hex>`, or null when the server could not be reached or would
     * not complete TLS.
     */
    public function fingerprint(string $host, int $port, int $timeout = 10): ?string
    {
        try {
            return ($this->reader)($host, $port, $timeout);
        } catch (Throwable) {
            return null;
        }
    }

    private function handshake(string $host, int $port, int $timeout): ?string
    {
        $context = stream_context_create(['ssl' => [
            // Not verified here on purpose: a self-signed certificate is the
            // common case on a backup box, and the pin is what is checked.
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, $timeout);

            if (! str_starts_with($this->reply($socket), '220')) {
                return null;
            }

            fwrite($socket, "AUTH TLS\r\n");

            if (! str_starts_with($this->reply($socket), '234')) {
                return null;
            }

            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                return null;
            }

            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return null;
            }

            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

            return $fingerprint === false ? null : 'sha256:'.$fingerprint;
        } finally {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    /**
     * One FTP reply, following a multi-line `220-` greeting to its end.
     *
     * @param  resource  $socket
     */
    private function reply($socket): string
    {
        $line = '';

        for ($i = 0; $i < 50; $i++) {
            $line = (string) fgets($socket, 1024);

            // `220-...` continues; `220 ...` (space after the code) ends it.
            if ($line === '' || preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        return $line;
    }
}
