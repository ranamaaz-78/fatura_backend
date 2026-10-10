<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One question and answer on the public website, in English and Spanish. */
class SiteFaq extends Model
{
    protected $fillable = [
        'question_en',
        'answer_en',
        'question_es',
        'answer_es',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    /** The text in a language, or the English when that language has none yet. */
    public function question(string $locale): string
    {
        return $this->pick($this->{'question_'.$locale} ?? null, $this->question_en);
    }

    public function answer(string $locale): string
    {
        return $this->pick($this->{'answer_'.$locale} ?? null, $this->answer_en);
    }

    private function pick(?string $wanted, string $english): string
    {
        return filled($wanted) ? $wanted : $english;
    }
}
