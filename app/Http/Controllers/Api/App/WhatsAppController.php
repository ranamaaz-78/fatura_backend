<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp works through click-to-chat links (wa.me), so nothing is linked or sent from the server.
 * All the company stores is the wording of the message that opens in the person's own WhatsApp.
 */
class WhatsAppController extends Controller
{
    use ApiResponse;

    public function settings(Request $request): JsonResponse
    {
        return $this->success($this->payload($request));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message_template' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->user()->company;

        if (array_key_exists('message_template', $data)) {
            $company->whatsapp_message_template = filled($data['message_template']) ? $data['message_template'] : null;
        }

        $company->saveQuietly();

        return $this->success($this->payload($request), __('WhatsApp settings saved.'));
    }

    /** @return array{message_template: string|null} */
    private function payload(Request $request): array
    {
        return ['message_template' => $request->user()->company->whatsapp_message_template];
    }
}
