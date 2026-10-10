<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A legal page on the public website (privacy policy, terms and conditions), in English and Spanish. */
class SitePage extends Model
{
    /** The pages that exist: the slug is also the name in the website's link (#privacy, #terms). */
    public const SLUGS = ['privacy', 'terms'];

    protected $fillable = [
        'slug',
        'title_en',
        'title_es',
        'body_en',
        'body_es',
    ];

    public function title(string $locale): string
    {
        $wanted = $this->{'title_'.$locale} ?? null;

        return filled($wanted) ? $wanted : $this->title_en;
    }

    public function body(string $locale): string
    {
        $wanted = $this->{'body_'.$locale} ?? null;

        return filled($wanted) ? $wanted : $this->body_en;
    }
}
