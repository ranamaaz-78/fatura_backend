<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppMicroserviceClient
{
    protected string $baseUrl;
    protected string $servicePath;
    protected bool $autoStart;
    protected string $secret;

    /** Track whether we already attempted auto-start in this process lifetime. */
    protected static bool $startAttempted = false;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('fatura.whatsapp_service.url', 'http://127.0.0.1:3333'), '/');
        $this->servicePath = config('fatura.whatsapp_service.path', base_path('../whatsapp_service'));
        $this->autoStart = (bool) config('fatura.whatsapp_service.auto_start', true);
        $this->secret = (string) config('fatura.whatsapp_service.secret', '');
    }

    /** Every call to the microservice carries the shared secret. */
    protected function http(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->withHeaders(['X-Service-Secret' => $this->secret]);
    }

    /**
     * Check if microservice is alive.
     */
    public function isAlive(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->baseUrl}/health");
            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ensure the WhatsApp Node service is running.
     * If it's not alive and auto_start is enabled, start it in the background.
     */
    public function ensureRunning(): void
    {
        if ($this->isAlive()) {
            return;
        }

        if (!$this->autoStart || static::$startAttempted) {
            return;
        }

        static::$startAttempted = true;

        $indexJs = str_replace('/', DIRECTORY_SEPARATOR, $this->servicePath) . DIRECTORY_SEPARATOR . 'index.js';

        if (!file_exists($indexJs)) {
            Log::warning("WhatsApp service index.js not found at: {$indexJs}");
            return;
        }

        $servicePath = str_replace('/', DIRECTORY_SEPARATOR, $this->servicePath);

        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows: use WMI Win32_Process.Create for a truly OS-level detached process.
                // This survives after the parent PHP process exits.
                $batFile = $servicePath . DIRECTORY_SEPARATOR . 'start.bat';
                $escaped = str_replace("'", "''", $batFile);
                $cmd = "powershell -Command \"([wmiclass]'Win32_Process').Create('cmd /c \\\"{$escaped}\\\"')\"";
                exec($cmd);
            } else {
                // Linux/Mac: nohup + background
                $cmd = "cd " . escapeshellarg($servicePath) . " && nohup node index.js > /dev/null 2>&1 &";
                exec($cmd);
            }

            Log::info("WhatsApp service auto-started from: {$servicePath}");

            // Wait up to 8 seconds for the service to become available
            $attempts = 0;
            while ($attempts < 16) {
                usleep(500000); // 500ms
                $attempts++;
                if ($this->isAlive()) {
                    Log::info("WhatsApp service is now alive after {$attempts} attempts.");
                    return;
                }
            }

            Log::warning('WhatsApp service was started but did not become alive within 8 seconds.');
        } catch (Throwable $e) {
            Log::error('Failed to auto-start WhatsApp service: ' . $e->getMessage());
        }
    }

    /**
     * Initialize or retrieve instance session and return QR code if needed.
     *
     * @return array{instanceName: string, status: string, qrcode: ?string, phone: ?string, error?: string}
     */
    public function initInstance(string $instanceName): array
    {
        $this->ensureRunning();

        try {
            $response = $this->http(10)->post("{$this->baseUrl}/instance/init", [
                'instanceName' => $instanceName,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'instanceName' => $instanceName,
                'status' => 'disconnected',
                'qrcode' => null,
                'phone' => null,
                'error' => $response->json('error') ?? 'Failed to initialize WhatsApp instance.',
            ];
        } catch (Throwable $e) {
            Log::warning('WhatsApp microservice initInstance error: ' . $e->getMessage());
            return [
                'instanceName' => $instanceName,
                'status' => 'disconnected',
                'qrcode' => null,
                'phone' => null,
                'error' => 'WhatsApp service is unreachable. Please ensure WhatsApp service is running.',
            ];
        }
    }

    /**
     * Get live instance connection status.
     *
     * @return array{instanceName: string, status: string, phone: ?string, qrcode: ?string, name?: ?string, error?: string}
     */
    public function getStatus(string $instanceName): array
    {
        $this->ensureRunning();

        try {
            $response = $this->http(5)->get("{$this->baseUrl}/instance/status/".rawurlencode($instanceName)."");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'instanceName' => $instanceName,
                'status' => 'disconnected',
                'phone' => null,
                'qrcode' => null,
                'name' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('WhatsApp microservice getStatus error: ' . $e->getMessage());
            return [
                'instanceName' => $instanceName,
                'status' => 'service_offline',
                'phone' => null,
                'qrcode' => null,
                'name' => null,
                'error' => 'WhatsApp service offline',
            ];
        }
    }

    /**
     * Logout and destroy instance session.
     */
    public function logoutInstance(string $instanceName): array
    {
        try {
            $response = $this->http(8)->post("{$this->baseUrl}/instance/logout/".rawurlencode($instanceName)."");
            return $response->json() ?? ['success' => true];
        } catch (Throwable $e) {
            Log::warning('WhatsApp microservice logoutInstance error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if a phone number is registered on WhatsApp.
     */
    public function checkNumber(string $instanceName, string $number): array
    {
        try {
            $response = $this->http(8)->get("{$this->baseUrl}/instance/check-number/".rawurlencode($instanceName)."/".rawurlencode($number)."");
            return $response->json() ?? ['exists' => false];
        } catch (Throwable $e) {
            return ['exists' => true, 'warning' => 'Could not verify number registration: ' . $e->getMessage()];
        }
    }

    /**
     * Send a document (PDF) via WhatsApp.
     *
     * @return array{success: boolean, messageId?: string, error?: string}
     */
    public function sendDocument(
        string $instanceName,
        string $number,
        string $fileBase64,
        string $filename,
        string $caption = ''
    ): array {
        $this->ensureRunning();

        try {
            $response = $this->http(25)->post("{$this->baseUrl}/message/send-document", [
                'instanceName' => $instanceName,
                'number' => $number,
                'fileBase64' => $fileBase64,
                'filename' => $filename,
                'caption' => $caption,
                'mimetype' => 'application/pdf',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'error' => $response->json('error') ?? 'Failed to send WhatsApp document.',
            ];
        } catch (Throwable $e) {
            Log::error('WhatsApp microservice sendDocument error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'WhatsApp service error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send a text message via WhatsApp.
     *
     * @return array{success: boolean, messageId?: string, error?: string}
     */
    public function sendText(string $instanceName, string $number, string $message): array
    {
        $this->ensureRunning();

        try {
            $response = $this->http(15)->post("{$this->baseUrl}/message/send-text", [
                'instanceName' => $instanceName,
                'number' => $number,
                'message' => $message,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'error' => $response->json('error') ?? 'Failed to send WhatsApp message.',
            ];
        } catch (Throwable $e) {
            Log::error('WhatsApp microservice sendText error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'WhatsApp service error: ' . $e->getMessage(),
            ];
        }
    }
}
