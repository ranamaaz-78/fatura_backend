<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\WhatsApp\WhatsAppMicroserviceClient;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/** The platform admin's view of each company's own WhatsApp link. */
class WhatsAppController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WhatsAppMicroserviceClient $client) {}

    private function instanceNameFor(Company $company): string
    {
        return 'company_'.$company->id;
    }

    /** @return array<string, mixed> */
    private function payload(Company $company, string $status, bool $serviceAlive): array
    {
        $linked = $status === 'connected';

        return [
            'instance_name' => $this->instanceNameFor($company),
            'has_instance' => $company->whatsapp_instance_name !== null,
            'status' => $status,
            'connected_phone' => $linked ? $company->whatsapp_connected_phone : null,
            'connected_name' => $linked ? $company->whatsapp_connected_name : null,
            'service_alive' => $serviceAlive,
        ];
    }

    public function show(Company $company): JsonResponse
    {
        if ($company->whatsapp_instance_name === null) {
            return $this->success($this->payload($company, 'disconnected', $this->client->isAlive()));
        }

        $live = $this->client->getStatus($this->instanceNameFor($company));

        if (isset($live['error'])) {
            // The service cannot say, so show what we last knew and flag the service.
            return $this->success($this->payload($company, $company->whatsapp_status ?: 'disconnected', false));
        }

        $company->forceFill(['whatsapp_status' => $live['status']]);
        if (! empty($live['phone'])) {
            $company->whatsapp_connected_phone = $live['phone'];
        }
        if (! empty($live['name'])) {
            $company->whatsapp_connected_name = $live['name'];
        }
        $company->saveQuietly();

        return $this->success($this->payload($company, $live['status'], true));
    }

    /** Cut a company's WhatsApp link, for example when the number must be unlinked on their behalf. */
    public function disconnect(Company $company): JsonResponse
    {
        if ($company->whatsapp_instance_name !== null) {
            $this->client->logoutInstance($this->instanceNameFor($company));
        }

        $company->forceFill([
            'whatsapp_instance_name' => null,
            'whatsapp_status' => 'disconnected',
            'whatsapp_connected_phone' => null,
            'whatsapp_connected_name' => null,
        ])->saveQuietly();

        return $this->success($this->payload($company, 'disconnected', true), __('WhatsApp disconnected for :company.', ['company' => $company->name]));
    }
}
