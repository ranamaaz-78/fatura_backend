<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\SalesDocument;
use App\Services\WhatsApp\WhatsAppMicroserviceClient;
use App\Support\Phone;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WhatsAppMicroserviceClient $client
    ) {}

    /**
     * The session name is owned by the server, never the client: one fixed name
     * per company, so no tenant can name (and so hijack) another tenant's session.
     */
    private function instanceNameFor(Company $company): string
    {
        return 'company_'.$company->id;
    }

    /** The company's own session name, or null when it has none it may use. */
    private function ownInstance(Company $company): ?string
    {
        return $company->whatsapp_instance_name === $this->instanceNameFor($company)
            ? $company->whatsapp_instance_name
            : null;
    }

    /**
     * Get current company's WhatsApp instance status and settings.
     */
    public function status(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $instanceName = $this->ownInstance($company);
        $qrcode = null;

        if ($instanceName) {
            $live = $this->client->getStatus($instanceName);
            if (!empty($live['status']) && $live['status'] !== 'service_offline') {
                $company->whatsapp_status = $live['status'];
                if (!empty($live['phone'])) {
                    $company->whatsapp_connected_phone = $live['phone'];
                }
                if (!empty($live['name'])) {
                    $company->whatsapp_connected_name = $live['name'];
                }
                if ($live['status'] === 'disconnected') {
                    $company->whatsapp_connected_phone = null;
                    $company->whatsapp_connected_name = null;
                }
                $company->saveQuietly();
                $qrcode = $live['qrcode'] ?? null;
            }
        }

        return $this->success([
            'instance_name' => $instanceName ?? $this->instanceNameFor($company),
            'status' => $instanceName ? ($company->whatsapp_status ?? 'disconnected') : 'disconnected',
            'connected_phone' => $company->whatsapp_connected_phone,
            'connected_name' => $company->whatsapp_connected_name,
            'qrcode' => $qrcode,
            'auto_send' => (bool) ($company->whatsapp_auto_send ?? true),
            'message_template' => $company->whatsapp_message_template,
            'service_alive' => $this->client->isAlive(),
        ]);
    }

    /**
     * Initialize or start a WhatsApp instance session.
     */
    public function init(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $instanceName = $this->instanceNameFor($company);

        $result = $this->client->initInstance($instanceName);

        if (!empty($result['error'])) {
            return $this->error($result['error'], 422);
        }

        $company->whatsapp_instance_name = $instanceName;
        $company->whatsapp_status = $result['status'] ?? 'connecting';
        if (!empty($result['phone'])) {
            $company->whatsapp_connected_phone = $result['phone'];
        }
        $company->saveQuietly();

        return $this->success([
            'instance_name' => $instanceName,
            'status' => $result['status'] ?? 'connecting',
            'qrcode' => $result['qrcode'] ?? null,
            'connected_phone' => $result['phone'] ?? null,
            'connected_name' => $result['name'] ?? null,
            'auto_send' => (bool) ($company->whatsapp_auto_send ?? true),
            'message_template' => $company->whatsapp_message_template,
        ], __('WhatsApp instance initialized. Scan QR code to connect.'));
    }

    /**
     * Logout and disconnect the WhatsApp instance.
     */
    public function logout(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        if ($instance = $this->ownInstance($company)) {
            $this->client->logoutInstance($instance);
        }

        $company->whatsapp_instance_name = null;
        $company->whatsapp_status = 'disconnected';
        $company->whatsapp_connected_phone = null;
        $company->whatsapp_connected_name = null;
        $company->saveQuietly();

        return $this->success(null, __('WhatsApp instance disconnected successfully.'));
    }

    /**
     * Update automation settings (auto-send and template).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_send' => ['nullable', 'boolean'],
            'message_template' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->user()->company;

        if (array_key_exists('auto_send', $data)) {
            $company->whatsapp_auto_send = (bool) $data['auto_send'];
        }
        if (array_key_exists('message_template', $data)) {
            $company->whatsapp_message_template = $data['message_template'];
        }

        $company->saveQuietly();

        return $this->success([
            'auto_send' => (bool) $company->whatsapp_auto_send,
            'message_template' => $company->whatsapp_message_template,
        ], __('WhatsApp settings saved.'));
    }

    /**
     * Send a sales document PDF directly to customer WhatsApp.
     */
    public function sendDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer'],
            'number' => ['nullable', 'string', 'max:50'],
            // About 10 MB of PDF once decoded.
            'fileBase64' => ['required', 'string', 'max:14000000'],
            'filename' => ['nullable', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->user()->company;
        $instanceName = $this->ownInstance($company);

        if ($instanceName === null || $company->whatsapp_status !== 'connected') {
            return $this->error(__('Your WhatsApp is not connected. Please connect your WhatsApp in Settings first.'), 422);
        }

        $head = base64_decode(substr(preg_replace('/^data:[^,]*;base64,/', '', $data['fileBase64']), 0, 16), true);
        if ($head === false || ! str_starts_with($head, '%PDF-')) {
            return $this->error(__('The attachment is not a valid PDF.'), 422);
        }

        /** @var SalesDocument|null $sale */
        $sale = SalesDocument::query()
            ->where('company_id', $company->id)
            ->where('id', $data['sale_id'])
            ->with(['customer', 'lines'])
            ->first();

        if (!$sale) {
            return $this->error(__('Sales document not found.'), 404);
        }

        $rawPhone = $data['number'] ?: $sale->client_phone ?: $sale->customer?->phone;
        if (empty($rawPhone)) {
            return $this->error(__('Customer does not have a phone/WhatsApp number.'), 422);
        }

        $waDigits = Phone::waDigits($rawPhone) ?: preg_replace('/\D+/', '', $rawPhone);
        if (empty($waDigits)) {
            return $this->error(__('Invalid phone number format.'), 422);
        }

        $caption = $data['caption'] ?? null;
        if (empty($caption)) {
            $totalFormatted = number_format($sale->total_cents / 100, 2) . ' ' . $company->currency;
            $typeLabel = ucfirst($sale->type);
            $caption = "Dear {$sale->client_name},\n\nPlease find attached your {$typeLabel} *#{$sale->number}* from *{$company->name}* for *{$totalFormatted}*.\n\nThank you for choosing us!";
        }

        $filename = $data['filename'] ?: "{$sale->number}.pdf";

        $result = $this->client->sendDocument(
            $instanceName,
            $waDigits,
            $data['fileBase64'],
            $filename,
            $caption
        );

        NotificationLog::create([
            'channel' => NotificationChannel::WhatsApp,
            'type' => 'document_issued',
            'recipient' => $waDigits,
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'status' => !empty($result['success']) ? NotificationStatus::Sent : NotificationStatus::Failed,
            'payload' => [
                'sale_id' => $sale->id,
                'number' => $sale->number,
                'type' => $sale->type,
                'total_cents' => $sale->total_cents,
                'caption' => $caption,
            ],
            'error' => $result['error'] ?? null,
            'sent_at' => !empty($result['success']) ? now() : null,
        ]);

        if (empty($result['success'])) {
            return $this->error($result['error'] ?? __('Failed to send WhatsApp document.'), 422);
        }

        return $this->success([
            'recipient' => $waDigits,
            'messageId' => $result['messageId'] ?? null,
            'filename' => $filename,
        ], __('Document successfully sent via WhatsApp to :number', ['number' => $rawPhone]));
    }

    /**
     * Send a quick test text message.
     */
    public function testMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $company = $request->user()->company;
        $instanceName = $this->ownInstance($company);

        if ($instanceName === null || $company->whatsapp_status !== 'connected') {
            return $this->error(__('Your WhatsApp is not connected. Please connect your WhatsApp in Settings first.'), 422);
        }

        $waDigits = Phone::waDigits($data['phone']) ?: preg_replace('/\D+/', '', $data['phone']);
        $message = $data['message'] ?: ("Hello from {$company->name}! This is a test WhatsApp message from Fatura.");

        $result = $this->client->sendText($instanceName, $waDigits, $message);

        if (empty($result['success'])) {
            return $this->error($result['error'] ?? __('Failed to send WhatsApp message.'), 422);
        }

        return $this->success($result, __('Test WhatsApp message sent successfully!'));
    }
}

