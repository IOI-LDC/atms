<?php

namespace Tests\Feature\Attachments;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Office documents must upload successfully.
 *
 * Regression test for a 422 on every .doc/.docx/.xls/.xlsx upload: content
 * sniffing reports OOXML files as `application/zip` (they are ZIP containers)
 * and legacy Office files as an OLE2 compound-document type, neither of which
 * appeared in the flat allowed-MIME list — while both upload dialogs advertised
 * those extensions as accepted.
 *
 * These fixtures carry the real magic bytes rather than a faked MIME string, so
 * they exercise the same detection path a browser upload does.
 */
class OfficeAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config(['atms.attachment_disk' => 'attachments']);
        Storage::fake('attachments');
    }

    private function creator(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::TECHNICIAN)->first()->id,
            'is_active' => true,
        ]);
    }

    private function maintenanceRequest(User $creator): MaintenanceRequest
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-OFF-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);

        return MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Test',
            'created_by' => $creator->id,
            'is_preventive' => false,
        ]);
    }

    /**
     * A file whose bytes really begin with the given signature, written to a
     * temp path so Symfony and finfo both sniff it as a browser upload would.
     */
    private function realFile(string $name, string $magic): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att').'-'.$name;
        file_put_contents($path, $magic.str_repeat("\x00", 512));

        return new UploadedFile($path, $name, null, null, true);
    }

    private const ZIP_MAGIC = "PK\x03\x04\x14\x00\x00\x00\x08\x00";

    private const OLE2_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * @return array<string, array{string, string}>
     */
    public static function officeFileProvider(): array
    {
        return [
            'docx (zip container)' => ['report.docx', self::ZIP_MAGIC],
            'xlsx (zip container)' => ['sheet.xlsx', self::ZIP_MAGIC],
        ];
    }

    #[DataProvider('officeFileProvider')]
    public function test_office_documents_upload_successfully(string $name, string $magic): void
    {
        $creator = $this->creator();
        $mr = $this->maintenanceRequest($creator);

        $this->actingAs($creator)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
                'file' => $this->realFile($name, $magic),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('attachments', ['original_name' => $name]);
    }

    public function test_pdf_still_uploads(): void
    {
        $creator = $this->creator();
        $mr = $this->maintenanceRequest($creator);

        $this->actingAs($creator)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
                'file' => $this->realFile('manual.pdf', "%PDF-1.4\n"),
            ])
            ->assertCreated();
    }

    /**
     * The extension whitelist stays the boundary: a ZIP container is accepted
     * only for the extensions that legitimately are ZIP containers.
     */
    public function test_a_zip_renamed_to_an_image_extension_is_rejected(): void
    {
        $creator = $this->creator();
        $mr = $this->maintenanceRequest($creator);

        $this->actingAs($creator)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
                'file' => $this->realFile('payload.png', self::ZIP_MAGIC),
            ])
            ->assertStatus(422);
    }

    public function test_a_disallowed_extension_is_still_rejected(): void
    {
        $creator = $this->creator();
        $mr = $this->maintenanceRequest($creator);

        $response = $this->actingAs($creator)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
                'file' => $this->realFile('script.sh', "#!/bin/sh\n"),
            ])
            ->assertStatus(422);

        // The message names the file and lists what is accepted, so a failed
        // multi-file upload says which file was the problem.
        $this->assertStringContainsString('script.sh', $response->json('message'));
        $this->assertStringContainsString('pdf', $response->json('message'));
    }

    public function test_rejection_message_names_the_offending_file(): void
    {
        $creator = $this->creator();
        $mr = $this->maintenanceRequest($creator);

        $response = $this->actingAs($creator)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
                'file' => $this->realFile('holiday.png', self::OLE2_MAGIC),
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('holiday.png', $response->json('message'));
    }
}
