<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Brings clients or suppliers in from a spreadsheet, the way ProductImportService brings products in: every row is
 * checked first and nothing is written unless all of them pass, so a red row can never slip into the book.
 */
class ContactImportService
{
    private const MAX = [
        'name' => 180,
        'company_name' => 180,
        'phone' => 40,
        'email' => 190,
        'nif' => 32,
        'address' => 255,
        'remarks' => 2000,
    ];

    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, errors: array<int, array{row: int, messages: array<int, string>}>}
     */
    public function importCustomers(int $companyId, array $rows): array
    {
        $taken = [];

        foreach (Customer::withoutGlobalScopes()->where('company_id', $companyId)->get(['name', 'phone']) as $existing) {
            $digits = $this->digits($existing->phone);

            if ($digits !== '') {
                $taken[$digits] ??= $existing->name;
            }
        }

        $seen = [];

        return $this->run($companyId, $rows, ['name', 'company_name', 'phone', 'email', 'nif', 'address', 'remarks'], function (array $data, array &$messages) use ($taken, &$seen) {
            $digits = $this->digits($data['phone']);

            if ($digits === '') {
                return;
            }

            // A number is one client: the sale screen refuses a second client on a saved number too.
            if (isset($seen[$digits])) {
                $messages[] = __('This phone number is repeated in the file.');
            } elseif (isset($taken[$digits])) {
                $messages[] = __('This phone number is already saved for :name.', ['name' => $taken[$digits]]);
            }

            $seen[$digits] = true;
        }, fn (int $companyId, array $data) => Customer::withoutGlobalScopes()->create([
            ...$data,
            'company_id' => $companyId,
            'nie' => null,
            'code' => $this->numbers->take($companyId, 'client'),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, errors: array<int, array{row: int, messages: array<int, string>}>}
     */
    public function importSuppliers(int $companyId, array $rows): array
    {
        $taken = [];

        foreach (Supplier::withoutGlobalScopes()->where('company_id', $companyId)->get(['name', 'company_name']) as $existing) {
            foreach ([$existing->name, $existing->company_name] as $label) {
                if (is_string($label) && trim($label) !== '') {
                    $taken[mb_strtolower(trim($label))] = true;
                }
            }
        }

        $seen = [];

        // A supplier is found by name when products are imported, so two with the same name would be ambiguous there.
        return $this->run($companyId, $rows, ['name', 'company_name', 'phone', 'email', 'nif', 'remarks'], function (array $data, array &$messages) use ($taken, &$seen) {
            $key = mb_strtolower($data['name']);

            if ($key === '') {
                return;
            }

            if (isset($seen[$key])) {
                $messages[] = __('This name is repeated in the file.');
            } elseif (isset($taken[$key])) {
                $messages[] = __('A supplier with this name already exists.');
            }

            $seen[$key] = true;
        }, fn (int $companyId, array $data) => Supplier::withoutGlobalScopes()->create([
            ...$data,
            'company_id' => $companyId,
            'nie' => null,
            'code' => $this->numbers->take($companyId, 'supplier'),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @param  callable(array<string, ?string>, array<int, string>): void  $duplicates  adds to the messages when the row repeats a saved or earlier one
     * @param  callable(int, array<string, ?string>): mixed  $create
     * @return array{created: int, errors: array<int, array{row: int, messages: array<int, string>}>}
     */
    private function run(int $companyId, array $rows, array $fields, callable $duplicates, callable $create): array
    {
        $prepared = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $messages = [];
            $data = [];

            foreach ($fields as $field) {
                $data[$field] = $this->text($row[$field] ?? null);
            }

            if ($data['name'] === null) {
                $messages[] = __('Name is required.');
            }

            foreach (self::MAX as $field => $max) {
                if (array_key_exists($field, $data) && $data[$field] !== null && mb_strlen($data[$field]) > $max) {
                    $messages[] = __(':field is too long (at most :max characters).', ['field' => $this->label($field), 'max' => $max]);
                }
            }

            if ($data['email'] !== null) {
                $data['email'] = mb_strtolower($data['email']);

                if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $messages[] = __('Email is not valid.');
                }
            }

            // A tax number is written in capitals, as the forms do.
            if ($data['nif'] !== null) {
                $data['nif'] = mb_strtoupper($data['nif']);
            }

            $data['name'] ??= '';
            $duplicates($data, $messages);

            if ($messages !== []) {
                $errors[] = ['row' => $index, 'messages' => $messages];

                continue;
            }

            $prepared[] = $data;
        }

        if ($errors !== []) {
            return ['created' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($companyId, $prepared, $create) {
            foreach ($prepared as $data) {
                $create($companyId, $data);
            }
        });

        return ['created' => count($prepared), 'errors' => []];
    }

    private function label(string $field): string
    {
        return match ($field) {
            'name' => __('Name'),
            'company_name' => __('Company name'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'nif' => __('Tax ID'),
            'address' => __('Address'),
            default => __('Remarks'),
        };
    }

    /** A phone number as it is compared: no spaces or dashes, so "612 000-111" and "612000111" are one number. */
    private function digits(?string $phone): string
    {
        return preg_replace('/[\s-]+/', '', (string) $phone) ?? '';
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
