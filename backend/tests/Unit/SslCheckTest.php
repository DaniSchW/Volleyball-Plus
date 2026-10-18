<?php

namespace Tests\Unit;

use App\Support\Security\SslCheck;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SslCheckTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-18 12:00:00');
    }

    /**
     * @return array<string, mixed>
     */
    private function certificate(string $from, string $to): array
    {
        return [
            'validFrom_time_t' => CarbonImmutable::parse($from)->getTimestamp(),
            'validTo_time_t' => CarbonImmutable::parse($to)->getTimestamp(),
            'issuer' => ['O' => "Let's Encrypt"],
        ];
    }

    public function test_valid_setup_passes(): void
    {
        $result = (new SslCheck('volleyball.example.org'))->evaluate(
            $this->certificate('2026-09-01', '2026-12-01'), null, 301, 'https://volleyball.example.org/', $this->now,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(43, $result['days_left']);
        $this->assertSame("Let's Encrypt", $result['issuer']);
    }

    public function test_certificate_expiring_soon_warns(): void
    {
        $result = (new SslCheck('volleyball.example.org', warnDays: 14))->evaluate(
            $this->certificate('2026-08-01', '2026-10-25'), null, 301, 'https://volleyball.example.org/', $this->now,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(['certificate_expires_soon'], $result['warnings']);
    }

    public function test_expired_certificate_fails(): void
    {
        $result = (new SslCheck('volleyball.example.org'))->evaluate(
            $this->certificate('2026-07-01', '2026-10-01'), null, 301, 'https://volleyball.example.org/', $this->now,
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('certificate_expired', $result['errors']);
    }

    public function test_failed_handshake_fails(): void
    {
        $result = (new SslCheck('volleyball.example.org'))->evaluate(
            null, 'certificate verify failed', 301, 'https://volleyball.example.org/', $this->now,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('tls_handshake_failed: certificate verify failed', $result['errors'][0]);
    }

    public function test_missing_http_redirect_fails(): void
    {
        $cert = $this->certificate('2026-09-01', '2026-12-01');
        $check = new SslCheck('volleyball.example.org');

        $this->assertContains('http_not_redirected', $check->evaluate($cert, null, 200, null, $this->now)['errors']);
        $this->assertContains('http_not_redirected', $check->evaluate($cert, null, 301, 'http://volleyball.example.org/', $this->now)['errors']);
        $this->assertContains('http_not_redirected', $check->evaluate($cert, null, null, null, $this->now)['errors']);
    }

    public function test_localhost_is_not_a_valid_production_host(): void
    {
        $result = (new SslCheck('localhost'))->evaluate(
            $this->certificate('2026-09-01', '2026-12-01'), null, 301, 'https://localhost/', $this->now,
        );

        $this->assertContains('no_public_host', $result['errors']);
    }
}
