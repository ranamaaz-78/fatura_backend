<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the Node WhatsApp service. That service holds one session per company and trusts
 * only calls that carry the shared secret, so every request goes through http() below.
 *
 * Every method returns an array. A failure carries `error` (a message fit to show) and `code`:
 *   SERVICE_OFFLINE  the service is not running or not reachable
 *   SERVICE_AUTH     the service refused our secret (the two .env files disagree)
 *   SERVICE_ERROR    the service answered with an error of its own
 */
class WhatsAppMicroserviceClient
{
    public const OFFLINE = 'SERVICE_OFFLINE';

    public const AUTH = 'SERVICE_AUTH';

    public const ERROR = 'SERVICE_ERROR';

    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('fatura.whatsapp_service.url', 'http://127.0.0.1:3333'), '/');
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders(['X-Service-Secret' => (string) config('fatura.whatsapp_service.secret')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $data = [], int $timeout = 10, string $fallbackError = 'WhatsApp request failed.'): array
    {
        try {
            $response = $method === 'get'
                ? $this->http($timeout)->get($path)
                : $this->http($timeout)->post($path, $data);
        } catch (ConnectionException $e) {
            Log::warning("WhatsApp service unreachable ({$path}): ".$e->getMessage());

            return ['error' => __('WhatsApp is not available right now. Please try again in a moment.'), 'code' => self::OFFLINE];
        } catch (Throwable $e) {
            Log::error("WhatsApp service call failed ({$path}): ".$e->getMessage());

            return ['error' => __('WhatsApp is not available right now. Please try again in a moment.'), 'code' => self::ERROR];
        }

        return $this->read($response, $path, $fallbackError);
    }

    /** @return array<string, mixed> */
    private function read(Response $response, string $path, string $fallbackError): array
    {
        if ($response->status() === 401) {
            Log::error("WhatsApp service refused our secret ({$path}). WHATSAPP_SERVICE_SECRET must match in both .env files.");

            return ['error' => __('WhatsApp is not set up correctly on the server. Please contact support.'), 'code' => self::AUTH];
        }

        if (! $response->successful()) {
            return ['error' => (string) ($response->json('error') ?: __($fallbackError)), 'code' => self::ERROR];
        }

        return $response->json() ?? [];
    }

    /** Is the service running? The health route needs no secret. */
    public function isAlive(): bool
    {
        try {
            return Http::baseUrl($this->baseUrl)->timeout(3)->get('/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Start (or pick up) a company's session and return its QR code when it needs scanning.
     *
     * @return array{instanceName?: string, status?: string, qrcode?: ?string, phone?: ?string, error?: string, code?: string}
     */
    public function initInstance(string $instanceName): array
    {
        return $this->call('post', '/instance/init', ['instanceName' => $instanceName], 15, 'Could not start WhatsApp.');
    }

    /**
     * Live state of a session.
     *
     * @return array{status: string, phone?: ?string, qrcode?: ?string, name?: ?string, error?: string, code?: string}
     */
    public function getStatus(string $instanceName): array
    {
        $result = $this->call('get', "/instance/status/{$instanceName}", [], 6, 'Could not read the WhatsApp status.');

        if (isset($result['error'])) {
            return $result + ['status' => $result['code'] === self::AUTH ? 'service_misconfigured' : 'service_offline'];
        }

        return $result;
    }

    /** Disconnect a session and remove it from the service. */
    public function logoutInstance(string $instanceName): array
    {
        return $this->call('post', "/instance/logout/{$instanceName}", [], 10, 'Could not disconnect WhatsApp.');
    }

    /** @return array{exists?: bool, error?: string, code?: string} */
    public function checkNumber(string $instanceName, string $number): array
    {
        return $this->call('get', "/instance/check-number/{$instanceName}/{$number}", [], 8, 'Could not check that number.');
    }

    /**
     * Send a PDF.
     *
     * @return array{success?: bool, messageId?: string, error?: string, code?: string}
     */
    public function sendDocument(string $instanceName, string $number, string $fileBase64, string $filename, string $caption = ''): array
    {
        return $this->call('post', '/message/send-document', [
            'instanceName' => $instanceName,
            'number' => $number,
            'fileBase64' => $fileBase64,
            'filename' => $filename,
            'caption' => $caption,
            'mimetype' => 'application/pdf',
        ], 30, 'Failed to send the WhatsApp document.');
    }

    /**
     * Send a text message.
     *
     * @return array{success?: bool, messageId?: string, error?: string, code?: string}
     */
    public function sendText(string $instanceName, string $number, string $message): array
    {
        return $this->call('post', '/message/send-text', [
            'instanceName' => $instanceName,
            'number' => $number,
            'message' => $message,
        ], 15, 'Failed to send the WhatsApp message.');
    }
}
