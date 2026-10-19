<?php

namespace Tests\Feature\Install;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Install\SetupCode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use DatabaseMigrations;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the real .env and storage untouched: the installer writes into a sandbox.
        $this->sandbox = sys_get_temp_dir().'/vp-install-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->sandbox.'/storage/app');
        $this->app->useEnvironmentPath($this->sandbox);
        $this->app->useStoragePath($this->sandbox.'/storage');

        // An installation without application key counts as not installed.
        config(['app.key' => '']);

        // Symfony's test requests announce English by default.
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    private function setupCode(): string
    {
        $this->get('/install')->assertOk();

        return trim((string) file_get_contents(SetupCode::path()));
    }

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        $db = config('database.connections.'.config('database.default'));

        return array_merge([
            'setup_code' => $this->setupCode(),
            'app_url' => 'https://volleyball.example.org',
            'db_host' => (string) ($db['host'] ?? '127.0.0.1'),
            'db_port' => (string) ($db['port'] ?? '3306'),
            'db_database' => (string) ($db['database'] ?? ''),
            'db_username' => (string) ($db['username'] ?? ''),
            'db_password' => (string) ($db['password'] ?? ''),
            'mail_mailer' => 'phpmail',
            'mail_from_address' => 'noreply@example.org',
            'tenant_name' => 'SSV Ulm Volleyball',
            'tenant_slug' => 'ssv-ulm',
            'admin_first_name' => 'Dana',
            'admin_last_name' => 'Admin',
            'admin_gender' => 'female',
            'admin_email' => 'Dana@Example.org',
        ], $overrides);
    }

    public function test_pages_and_api_lead_to_the_installer_until_installed(): void
    {
        $this->get('/ssv-ulm')->assertRedirect('/install');
        $this->getJson('/api/v1/health')->assertStatus(503);
    }

    public function test_installer_shows_requirements_and_creates_a_setup_code(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('VolleyOrg einrichten')
            ->assertSee('PHP-Erweiterung pdo_mysql')
            ->assertSee('backend/storage/app/setup-code.txt');

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', trim((string) file_get_contents(SetupCode::path())));
    }

    public function test_installer_is_gone_once_installed(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->get('/install')->assertNotFound();
        $this->post('/install', [])->assertNotFound();
    }

    public function test_wrong_setup_code_is_rejected(): void
    {
        $this->post('/install', $this->form(['setup_code' => 'AAAAA-BBBBB-CCCCC-DDDDD']))
            ->assertStatus(422)
            ->assertSee('Der Einrichtungscode stimmt nicht.');

        $this->assertFileDoesNotExist($this->sandbox.'/.env');
        $this->assertSame(0, Tenant::count());
    }

    public function test_invalid_input_is_reported_in_german(): void
    {
        $this->post('/install', $this->form(['app_url' => 'http://unsicher.example.org', 'tenant_slug' => 'Mit Leerzeichen']))
            ->assertStatus(422)
            ->assertSee('Adresse der App (https://…) muss eine gültige Adresse sein', false)
            ->assertSee('Kürzel für die Adresse hat ein ungültiges Format.');

        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_unreachable_database_is_reported(): void
    {
        $this->post('/install', $this->form(['db_host' => '127.0.0.1', 'db_port' => '1', 'db_database' => 'x', 'db_username' => 'x']))
            ->assertStatus(422)
            ->assertSee('Verbindung zur Datenbank fehlgeschlagen');

        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }

    public function test_full_installation_against_mysql(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (runs in CI).');
        }

        $response = $this->post('/install', $this->form())
            ->assertOk()
            ->assertSee('Fertig eingerichtet')
            ->assertSee('https://volleyball.example.org/cron/', false)
            ->assertSee('https://volleyball.example.org/ssv-ulm', false);

        $env = (string) file_get_contents($this->sandbox.'/.env');
        $this->assertMatchesRegularExpression("/^APP_KEY='base64:[A-Za-z0-9+\\/=]{44}'$/m", $env);
        $this->assertStringContainsString("APP_ENV='production'", $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('FORCE_HTTPS=true', $env);
        $this->assertStringContainsString("MAIL_MAILER='phpmail'", $env);
        $this->assertStringNotContainsString('MAIL_HOST', $env);
        $this->assertMatchesRegularExpression("/^CRON_TOKEN='[A-Za-z0-9]{48}'$/m", $env);
        $response->assertSee(substr((string) preg_replace('/.*CRON_TOKEN=\'([^\']+)\'.*/s', '$1', $env), 0, 48));

        $tenant = Tenant::where('slug', 'ssv-ulm')->first();
        $this->assertSame('SSV Ulm Volleyball', $tenant->name);

        // First admin with own profile; the address is stored in lower case.
        $account = Account::where('email', 'dana@example.org')->first();
        $this->assertNotNull($account);
        $this->inTenant($tenant, function () use ($account) {
            $member = TenantMember::where('account_id', $account->id)->first();
            $this->assertTrue($member->is_admin);
            $this->assertSame('Dana', $member->ownPerson()?->first_name);
        });
        $this->assertFileDoesNotExist(SetupCode::path());
    }

    public function test_smtp_settings_are_written(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (runs in CI).');
        }

        $this->post('/install', $this->form([
            'mail_mailer' => 'smtp',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => '465',
            'smtp_scheme' => 'smtps',
            'smtp_username' => 'noreply@example.org',
            'smtp_password' => "pa'ss\$word",
        ]))->assertOk();

        $env = (string) file_get_contents($this->sandbox.'/.env');
        $this->assertStringContainsString("MAIL_MAILER='smtp'", $env);
        $this->assertStringContainsString("MAIL_HOST='smtp.example.org'", $env);
        $this->assertStringContainsString("MAIL_SCHEME='smtps'", $env);
        $this->assertStringContainsString('MAIL_PASSWORD="pa\'ss\\$word"', $env);
    }
}
