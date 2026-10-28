<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\DocumentCounter;
use App\Services\DocumentNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Numbering: the letters each kind of document starts with and the number each month begins from. It
 * only shapes the numbers still to come; documents already issued keep theirs.
 */
class DocumentSeriesController extends Controller
{
    use ApiResponse;

    /** The credit note (abono) is no longer issued, but its letters stay taken so no new series can reuse them. */
    private const RESERVED = ['AB'];

    public function __construct(private readonly DocumentNumber $numbers) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->payload((int) $request->user()->company_id));
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 404);

        $rules = [];
        foreach (DocumentNumber::SERIES_KINDS as $kind) {
            $rules["series.{$kind}.prefix"] = ['required', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/'];
            // "start" is where every month begins; "first" is where this month begins (it can be the same).
            $rules["series.{$kind}.start"] = ['required', 'integer', 'min:1', 'max:9999999'];
            $rules["series.{$kind}.first"] = ['required', 'integer', 'min:1', 'max:9999999'];
        }
        $data = $request->validate(['series' => ['required', 'array']] + $rules, [
            'series.*.prefix.regex' => __('Use 1 to 10 letters or numbers, with no spaces or symbols.'),
        ]);

        // Each kind needs letters of its own, or two kinds could end up with the same number (INV-202610/00001 twice).
        $seen = array_map('strtoupper', self::RESERVED);
        $period = (int) now()->format('Ym');
        $series = [];
        foreach (DocumentNumber::SERIES_KINDS as $kind) {
            $prefix = strtoupper($data['series'][$kind]['prefix']);

            if (in_array($prefix, $seen, true)) {
                throw ValidationException::withMessages(["series.{$kind}.prefix" => __('Another document already uses these letters. Pick different ones.')]);
            }

            $seen[] = $prefix;
            $start = (int) $data['series'][$kind]['start'];
            $first = (int) $data['series'][$kind]['first'];
            $series[$kind] = ['prefix' => $prefix, 'start' => $start];

            // A different first number is kept for this month only; the same as the start needs no special case.
            if ($first !== $start) {
                $series[$kind] += ['first' => $first, 'first_period' => $period];
            }
        }

        $company->update(['document_series' => $series]);

        return $this->success($this->payload((int) $company->id), __('Numbering saved.'));
    }

    /** @return array{series: list<array<string, mixed>>} */
    private function payload(int $companyId): array
    {
        $period = (int) now()->format('Ym');

        return [
            'series' => array_map(function (string $kind) use ($companyId, $period) {
                $series = $this->numbers->series($companyId, $kind);
                $last = (int) (DocumentCounter::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('kind', $kind)
                    ->where('year', $period)
                    ->value('last_number') ?? 0);

                return [
                    'kind' => $kind,
                    'prefix' => $series['prefix'],
                    // Where this month begins, and where every month begins.
                    'first' => $this->numbers->startFor($series, $period),
                    'start' => $series['start'],
                    'default_prefix' => $this->numbers->defaultPrefix($kind),
                    // How many of this kind went out this month, and the number the next one gets.
                    'issued_this_month' => $last,
                    'next_number' => $this->numbers->peek($companyId, $kind, $period),
                ];
            }, DocumentNumber::SERIES_KINDS),
        ];
    }
}
