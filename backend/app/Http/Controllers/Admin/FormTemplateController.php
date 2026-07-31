<?php

namespace App\Http\Controllers\Admin;

use App\Actions\FormTemplates\AddFormField;
use App\Actions\FormTemplates\CreateFormTemplate;
use App\Actions\FormTemplates\DeactivateFormTemplate;
use App\Actions\FormTemplates\DeleteFormField;
use App\Actions\FormTemplates\ReactivateFormTemplate;
use App\Actions\FormTemplates\ReorderFormFields;
use App\Actions\FormTemplates\UpdateFormField;
use App\Actions\FormTemplates\UpdateFormTemplate;
use App\Enums\FormFieldType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FormTemplateFieldResource;
use App\Http\Resources\FormTemplateResource;
use App\Models\FormTemplate;
use App\Models\FormTemplateField;
use App\Queries\FormTemplates\FormTemplateIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FormTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', FormTemplate::class);

        $results = app(FormTemplateIndexQuery::class)->build($request);

        return FormTemplateResource::collection($results)->toResponse($request);
    }

    public function store(Request $request, CreateFormTemplate $action): JsonResponse
    {
        Gate::authorize('create', FormTemplate::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // The unique check returns a clean 422 before the partial unique
            // index can raise a 500.
            'fa_subclass_code' => [
                'required',
                'string',
                'exists:fa_subclass_type_codes,fa_subclass_code',
                Rule::unique('form_templates', 'fa_subclass_code')->where(fn ($q) => $q->where('is_active', true)),
            ],
        ]);

        $template = $action->execute($validated, $request->user()->id);

        return (new FormTemplateResource($template))->toResponse($request)->setStatusCode(201);
    }

    public function show(Request $request, FormTemplate $template): JsonResponse
    {
        Gate::authorize('view', $template);

        $template->load('fields');

        return (new FormTemplateResource($template))->toResponse($request);
    }

    public function update(Request $request, FormTemplate $template, UpdateFormTemplate $action): JsonResponse
    {
        Gate::authorize('update', $template);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $template = $action->execute($template, $validated, $request->user()->id);

        return (new FormTemplateResource($template))->toResponse($request);
    }

    public function deactivate(Request $request, FormTemplate $template, DeactivateFormTemplate $action): JsonResponse
    {
        Gate::authorize('deactivate', $template);

        try {
            $template = $action->execute($template, $request->user()->id);

            return (new FormTemplateResource($template))->toResponse($request);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reactivate(Request $request, FormTemplate $template, ReactivateFormTemplate $action): JsonResponse
    {
        Gate::authorize('reactivate', $template);

        try {
            $template = $action->execute($template, $request->user()->id);

            return (new FormTemplateResource($template))->toResponse($request);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function addField(Request $request, FormTemplate $template, AddFormField $action): JsonResponse
    {
        Gate::authorize('addField', $template);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'string', Rule::enum(FormFieldType::class)],
            'has_pre_post' => ['sometimes', 'boolean'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $field = $action->execute($template, $validated, $request->user()->id);

        return (new FormTemplateFieldResource($field))->toResponse($request)->setStatusCode(201);
    }

    public function updateField(Request $request, FormTemplate $template, FormTemplateField $field, UpdateFormField $action): JsonResponse
    {
        Gate::authorize('updateField', $template);

        if (! $this->fieldBelongsToTemplate($field, $template)) {
            return response()->json(['message' => 'Field not found for this template.'], 404);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'field_type' => ['sometimes', 'string', Rule::enum(FormFieldType::class)],
            'has_pre_post' => ['sometimes', 'boolean'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_required' => ['sometimes', 'boolean'],
        ]);

        $field = $action->execute($field, $validated, $request->user()->id);

        return (new FormTemplateFieldResource($field))->toResponse($request);
    }

    public function deleteField(Request $request, FormTemplate $template, FormTemplateField $field, DeleteFormField $action): JsonResponse
    {
        Gate::authorize('deleteField', $template);

        if (! $this->fieldBelongsToTemplate($field, $template)) {
            return response()->json(['message' => 'Field not found for this template.'], 404);
        }

        $action->execute($field, $request->user()->id);

        return response()->json(['message' => 'Field deleted.']);
    }

    public function reorderFields(Request $request, FormTemplate $template, ReorderFormFields $action): JsonResponse
    {
        Gate::authorize('reorderFields', $template);

        $validated = $request->validate([
            'field_ids' => ['required', 'array'],
            'field_ids.*' => ['required', 'integer', 'exists:form_template_fields,id'],
        ]);

        $template = $action->execute($template, $validated['field_ids'], $request->user()->id);

        return (new FormTemplateResource($template))->toResponse($request);
    }

    /**
     * Validate that the field actually belongs to the route-bound template
     * (nested binding guard; 404 on mismatch).
     */
    protected function fieldBelongsToTemplate(FormTemplateField $field, FormTemplate $template): bool
    {
        return $field->form_template_id === $template->id;
    }
}
