<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use BelongsToCompany;

    public const TYPES = ['factura', 'albaran', 'quotation', 'proforma'];

    public const FONTS = [
        'geist',
        'inter',
        'roboto',
        'open_sans',
        'lato',
        'montserrat',
        'poppins',
        'nunito',
        'nunito_sans',
        'raleway',
        'work_sans',
        'source_sans_3',
        'ibm_plex_sans',
        'dm_sans',
        'karla',
        'manrope',
        'outfit',
        'plus_jakarta_sans',
        'mulish',
        'rubik',
        'noto_sans',
        'barlow',
        'figtree',
        'urbanist',
        'archivo',
        'public_sans',
        'josefin_sans',
        'cabin',
        'titillium_web',
        'oswald',
        'source_serif',
        'merriweather',
        'playfair_display',
        'libre_baskerville',
        'lora',
        'crimson_pro',
        'eb_garamond',
        'literata',
        'pt_serif',
        'spectral',
        'cormorant_garamond',
        'newsreader',
        'noto_serif',
        'ibm_plex_serif',
        'libre_caslon_text',
        'cardo',
        'fraunces',
        'bitter',
        'vollkorn',
        'georgia',
        'times',
        'garamond',
        'palatino',
        'arial',
        'verdana',
        'tahoma',
        'trebuchet',
        'courier',
    ];

    public const DEFAULT_COLOR = '#004ac6';

    protected $fillable = [
        'company_id',
        'type',
        'primary_color',
        'font_key',
        'footer_notes',
        'show_logo',
        'show_signature',
    ];

    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_signature' => 'boolean',
        ];
    }

    /**
     * @return array<string, array{primary_color: string, font_key: string, footer_notes: string, show_logo: bool, show_signature: bool}>
     */
    public static function defaults(): array
    {
        return [
            'factura' => [
                'primary_color' => self::DEFAULT_COLOR,
                'font_key' => 'geist',
                'footer_notes' => 'Thank you for your business.',
                'show_logo' => true,
                'show_signature' => false,
            ],
            'albaran' => [
                'primary_color' => self::DEFAULT_COLOR,
                'font_key' => 'geist',
                'footer_notes' => 'This delivery note is not a tax invoice.',
                'show_logo' => false,
                'show_signature' => true,
            ],
            'quotation' => [
                'primary_color' => self::DEFAULT_COLOR,
                'font_key' => 'geist',
                'footer_notes' => "Prices shown both without and with tax, at the rates in force today.\nDelivery starts once the quotation is accepted in writing.\nStock is not reserved until acceptance.",
                'show_logo' => true,
                'show_signature' => false,
            ],
            'proforma' => [
                'primary_color' => self::DEFAULT_COLOR,
                'font_key' => 'geist',
                'footer_notes' => 'Proforma document. Not a tax invoice and not a payment request.',
                'show_logo' => true,
                'show_signature' => false,
            ],
        ];
    }

    public static function seedDefaults(int $companyId): void
    {
        foreach (self::defaults() as $type => $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'type' => $type],
                $row,
            );
        }
    }

    /**
     * @return array{primary_color: string, font_key: string, footer_notes: string, show_logo: bool, show_signature: bool}
     */
    public static function defaultFor(string $type): array
    {
        return self::defaults()[$type];
    }
}
