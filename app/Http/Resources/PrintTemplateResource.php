<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'primary_color' => $this->primary_color,
            'font_key' => $this->font_key,
            'footer_notes' => $this->footer_notes ?? '',
            'show_logo' => (bool) $this->show_logo,
            'show_signature' => (bool) $this->show_signature,
        ];
    }
}
