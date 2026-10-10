<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'team_role' => $this->team_role,
            'company_id' => $this->company_id,
            'locale' => $this->locale,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'has_password' => $this->password !== null,
        ];
    }
}
