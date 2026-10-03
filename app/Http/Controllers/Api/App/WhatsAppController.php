<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\SalesDocument;
use App\Services\WhatsApp\WhatsAppMicroserviceClient;
use App\Support\Fmt;
use App\Support\Phone;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Each company links its own WhatsApp. The session is named by the server (one fixed name per
 * company), so no company can name, and so hijack, another company's session.
 */
class WhatsAppController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WhatsAppMicroserviceClient $client
    ) {}

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
     * What the screen needs in one place.
     *
     * status is one of: disconnected, connecting, qrcode, connected, service_offline, service_misconfigured.
     *
     * @return array<string, mixed>
     */
    private function state(Company $company, ?string $status = null, ?string $qrcode = null, ?bool $serviceAlive = null): array
    {
        $stored = $this->ownInstance($company) ? ($company->whatsapp_status ?: 'disconnected') : 'disconnected';
        $status ??= $stored;

        return [
            'instance_name' => $this->instanceNameFor($company),
            'status' => $status,
            'connected_phone' => $status === 'connected' ? $company->whatsapp_connected_phone : null,
            'connected_name' => $status === 'connected' ? $company->whatsapp_connected_name : null,
            'qrcode' => $status === 'qrcode' ? $qrcode : null,
            'auto_send' => (bool) ($company->whatsapp_auto_send ?? true),
            'message_template' => $company->whatsapp_message_template,
            'service_alive' => $serviceAlive ?? ! in_array($status, ['service_offline', 'service_misconfigured'], true),
        ];
    }

    /** Keep what we store in step with what the service says. */
    private function remember(Company $company, array $live): void
    {
        $company->whatsapp_status = $live['status'];

        if (! empty($live['phone'])) {
            $company->whatsapp_connected_phone = $live['phone'];
        }
        if (! empty($live['name'])) {
            $company->whatsapp_connected_name = $live['name'];
        }
        if ($live['status'] === 'disconnected') {
            $company->whatsapp_connected_phone = null;
            $company->whatsapp_connected_name = null;
        }

        $company->saveQuietly();
    }

    /** Offline or refused: tell the person plainly, with a status the screen can act on. */
    private function unavailable(array $result): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $result['error'] ?? __('WhatsApp is not available right now. Please try again in a moment.'),
            'data' => [],
            'code' => $result['code'] ?? WhatsAppMicroserviceClient::OFFLINE,
        ], 503);
    }

    private function isUnavailable(array $result): bool
    {
        return in_array($result['code'] ?? null, [WhatsAppMicroserviceClient::OFFLINE, WhatsAppMicroserviceClient::AUTH], true);
    }

    /** The company's WhatsApp, as it is right now. */
    public function status(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $instance = $this->ownInstance($company);

        if ($instance === null) {
            $alive = $this->client->isAlive();

            return $this->success($this->state($company, $alive ? 'disconnected' : 'service_offline', null, $alive));
        }

        $live = $this->client->getStatus($instance);

        if (isset($live['error'])) {
            return $this->success($this->state($company, $live['status'], null, false));
        }

        $this->remember($company, $live);

        return $this->success($this->state($company, $live['status'], $live['qrcode'] ?? null));
    }

    /**
     * Start linking: returns the QR code to scan. Send fresh=1 to throw the old session away and get a new code.
     */
    public function init(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $instanceName = $this->instanceNameFor($company);

        if ($request->boolean('fresh') && $this->ownInstance($company)) {
            $this->client->logoutInstance($instanceName);
        }

        $result = $this->client->initInstance($instanceName);

        if (! empty($result['error'])) {
            return $this->isUnavailable($result) ? $this->unavailable($result) : $this->error($result['error'], 422);
        }

        $company->whatsapp_instance_name = $instanceName;
        $this->remember($company, [
            'status' => $result['status'] ?? 'connecting',
            'phone' => $result['phone'] ?? null,
            'name' => $result['name'] ?? null,
        ]);

        return $this->success(
            $this->state($company, $result['status'] ?? 'connecting', $result['qrcode'] ?? null),
            __('Scan the QR code with WhatsApp on your phone.'),
        );
    }

    /** Disconnect the linked WhatsApp. */
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

        return $this->success($this->state($company), __('WhatsApp disconnected.'));
    }

    /** Automatic sending and the message wording. */
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
            $company->whatsapp_message_template = filled($data['message_template']) ? $data['message_template'] : null;
        }

        $company->saveQuietly();

        return $this->success([
            'auto_send' => (bool) $company->whatsapp_auto_send,
            'message_template' => $company->whatsapp_message_template,
        ], __('WhatsApp settings saved.'));
    }

    /** Send a sales document PDF to the customer's WhatsApp. */
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
            return $this->error(__('Your WhatsApp is not connected. Connect it in Settings, under WhatsApp, first.'), 422);
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

        if (! $sale) {
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
            $totalFormatted = Fmt::money($sale->total_cents / 100).' '.$company->currency;
            $caption = __("Dear :customer,\n\nPlease find attached your :type *#:number* from *:company* for *:total*.\n\nThank you for choosing us!", [
                'customer' => $sale->client_name,
                'type' => SalesDocument::typeLabel($sale->type),
                'number' => $sale->number,
                'company' => $company->name,
                'total' => $totalFormatted,
            ]);
        }

        $filename = $data['filename'] ?: "{$sale->number}.pdf";

        $result = $this->client->sendDocument($instanceName, $waDigits, $data['fileBase64'], $filename, $caption);

        NotificationLog::create([
            'channel' => NotificationChannel::WhatsApp,
            'type' => 'document_issued',
            'recipient' => $waDigits,
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'status' => ! empty($result['success']) ? NotificationStatus::Sent : NotificationStatus::Failed,
            'payload' => [
                'sale_id' => $sale->id,
                'number' => $sale->number,
                'type' => $sale->type,
                'total_cents' => $sale->total_cents,
                'caption' => $caption,
            ],
            'error' => $result['error'] ?? null,
            'sent_at' => ! empty($result['success']) ? now() : null,
        ]);

        if (empty($result['success'])) {
            return $this->isUnavailable($result)
                ? $this->unavailable($result)
                : $this->error($result['error'] ?? __('Failed to send WhatsApp document.'), 422);
        }

        return $this->success([
            'recipient' => $waDigits,
            'messageId' => $result['messageId'] ?? null,
            'filename' => $filename,
        ], __('Document successfully sent via WhatsApp to :number', ['number' => $rawPhone]));
    }

    /**
     * A quick test message. With no number given it goes to the linked WhatsApp itself,
     * so a test never needs anyone else's number.
     */
    public function testMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $company = $request->user()->company;
        $instanceName = $this->ownInstance($company);

        if ($instanceName === null || $company->whatsapp_status !== 'connected') {
            return $this->error(__('Your WhatsApp is not connected. Connect it in Settings, under WhatsApp, first.'), 422);
        }

        $target = $data['phone'] ?? null ?: $company->whatsapp_connected_phone;

        if (blank($target)) {
            return $this->error(__('Enter the number to send the test to.'), 422);
        }

        $waDigits = Phone::waDigits($target) ?: preg_replace('/\D+/', '', $target);
        $message = ($data['message'] ?? null) ?: __('Hello from :company! This is a test message from YK Digital Solutions. Your WhatsApp is connected.', ['company' => $company->name]);

        $result = $this->client->sendText($instanceName, $waDigits, $message);

        if (empty($result['success'])) {
            return $this->isUnavailable($result)
                ? $this->unavailable($result)
                : $this->error($result['error'] ?? __('Failed to send WhatsApp message.'), 422);
        }

        return $this->success(['recipient' => $waDigits], __('Test message sent.'));
    }
}
