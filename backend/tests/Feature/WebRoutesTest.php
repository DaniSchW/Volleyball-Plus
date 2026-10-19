<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WebRoutesTest extends TestCase
{
    private string $entry;

    private ?string $previous = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entry = public_path('app.html');
        $this->previous = is_file($this->entry) ? file_get_contents($this->entry) : null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            @unlink($this->entry);
        } else {
            file_put_contents($this->entry, $this->previous);
        }

        parent::tearDown();
    }

    public function test_app_paths_are_served_by_the_pwa(): void
    {
        file_put_contents($this->entry, '<!doctype html><title>VolleyOrga</title>');

        $this->get('/ssv-ulm/termine')
            ->assertOk()
            ->assertSee('VolleyOrga', false)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_missing_frontend_build_is_reported(): void
    {
        @unlink($this->entry);

        $this->get('/ssv-ulm')->assertStatus(503);
    }

    public function test_cron_url_requires_the_configured_token(): void
    {
        config(['app.cron_token' => 'geheim-1234567890']);
        Artisan::shouldReceive('call')->once()->with('schedule:run');

        $this->get('/cron/falsch')->assertNotFound();
        $this->get('/cron/geheim-1234567890')->assertOk();
    }

    public function test_cron_url_is_disabled_without_token(): void
    {
        config(['app.cron_token' => null]);

        $this->get('/cron/irgendwas')->assertNotFound();
    }
}
