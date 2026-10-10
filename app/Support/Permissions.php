<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** The permissions a company's team members can be given (see config/permissions.php). */
final class Permissions
{
    /** The roles an owner can start a member from, plus "custom" once the ticks no longer match one. */
    public const CUSTOM = 'custom';

    /** @return array<string, list<string>> area => actions that exist for it */
    public static function modules(): array
    {
        return config('permissions.modules');
    }

    /** @return list<string> every "area.action" there is */
    public static function all(): array
    {
        $all = [];

        foreach (self::modules() as $module => $actions) {
            foreach ($actions as $action) {
                $all[] = "{$module}.{$action}";
            }
        }

        return $all;
    }

    /** @return list<string> */
    public static function presetKeys(): array
    {
        return array_keys(config('permissions.presets'));
    }

    /** @return list<string> */
    public static function preset(string $key): array
    {
        return config("permissions.presets.{$key}", []);
    }

    /** @return array<string, list<string>> the standard roles */
    public static function presets(): array
    {
        return config('permissions.presets');
    }

    /**
     * The roles as this company has them: the standard permissions, except where the owner saved their own
     * version of a role.
     *
     * @return array<string, list<string>>
     */
    public static function presetsFor(?\App\Models\Company $company): array
    {
        $presets = self::presets();

        foreach ((array) ($company?->role_presets ?? []) as $key => $list) {
            if (array_key_exists($key, $presets) && is_array($list)) {
                $presets[$key] = self::normalize($list);
            }
        }

        return $presets;
    }

    /** @return list<string> the roles this company has changed from the standard */
    public static function customizedFor(?\App\Models\Company $company): array
    {
        return array_values(array_intersect(self::presetKeys(), array_keys((array) ($company?->role_presets ?? []))));
    }

    /**
     * Permissions saved under the old names, brought to the current ones: "update" on a document became "pay"
     * (it was marking paid), "delete" became "void". Ones that no longer exist are dropped.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public static function migrateLegacy(array $permissions): array
    {
        $moved = [];

        foreach ($permissions as $permission) {
            $moved[] = match ($permission) {
                'invoices.update' => 'invoices.pay',
                'invoices.delete' => 'invoices.void',
                'delivery_notes.update' => 'delivery_notes.pay',
                'delivery_notes.delete' => 'delivery_notes.void',
                'proformas.update' => 'proformas.pay',
                'payments.update' => 'invoices.pay',
                default => $permission,
            };

            if ($permission === 'payments.update') {
                array_push($moved, 'delivery_notes.pay', 'proformas.pay');
            }
        }

        return self::normalize($moved);
    }

    /** Only real permissions, each once, in catalogue order. */
    public static function normalize(array $permissions): array
    {
        return array_values(array_intersect(self::all(), array_map('strval', $permissions)));
    }

    /** Which role the ticks amount to: a role when they match it exactly (as this company has it), otherwise "custom". */
    public static function roleFor(array $permissions, ?\App\Models\Company $company = null): string
    {
        $given = self::normalize($permissions);

        foreach (self::presetsFor($company) as $key => $list) {
            if (self::normalize($list) === $given) {
                return $key;
            }
        }

        return self::CUSTOM;
    }

    /** The area a document type belongs to ("quotation" is "quotes"). */
    public static function forDocument(string $type): string
    {
        return config("permissions.documents.{$type}", 'invoices');
    }

    public static function denied(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('You do not have permission to do this.'),
            'data' => [],
            'code' => 'FORBIDDEN_PERMISSION',
        ], 403);
    }
}
