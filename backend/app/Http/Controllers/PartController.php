<?php

namespace App\Http\Controllers;

use App\Actions\Parts\ImportPartQuantities;
use App\Actions\Parts\UpdatePart;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Queries\Parts\PartIndexQuery;
use App\Support\Reports\CsvReportStreamer;
use App\Support\SizeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Part::class);

        $request->validate([
            'compatible_with_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);

        $results = app(PartIndexQuery::class)->build($request);

        return PartResource::collection($results)->toResponse($request);
    }

    /**
     * RQ3 — the parts list as CSV, for offline reconciliation against the ERP.
     *
     * Carries three keys on purpose: `part_id` is what the upload matches on
     * (Q8), `erp_part_id` is what the operator VLOOKUPs the ERP export against,
     * and `erp_part_code` is the Part No. they actually read. Every other column
     * is context and is ignored on the way back in.
     *
     * Includes inactive parts — a physical stock count does not stop at the
     * catalogue edge, and Excel can filter them out.
     */
    public function exportCsv(Request $request, CsvReportStreamer $streamer): StreamedResponse
    {
        Gate::authorize('importQuantities', Part::class);

        return $streamer->stream('parts', [
            'part_id' => 'id',
            'erp_part_id' => 'erp_part_id',
            'erp_part_code' => 'erp_part_code',
            'name' => 'name',
            'unit_of_measure' => 'unit_of_measure',
            'erp_status' => 'erp_status',
            // Explicit rather than the streamer's Yes/No, so the column reads
            // the same as the API and nobody has to guess which spelling the
            // upload wants. (It wants neither — the column is ignored.)
            'is_active' => fn (Part $p) => $p->is_active ? 'true' : 'false',
            // Written exactly as stored, numeric(14,3). Trimming trailing zeros
            // would mean parsing the value, and the only obvious way to do that
            // routes a stored decimal through a float.
            'available_quantity' => 'available_quantity',
        ], Part::query()->orderBy('id')->lazy());
    }

    /**
     * RQ3 — apply corrected quantities from the edited CSV.
     *
     * All-or-nothing: a rejected file writes nothing and reports every problem
     * with its line number, because the operator's next move is to fix the
     * spreadsheet and retry.
     */
    public function importQuantities(Request $request, ImportPartQuantities $action): JsonResponse
    {
        Gate::authorize('importQuantities', Part::class);

        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        try {
            $result = $action->execute($validated['file'], $request->user()->id);

            return response()->json([
                'message' => "Quantities applied: {$result['updated']} updated, {$result['unchanged']} unchanged.",
                'data' => $result,
            ]);
        } catch (\DomainException $e) {
            $errors = explode("\n", $e->getMessage());

            return response()->json([
                'message' => 'The file was rejected. Nothing was changed.',
                // First 40 mirrors the CLI import's display rule — a wholly
                // mis-keyed file produces one error per row, and a 700-line
                // response helps nobody.
                'errors' => array_slice($errors, 0, 40),
                'error_count' => count($errors),
            ], 422);
        }
    }

    public function show(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('view', $part);

        return (new PartResource($part->load('maintenanceCategory')))->toResponse($request);
    }

    public function update(Request $request, Part $part, UpdatePart $action): JsonResponse
    {
        Gate::authorize('update', $part);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'available_quantity' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
            'maintenance_category_id' => ['sometimes', 'nullable', 'integer', 'exists:maintenance_categories,id'],
            'size_inches' => ['sometimes', 'nullable', new SizeRule],
            'description' => ['nullable', 'string'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip([
                'name',
                'available_quantity',
                'maintenance_category_id',
                'size_inches',
                'description',
                'unit_of_measure',
                'is_active',
            ])
        );

        $part = $action->execute($part, $fieldUpdates);

        return (new PartResource($part->fresh()->load('maintenanceCategory')))->toResponse($request);
    }
}
