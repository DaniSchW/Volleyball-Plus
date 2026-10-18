<?php

namespace App\Support\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Self-check of the installation's TLS setup: the certificate must be
 * valid for the host, trusted, not about to expire, and plain HTTP must
 * redirect to HTTPS.
 */
class SslCheck
{
    public function __construct(
        private string $host,
        private int $port = 443,
        private int $warnDays = 14,
    ) {}

    public static function fromConfig(): self
    {
        $host = config('security.ssl_check.host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        return new self(
            (string) $host,
            config('security.ssl_check.port'),
            config('security.ssl_check.warn_days'),
        );
    }

    /**
     * @return array{ok: bool, host: string, checked_at: string, valid_until: ?string, days_left: ?int, issuer: ?string, errors: list<string>, warnings: list<string>}
     */
    public function run(): array
    {
        $certificate = null;
        $connectError = null;

        try {
            $certificate = $this->fetchCertificate();
        } catch (Throwable $e) {
            $connectError = $e->getMessage();
        }

        [$redirectStatus, $redirectLocation] = $this->probeHttpRedirect();

        return $this->evaluate($certificate, $connectError, $redirectStatus, $redirectLocation, CarbonImmutable::now());
    }

    /**
     * Pure evaluation of the collected facts (unit-tested separately).
     *
     * @param  array<string, mixed>|null  $certificate  result of openssl_x509_parse()
     * @return array{ok: bool, host: string, checked_at: string, valid_until: ?string, days_left: ?int, issuer: ?string, errors: list<string>, warnings: list<string>}
     */
    public function evaluate(?array $certificate, ?string $connectError, ?int $redirectStatus, ?string $redirectLocation, CarbonImmutable $now): array
    {
        $errors = [];
        $warnings = [];
        $validUntil = null;
        $daysLeft = null;
        $issuer = null;

        if ($this->host === '' || $this->host === 'localhost') {
            $errors[] = 'no_public_host';
        }

        if ($certificate === null) {
            $errors[] = 'tls_handshake_failed'.($connectError ? ': '.$connectError : '');
        } else {
            $validUntil = CarbonImmutable::createFromTimestamp((int) $certificate['validTo_time_t']);
            $validFrom = CarbonImmutable::createFromTimestamp((int) $certificate['validFrom_time_t']);
            $daysLeft = (int) floor($now->diffInDays($validUntil, false));
            $issuer = $certificate['issuer']['O'] ?? $certificate['issuer']['CN'] ?? null;

            if ($validFrom->isAfter($now) || $validUntil->isBefore($now)) {
                $errors[] = 'certificate_expired';
            } elseif ($daysLeft < $this->warnDays) {
                $warnings[] = 'certificate_expires_soon';
            }
        }

        $redirectsToHttps = $redirectStatus !== null
            && in_array($redirectStatus, [301, 302, 307, 308], true)
            && str_starts_with((string) $redirectLocation, 'https://');

        if (! $redirectsToHttps) {
            $errors[] = 'http_not_redirected';
        }

        return [
            'ok' => $errors === [],
            'host' => $this->host,
            'checked_at' => $now->toIso8601String(),
            'valid_until' => $validUntil?->toIso8601String(),
            'days_left' => $daysLeft,
            'issuer' => is_string($issuer) ? $issuer : null,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Connects with full peer and host name verification; a self-signed,
     * untrusted or mismatching certificate makes the handshake fail.
     *
     * @return array<string, mixed>
     */
    private function fetchCertificate(): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            throw new \RuntimeException($errorMessage ?: 'connection failed');
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

        if ($parsed === false) {
            throw new \RuntimeException('certificate could not be parsed');
        }

        return $parsed;
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function probeHttpRedirect(): array
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->get("http://{$this->host}/");

            return [$response->status(), $response->header('Location')];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
