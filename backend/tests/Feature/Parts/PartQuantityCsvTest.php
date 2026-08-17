<?php

namespace Tests\Feature\Parts;

use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RQ3 — the parts CSV round trip.
 *
 * The workflow is offline and runs through Excel: download from ATMS, VLOOKUP
 * the ERP's quantities onto it, upload the result. So the tests that matter are
 * the ones about spreadsheet failure modes — a shifted lookup, a stray BOM, a
 * re-uploaded unedited file — rather than about API shapes.
 */
class PartQuantityCsvTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->user(RoleCode::ADMINISTRATOR);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function part(string $code, string $quantity = '10.000', bool $active = true): Part
    {
        return Part::create([
            'erp_part_id' => (string) Str::uuid(),
            'erp_part_code' => $code,
            'name' => 'Part '.$code,
            'unit_of_measure' => 'PCS',
            'available_quantity' => $quantity,
            'erp_status' => 'active',
            'is_active' => $active,
        ]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qty').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'quantities.csv', 'text/csv', null, true);
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/api/parts/import-quantities', ['file' => $file]);
    }

    private function header(): string
    {
        return "part_id,erp_part_code,available_quantity\n";
    }

    // ── Export ──────────────────────────────────────────────────────────────────

    public function test_the_export_carries_the_agreed_columns_in_order(): void
    {
        $this->part('AAA-1');

        $response = $this->actingAs($this->admin)->get('/api/parts/export-csv')->assertOk();
        $csv = $response->streamedContent();
        $firstLine = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $csv), "\n");

        $this->assertSame(
            'part_id,erp_part_id,erp_part_code,name,unit_of_measure,erp_status,is_active,available_quantity',
            trim($firstLine),
        );
    }

    /**
     * A physical stock count does not stop at the catalogue edge — an inactive
     * part still occupies shelf space.
     */
    public function test_the_export_includes_inactive_parts(): void
    {
        $this->part('LIVE-1');
        $this->part('DEAD-1', active: false);

        $csv = $this->actingAs($this->admin)->get('/api/parts/export-csv')->assertOk()->streamedContent();

        $this->assertStringContainsString('LIVE-1', $csv);
        $this->assertStringContainsString('DEAD-1', $csv);
    }

    /** Stored precision, verbatim — trimming would mean parsing, and parsing invites a float. */
    public function test_quantities_export_at_their_stored_precision(): void
    {
        $this->part('PREC-1', '12.500');

        $csv = $this->actingAs($this->admin)->get('/api/parts/export-csv')->assertOk()->streamedContent();

        $this->assertStringContainsString('12.500', $csv);
    }

    // ── The round trip ──────────────────────────────────────────────────────────

    /**
     * The test the whole design rests on: an unedited download must apply as
     * zero changes. If `isDirty` mishandles the decimal cast this reports the
     * entire catalogue as corrected, which would destroy the operator's trust in
     * the counts the moment they tried it.
     */
    public function test_re_uploading_an_unedited_export_changes_nothing(): void
    {
        $a = $this->part('RT-1', '10.000');
        $b = $this->part('RT-2', '0.500');

        $export = $this->actingAs($this->admin)->get('/api/parts/export-csv')->assertOk()->streamedContent();

        $body = $this->header();
        foreach ([$a, $b] as $p) {
            $body .= "{$p->id},{$p->erp_part_code},{$p->available_quantity}\n";
        }

        $this->upload($this->admin, $this->csv($body))
            ->assertOk()
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unchanged', 2);

        $this->assertSame('10.000', $a->fresh()->available_quantity);
        $this->assertSame('0.500', $b->fresh()->available_quantity);
        $this->assertNotEmpty($export);
    }

    public function test_corrected_quantities_are_applied_wholesale(): void
    {
        $a = $this->part('APP-1', '10.000');
        $b = $this->part('APP-2', '5.000');

        $body = $this->header()."{$a->id},APP-1,42.250\n";

        $this->upload($this->admin, $this->csv($body))
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame('42.250', $a->fresh()->available_quantity);
        $this->assertSame('5.000', $b->fresh()->available_quantity, 'A part absent from the file is untouched.');
    }

    public function test_extra_columns_are_ignored(): void
    {
        $a = $this->part('EXT-1', '1.000');
        $body = "part_id,erp_part_code,name,helper_column,available_quantity\n"
            ."{$a->id},EXT-1,Whatever,=VLOOKUP(A2),9.000\n";

        $this->upload($this->admin, $this->csv($body))->assertOk();

        $this->assertSame('9.000', $a->fresh()->available_quantity);
    }

    /** Excel writes a BOM onto the first header, including into our own export. */
    public function test_a_utf8_bom_on_the_header_does_not_break_the_upload(): void
    {
        $a = $this->part('BOM-1', '1.000');
        $body = "\xEF\xBB\xBF".$this->header()."{$a->id},BOM-1,7.000\n";

        $this->upload($this->admin, $this->csv($body))->assertOk();

        $this->assertSame('7.000', $a->fresh()->available_quantity);
    }

    public function test_inactive_parts_accept_corrections(): void
    {
        $a = $this->part('INA-1', '1.000', active: false);

        $this->upload($this->admin, $this->csv($this->header()."{$a->id},INA-1,3.000\n"))->assertOk();

        $this->assertSame('3.000', $a->fresh()->available_quantity);
    }

    // ── Rejection: all-or-nothing, nothing written ──────────────────────────────

    public function test_an_unknown_part_id_rejects_the_whole_file(): void
    {
        $a = $this->part('OK-1', '1.000');
        $body = $this->header()."{$a->id},OK-1,5.000\n999999,GHOST,7.000\n";

        $this->upload($this->admin, $this->csv($body))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file was rejected. Nothing was changed.');

        $this->assertSame('1.000', $a->fresh()->available_quantity, 'The valid row must not have landed.');
    }

    public function test_a_duplicate_part_id_is_rejected(): void
    {
        $a = $this->part('DUP-1', '1.000');
        $body = $this->header()."{$a->id},DUP-1,5.000\n{$a->id},DUP-1,6.000\n";

        $response = $this->upload($this->admin, $this->csv($body))->assertStatus(422);

        $this->assertStringContainsString('duplicate part_id', $response->json('errors.0'));
        $this->assertSame('1.000', $a->fresh()->available_quantity);
    }

    /** The shifted-VLOOKUP catch: right id column, wrong row's code. */
    public function test_a_mismatched_part_code_is_rejected(): void
    {
        $a = $this->part('REAL-1', '1.000');
        $body = $this->header()."{$a->id},SOMEONE-ELSE,5.000\n";

        $response = $this->upload($this->admin, $this->csv($body))->assertStatus(422);

        $this->assertStringContainsString('SOMEONE-ELSE', $response->json('errors.0'));
        $this->assertSame('1.000', $a->fresh()->available_quantity);
    }

    /** A blank code is a dropped column, not a shifted lookup. */
    public function test_a_blank_part_code_skips_the_cross_check(): void
    {
        $a = $this->part('BLANK-1', '1.000');

        $this->upload($this->admin, $this->csv($this->header()."{$a->id},,4.000\n"))->assertOk();

        $this->assertSame('4.000', $a->fresh()->available_quantity);
    }

    public function test_the_cross_check_ignores_case_and_padding(): void
    {
        $a = $this->part('Case-1', '1.000');

        $this->upload($this->admin, $this->csv($this->header()."{$a->id},  case-1  ,4.000\n"))->assertOk();

        $this->assertSame('4.000', $a->fresh()->available_quantity);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQuantityProvider(): array
    {
        return [
            'negative' => ['-5'],
            'four decimals' => ['1.2345'],
            'not a number' => ['twelve'],
            'blank' => [''],
            // Quoted, because that is how Excel writes a grouped number — bare
            // `1,200` is two CSV fields and never reaches the quantity check.
            'comma-grouped' => ['"1,200"'],
            'currency' => ['$12'],
        ];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function test_an_invalid_quantity_is_rejected(string $quantity): void
    {
        $a = $this->part('QTY-1', '1.000');

        $this->upload($this->admin, $this->csv($this->header()."{$a->id},QTY-1,{$quantity}\n"))
            ->assertStatus(422);

        $this->assertSame('1.000', $a->fresh()->available_quantity);
    }

    public function test_zero_is_a_valid_quantity(): void
    {
        $a = $this->part('ZERO-1', '9.000');

        $this->upload($this->admin, $this->csv($this->header()."{$a->id},ZERO-1,0\n"))->assertOk();

        $this->assertSame('0.000', $a->fresh()->available_quantity, 'A shelf can genuinely be empty.');
    }

    public function test_a_missing_required_header_is_rejected(): void
    {
        $a = $this->part('HDR-1');

        $this->upload($this->admin, $this->csv("part_id,available_quantity\n{$a->id},5\n"))
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'Missing required column(s): erp_part_code.');
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $this->upload($this->admin, $this->csv(''))->assertStatus(422);
    }

    public function test_a_header_only_file_is_rejected(): void
    {
        $this->upload($this->admin, $this->csv($this->header()))
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'The file has no data rows.');
    }

    /** A wholly mis-keyed file produces one error per row; the response caps them. */
    public function test_the_error_list_is_capped_but_the_count_is_not(): void
    {
        $body = $this->header();
        for ($i = 1; $i <= 45; $i++) {
            $body .= "90000{$i},GHOST,1.000\n";
        }

        $response = $this->upload($this->admin, $this->csv($body))->assertStatus(422);

        $this->assertCount(40, $response->json('errors'));
        $this->assertSame(45, $response->json('error_count'));
    }

    // ── Audit ───────────────────────────────────────────────────────────────────

    public function test_a_successful_upload_records_one_summary_event(): void
    {
        $a = $this->part('AUD-1', '1.000');
        $b = $this->part('AUD-2', '2.000');
        $body = $this->header()."{$a->id},AUD-1,5.000\n{$b->id},AUD-2,2.000\n";

        $this->upload($this->admin, $this->csv($body))->assertOk();

        $log = AuditLog::where('event', 'parts.quantity_upload.completed')->sole();
        $this->assertSame(2, $log->metadata['rows']);
        $this->assertSame(1, $log->metadata['updated']);
        $this->assertSame(1, $log->metadata['unchanged']);
        $this->assertSame('quantities.csv', $log->metadata['filename']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $log->metadata['file_sha256']);
    }

    public function test_a_rejected_upload_audits_nothing(): void
    {
        $this->upload($this->admin, $this->csv($this->header()."999999,GHOST,1.000\n"))->assertStatus(422);

        $this->assertSame(0, AuditLog::where('event', 'parts.quantity_upload.completed')->count());
    }

    // ── Authorization: narrower than PartPolicy::manage ─────────────────────────

    /**
     * @return array<string, array{RoleCode}>
     */
    public static function forbiddenRoleProvider(): array
    {
        return [
            // Admits `manage()`, but one upload rewrites the whole catalogue.
            'maintenance manager' => [RoleCode::MAINTENANCE_MANAGER],
            'technician' => [RoleCode::TECHNICIAN],
            'logistics' => [RoleCode::LOGISTICS],
            'requester' => [RoleCode::REQUESTER],
        ];
    }

    #[DataProvider('forbiddenRoleProvider')]
    public function test_only_an_administrator_may_export_or_upload(RoleCode $roleCode): void
    {
        $user = $this->user($roleCode);
        $part = $this->part('AUTH-1', '1.000');

        $this->actingAs($user)->get('/api/parts/export-csv')->assertForbidden();
        $this->upload($user, $this->csv($this->header()."{$part->id},AUTH-1,5.000\n"))->assertForbidden();

        $this->assertSame('1.000', $part->fresh()->available_quantity);
    }
}
