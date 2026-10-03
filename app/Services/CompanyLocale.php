<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\PrintTemplate;
use App\Support\Locales;

/** Changing a company's language, and carrying along the starter text it was created with. */
class CompanyLocale
{
    public function change(Company $company, string $to): Company
    {
        $from = Locales::normalize($company->locale) ?? Locales::DEFAULT;

        $company->update(['locale' => $to]);

        if ($from !== $to) {
            $this->translateDefaults($company, $from, $to);
        }

        return $company->fresh();
    }

    /**
     * Only starter text that nobody has touched is translated. Anything the company edited, or any name it
     * made up, stays exactly as written.
     */
    private function translateDefaults(Company $company, string $from, string $to): void
    {
        foreach (PrintTemplate::withoutGlobalScopes()->where('company_id', $company->id)->get() as $template) {
            $before = PrintTemplate::defaultFor($template->type, $from)['footer_notes'];

            if ($template->footer_notes === $before) {
                $template->update(['footer_notes' => PrintTemplate::defaultFor($template->type, $to)['footer_notes']]);
            }
        }

        foreach (CompanyPaymentMethod::DEFAULTS as $row) {
            $oldName = __($row['name'], [], $from);
            $newName = __($row['name'], [], $to);

            if ($oldName === $newName) {
                continue;
            }

            $methods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $company->id);

            if (! (clone $methods)->where('name', $newName)->exists()) {
                (clone $methods)->where('name', $oldName)->update(['name' => $newName]);
            }
        }
    }
}
