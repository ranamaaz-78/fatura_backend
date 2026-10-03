<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintTemplateResource;
use App\Models\PrintTemplate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrintTemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->payload($request));
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $template = $this->template($request, $type);
        $template->update($this->validated($request, $type));

        return $this->success($this->payload($request), __('Printable saved.'));
    }

    public function reset(Request $request, string $type): JsonResponse
    {
        $template = $this->template($request, $type);
        $template->update(PrintTemplate::defaultFor($type, $request->user()->company?->locale));

        return $this->success($this->payload($request), __('Printable reset.'));
    }

    public function copy(Request $request, string $type): JsonResponse
    {
        $source = $this->template($request, $type);
        $targets = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['required', 'string', Rule::in(array_values(array_diff(PrintTemplate::TYPES, [$type])))],
        ])['types'];

        $fields = $source->only(['primary_color', 'font_key', 'footer_notes', 'notes', 'show_logo', 'show_signature']);

        foreach ($targets as $target) {
            $payload = $fields;
            if ($target === 'albaran' || $type === 'albaran') {
                unset($payload['show_logo']);
            }
            if ($target === 'albaran') {
                $payload['show_logo'] = false;
            }
            $this->template($request, $target)->update($payload);
        }

        return $this->success($this->payload($request), __('Copied to the other printables.'));
    }

    /**
     * @return array{logo_url: string|null, templates: array<int, array<string, mixed>>}
     */
    private function payload(Request $request): array
    {
        $company = $request->user()->company;
        PrintTemplate::seedDefaults((int) $company->id, $company->locale);

        $order = array_flip(PrintTemplate::TYPES);
        $templates = PrintTemplate::query()
            ->whereIn('type', PrintTemplate::TYPES)
            ->get()
            ->sortBy(fn (PrintTemplate $row) => $order[$row->type] ?? 99)
            ->values();

        return [
            'logo_url' => $company?->logo_path ? '/app/company/logo' : null,
            'templates' => PrintTemplateResource::collection($templates)->resolve(),
        ];
    }

    private function template(Request $request, string $type): PrintTemplate
    {
        abort_unless(in_array($type, PrintTemplate::TYPES, true), 404);

        $companyId = (int) $request->user()->company_id;
        PrintTemplate::seedDefaults($companyId, $request->user()->company?->locale);

        return PrintTemplate::query()->where('type', $type)->firstOrFail();
    }

    /**
     * @return array{primary_color: string, font_key: string, footer_notes: string, notes?: string, show_logo: bool, show_signature: bool}
     */
    private function validated(Request $request, string $type): array
    {
        $data = $request->validate([
            'primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_key' => ['required', 'string', Rule::in(PrintTemplate::FONTS)],
            'footer_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'show_logo' => ['required', 'boolean'],
            'show_signature' => ['required', 'boolean'],
        ]);

        $data['primary_color'] = strtolower($data['primary_color']);
        $data['footer_notes'] = $data['footer_notes'] ?? '';

        // Left alone when the request does not mention it, so a client that only knows the
        // colour, font and terms cannot wipe the note.
        if (array_key_exists('notes', $data)) {
            $data['notes'] = trim((string) $data['notes']);
        }
        if ($type === 'albaran') {
            $data['show_logo'] = false;
        }

        return $data;
    }
}
