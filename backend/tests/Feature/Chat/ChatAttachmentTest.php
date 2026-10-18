<?php

namespace Tests\Feature\Chat;

use App\Enums\TeamRole;
use App\Jobs\SendPush;
use App\Models\ChatAttachment;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ChatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantMember $trainer;

    private TenantMember $paul;

    private TenantMember $lena;

    private string $chatId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        Queue::fake();
        Storage::fake('local');

        $this->tenant = $this->tenant();
        $team = $this->team($this->tenant);
        $other = $this->team($this->tenant, 'Damen 1', 'women');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->addToTeam($team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($team, $this->personOf($this->paul));
        $this->addToTeam($other, $this->personOf($this->lena));
        $this->chatId = collect($this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/chats')->json('data'))->firstWhere('name', 'Herren 1')['id'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function upload(TenantMember $as, UploadedFile $file): TestResponse
    {
        return $this->loginAs($as)->post("/api/v1/t/ssv-ulm/chats/{$this->chatId}/attachments", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function zipFile(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
            if ($entry === 'mimetype') {
                $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
            }
        }
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A real temporary file, so the content type is detected from the bytes as in production. */
    private function contentFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_pictures_videos_pdf_office_and_libreoffice_files(): void
    {
        $jpeg = UploadedFile::fake()->image('x.jpg', 4000, 3000);
        $photo = $this->upload($this->paul, $this->contentFile('IMG_0042.JPG', (string) file_get_contents($jpeg->getRealPath())))->assertCreated()->json('data');
        $this->assertSame(['image', 'IMG_0042.webp', 1600, 1200], [$photo['kind'], $photo['name'], $photo['width'], $photo['height']]);

        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 2000);
        $video = $this->upload($this->paul, $this->contentFile('Aufschlag.mp4', $mp4))->assertCreated()->json('data');
        $this->assertSame(['video', 'video/mp4'], [$video['kind'], $video['mime_type']]);

        $pdf = $this->upload($this->paul, $this->contentFile('Spielplan.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"))->assertCreated()->json('data');
        $this->assertSame(['file', 'Spielplan.pdf', 'application/pdf'], [$pdf['kind'], $pdf['name'], $pdf['mime_type']]);

        $docx = $this->upload($this->paul, $this->zipFile('Protokoll.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            'word/document.xml' => '<w:document/>',
        ]))->assertCreated()->json('data');
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx['mime_type']);

        $ods = $this->upload($this->paul, $this->zipFile('Kasse.ods', [
            'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
            'content.xml' => '<office:document-content/>',
        ]))->assertCreated()->json('data');
        $this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $ods['mime_type']);

        $this->upload($this->paul, $this->contentFile('Aufstellung.csv', "Name;Position\nPaul;Mitte\n"))->assertCreated();

        // Disguised or unknown files are refused.
        $this->upload($this->paul, $this->contentFile('virus.pdf', "MZ\x90\x00".str_repeat("\x00", 100)))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($this->paul, $this->contentFile('seite.docx', '<html><script>alert(1)</script></html>'))->assertUnprocessable();
        $this->upload($this->paul, $this->contentFile('tool.exe', "MZ\x90\x00"))->assertUnprocessable();
    }

    public function test_sending_and_who_can_open_the_files(): void
    {
        $pdf = $this->upload($this->paul, $this->contentFile('Spielplan.pdf', "%PDF-1.4\n%%EOF"))->json('data');
        $url = $pdf['url'];

        // Before sending only the uploader sees it.
        $this->loginAs($this->trainer)->get($url)->assertNotFound();
        $message = $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/chats/{$this->chatId}/messages", ['attachment_ids' => [$pdf['id']]])
            ->assertCreated()->json('data');
        $this->assertSame(['Spielplan.pdf'], array_column($message['attachments'], 'name'));
        $this->assertSame('Paul Spieler: 📄 Spielplan.pdf', Queue::pushed(SendPush::class)->first()->payload['body']);

        $response = $this->loginAs($this->trainer)->get($url)->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->loginAs($this->lena)->get($url)->assertForbidden();

        // Not reachable via the general file route.
        $fileId = $this->inTenant($this->tenant, fn () => ChatAttachment::query()->find($pdf['id'])->file_id);
        $this->loginAs($this->trainer)->get("/api/v1/t/ssv-ulm/files/{$fileId}")->assertNotFound();

        // An empty message without files is refused; others' uploads cannot be sent.
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/chats/{$this->chatId}/messages", ['body' => ''])->assertUnprocessable();
        $foreign = $this->upload($this->trainer, $this->contentFile('a.txt', 'Hallo'))->json('data');
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/chats/{$this->chatId}/messages", ['attachment_ids' => [$foreign['id']]])->assertUnprocessable();

        // Deleting the message removes the file.
        $this->loginAs($this->paul)->deleteJson("/api/v1/t/ssv-ulm/chat-messages/{$message['id']}")->assertOk()->assertJsonPath('data.attachments', []);
        $this->loginAs($this->trainer)->get($url)->assertNotFound();
    }

    public function test_documents_download_and_videos_support_ranges(): void
    {
        $doc = $this->upload($this->paul, $this->contentFile('Liste.txt', 'Trikots: 12'))->json('data');
        $video = $this->upload($this->paul, $this->contentFile('Aufschlag.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x01", 5000)))->json('data');
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/chats/{$this->chatId}/messages", ['body' => 'Schaut mal', 'attachment_ids' => [$doc['id'], $video['id']]])->assertCreated();

        $this->assertStringStartsWith('attachment', $this->loginAs($this->trainer)->get($doc['url'])->headers->get('Content-Disposition'));
        $this->loginAs($this->trainer)->get($video['url'], ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Length', '100');
    }

    public function test_uploads_never_sent_are_removed_after_a_day(): void
    {
        $this->upload($this->paul, $this->contentFile('a.txt', 'Notiz zum Training'))->assertCreated();
        $this->artisan('app:cleanup-chat-uploads')->assertSuccessful();
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => ChatAttachment::count()));

        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->artisan('app:cleanup-chat-uploads')->assertSuccessful();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => ChatAttachment::count() + StoredFile::count()));
    }
}
