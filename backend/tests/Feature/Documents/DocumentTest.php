<?php

namespace Tests\Feature\Documents;

use App\Enums\TeamRole;
use App\Models\Document;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $h1;

    private Team $h2;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $max;

    private TenantMember $paul;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->tenant();
        $this->h1 = $this->team($this->tenant);
        $this->h2 = $this->team($this->tenant, 'Herren 2');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->max = $this->member($this->tenant, 'max@example.org', 'Max', 'Erster');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Zweiter');
        $this->addToTeam($this->h1, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->h1, $this->personOf($this->max));
        $this->addToTeam($this->h2, $this->personOf($this->paul));
    }

    private function upload(TenantMember $as, array $data, string $name = 'vereinsmeldung.pdf'): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");

        return $this->loginAs($as)->post('/api/v1/t/ssv-ulm/documents', $data + ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @return list<string> */
    private function titles(TenantMember $as, string $query = ''): array
    {
        return array_column($this->loginAs($as)->getJson('/api/v1/t/ssv-ulm/documents'.$query)->assertOk()->json('data.documents'), 'title');
    }

    public function test_department_and_team_documents(): void
    {
        // For everybody: only admins upload.
        $this->upload($this->trainer, ['title' => 'Vereinsmeldung'])->assertForbidden();
        $club = $this->upload($this->admin, ['title' => 'Vereinsmeldung 2026', 'folder' => 'Verband'])->assertCreated()->json('data');
        $this->assertSame(['vereinsmeldung.pdf', null], [$club['name'], $club['team']]);

        // Only for Herren 1: its trainer uploads, not for other teams; players never.
        $this->upload($this->trainer, ['team_id' => $this->h2->id])->assertForbidden();
        $this->upload($this->max, ['team_id' => $this->h1->id])->assertForbidden();
        $setup = $this->upload($this->trainer, ['team_id' => $this->h1->id, 'title' => 'Aufbauanleitung Spieltag'], 'aufbau.pdf')->assertCreated()->json('data');

        $this->assertSame(['Aufbauanleitung Spieltag', 'Vereinsmeldung 2026'], $this->titles($this->max));
        $this->assertSame(['Vereinsmeldung 2026'], $this->titles($this->paul));
        $this->assertSame(['Aufbauanleitung Spieltag'], $this->titles($this->max, '?team='.$this->h1->id));
        $this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/documents?team='.$this->h1->id)->assertForbidden();

        // Download: PDF opens in the browser; only for who may see it, never via the general file route.
        $this->loginAs($this->paul)->get($club['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->loginAs($this->paul)->getJson($setup['url'])->assertForbidden();
        $this->loginAs($this->max)->get($setup['url'])->assertOk();
        $fileId = $this->inTenant($this->tenant, fn () => Document::query()->find($setup['id'])->file_id);
        $this->loginAs($this->max)->getJson("/api/v1/t/ssv-ulm/files/{$fileId}")->assertNotFound();

        $list = $this->loginAs($this->trainer)->getJson('/api/v1/t/ssv-ulm/documents')->json('data');
        $this->assertFalse($list['can_upload']);
        $this->assertSame([true], array_column($list['teams'], 'can_upload'));
    }

    public function test_manage_documents(): void
    {
        $doc = $this->upload($this->trainer, ['team_id' => $this->h1->id, 'title' => 'Aufbau'])->assertCreated()->json('data');

        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/documents/{$doc['id']}", ['title' => 'Aufbau Netzanlage', 'folder' => 'Spieltag'])
            ->assertOk()->assertJsonPath('data.folder', 'Spieltag');
        // Moving to "for everybody" needs an admin.
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/documents/{$doc['id']}", ['team_id' => null])->assertForbidden();
        $this->loginAs($this->admin)->patchJson("/api/v1/t/ssv-ulm/documents/{$doc['id']}", ['team_id' => null])->assertOk()->assertJsonPath('data.team', null);
        $this->assertSame(['Aufbau Netzanlage'], $this->titles($this->paul));

        // Wrong file types are refused.
        $exe = UploadedFile::fake()->createWithContent('tool.exe', 'MZ binary');
        $this->loginAs($this->admin)->post('/api/v1/t/ssv-ulm/documents', ['file' => $exe], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->loginAs($this->trainer)->deleteJson("/api/v1/t/ssv-ulm/documents/{$doc['id']}")->assertForbidden();
        $this->loginAs($this->admin)->deleteJson("/api/v1/t/ssv-ulm/documents/{$doc['id']}")->assertOk();
        $this->assertSame([], $this->titles($this->paul));
        $this->assertSame([], Storage::disk('local')->allFiles("tenants/{$this->tenant->id}/document"));
    }
}
