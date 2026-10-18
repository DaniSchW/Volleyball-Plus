<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class HttpsTest extends TestCase
{
    public function test_plain_http_page_loads_are_redirected_to_https(): void
    {
        $this->get('http://localhost/ssv-ulm/termine?x=1')
            ->assertStatus(301)
            ->assertRedirect('https://localhost/ssv-ulm/termine?x=1');
    }

    public function test_plain_http_api_calls_are_refused(): void
    {
        $this->getJson('http://localhost/api/v1/health')->assertForbidden();
    }

    public function test_plain_http_posts_are_refused(): void
    {
        $this->post('http://localhost/irgendwas')->assertForbidden();
    }

    public function test_https_requests_pass_and_carry_security_headers(): void
    {
        $response = $this->getJson('https://localhost/api/v1/health')
            ->assertOk()
            ->assertJsonPath('secure', true);

        $this->assertStringContainsString('max-age=63072000', $response->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_https_behind_a_trusted_proxy_is_recognised(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->getJson('http://localhost/api/v1/health', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJsonPath('secure', true);
    }

    public function test_forwarded_proto_from_untrusted_clients_is_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->getJson('http://localhost/api/v1/health', ['X-Forwarded-Proto' => 'https'])
            ->assertForbidden();
    }

    public function test_language_follows_the_browser(): void
    {
        $this->getJson('/api/v1/health', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertJsonPath('locale', 'en');

        $this->getJson('/api/v1/health', ['Accept-Language' => 'fr-FR'])
            ->assertJsonPath('locale', 'de');
    }
}
