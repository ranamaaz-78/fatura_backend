<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\DocumentNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Numbering: how invoices are numbered. Either the built-in numbers (F-202610/00001, starting again every
 * month) or the company's own: it types one number ("ALP-00234") and every next invoice continues from it. It only
 * shapes the numbers still to come; documents already issued keep theirs.
 */
class DocumentSeriesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumber $numbers) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->payload((int) $request->user()->company_id));
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'manual'])],
            'next_number' => ['required_if:mode,manual', 'nullable', 'string', 'max:30'],
        ]);

        DB::transaction(function () use ($company, $data) {
            if ($data['mode'] === 'auto') {
                $this->numbers->setAutomatic($company);

                return;
            }

            try {
                $this->numbers->setManual($company, (string) $data['next_number']);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['next_number' => $error->getMessage()]);
            }
        });

        return $this->success($this->payload((int) $company->id), __('Numbering saved.'));
    }

    /** @return array<string, mixed> */
    private function payload(int $companyId): array
    {
        $manual = $this->numbers->manual($companyId);
        $period = (int) now()->format('Ym');

        return [
            'mode' => $manual === null ? 'auto' : 'manual',
            // The number the next invoice gets, and (own numbers) the last one handed out.
            'next_number' => $this->numbers->peek($companyId, 'factura', $period),
            'last_number' => $manual !== null && $this->numbers->lastManual($companyId) > 0
                ? $this->numbers->formatManual($manual['prefix'], $manual['width'], $this->numbers->lastManual($companyId))
                : null,
            // What the built-in numbering looks like, for the example on the screen.
            'automatic_example' => $this->numbers->format('factura', $period, 1),
        ];
    }
}
