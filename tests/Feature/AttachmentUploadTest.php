<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use App\Rules\AllowedAttachmentFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::query()->where('key', 'resend')->first()
            ?? MailProvider::factory()->create(['key' => 'resend', 'driver' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_can_upload_pdf_attachment(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk()
            ->assertJsonStructure(['id', 'filename', 'content_type', 'size', 'path', 'disk']);

        $this->assertSame('document.pdf', $response->json('filename'));
        $this->assertSame('application/pdf', $response->json('content_type'));
    }

    public function test_can_upload_image_attachment(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

        $response = $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk();

        $this->assertSame('photo.jpg', $response->json('filename'));
    }

    public function test_can_upload_docx_attachment(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create(
            'report.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk()
            ->assertJsonFragment(['filename' => 'report.docx']);
    }

    public function test_rejects_executable_files(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_javascript_files(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('script.js', 100, 'text/javascript');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_php_files(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('shell.php', 100, 'text/x-php');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_html_files(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('page.html', 100, 'text/html');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_svg_files(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('logo.svg', 100, 'image/svg+xml');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_shell_scripts(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('deploy.sh', 100, 'application/x-sh');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rejects_files_over_10mb(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_accepts_files_at_10mb_limit(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('exact.pdf', 10240, 'application/pdf');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk();
    }

    public function test_file_stored_in_correct_organization_path(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create('test.pdf', 50, 'application/pdf');

        $response = $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk();

        $path = $response->json('path');
        $this->assertStringContainsString("attachments/tmp/{$org->id}/", $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');

        $this->post(route('attachments.store'), ['file' => $file])
            ->assertRedirect(route('login'));
    }

    #[DataProvider('allowedExtensionsProvider')]
    public function test_allowed_extensions_are_accepted(string $extension, string $mime): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create("file.{$extension}", 50, $mime);

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allowedExtensionsProvider(): array
    {
        return [
            'pdf' => ['pdf', 'application/pdf'],
            'doc' => ['doc', 'application/msword'],
            'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['xls', 'application/vnd.ms-excel'],
            'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'txt' => ['txt', 'text/plain'],
            'csv' => ['csv', 'text/csv'],
            'jpg' => ['jpg', 'image/jpeg'],
            'png' => ['png', 'image/png'],
            'gif' => ['gif', 'image/gif'],
            'zip' => ['zip', 'application/zip'],
            'mp3' => ['mp3', 'audio/mpeg'],
            'mp4' => ['mp4', 'video/mp4'],
        ];
    }

    #[DataProvider('blockedExtensionsProvider')]
    public function test_blocked_extensions_are_rejected(string $extension): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();

        $file = UploadedFile::fake()->create("file.{$extension}", 50, 'application/octet-stream');

        $this->as($user, $org)
            ->post(route('attachments.store'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedExtensionsProvider(): array
    {
        return [
            'exe' => ['exe'],
            'bat' => ['bat'],
            'js' => ['js'],
            'php' => ['php'],
            'sh' => ['sh'],
            'py' => ['py'],
            'html' => ['html'],
            'svg' => ['svg'],
            'dll' => ['dll'],
            'vbs' => ['vbs'],
            'ps1' => ['ps1'],
        ];
    }

    public function test_rule_constants_are_defined(): void
    {
        $this->assertNotEmpty(AllowedAttachmentFile::ALLOWED_EXTENSIONS);
        $this->assertNotEmpty(AllowedAttachmentFile::ALLOWED_MIME_TYPES);
        $this->assertNotEmpty(AllowedAttachmentFile::BLOCKED_EXTENSIONS);
        $this->assertContains('pdf', AllowedAttachmentFile::ALLOWED_EXTENSIONS);
        $this->assertContains('exe', AllowedAttachmentFile::BLOCKED_EXTENSIONS);
    }
}
