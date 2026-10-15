<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    /** The Windows app serves the site from app://yk-digital: its calls to the API must be let through. */
    public function test_the_windows_app_may_call_the_api(): void
    {
        $this->call('OPTIONS', '/api/public/plans', [], [], [], [
            'HTTP_ORIGIN' => 'app://yk-digital',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,accept-language',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'app://yk-digital');
    }

    public function test_an_unknown_origin_is_not_let_through(): void
    {
        $response = $this->call('OPTIONS', '/api/public/plans', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
