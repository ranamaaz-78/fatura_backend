<?php

$local = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'capacitor://localhost',
    'https://localhost',
    'http://localhost',
];

$frontend = rtrim((string) env('FRONTEND_URL', ''), '/');

$extra = array_map(
    static fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
);

/**
 * @return list<string>
 */
$expand = static function (string $url): array {
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        return [];
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (! is_string($host) || $host === '') {
        return [$url];
    }

    if (in_array($host, ['localhost', '127.0.0.1'], true)) {
        return [$url];
    }

    $hosts = [$host];
    if (str_starts_with($host, 'www.')) {
        $hosts[] = substr($host, 4);
    } else {
        $hosts[] = 'www.'.$host;
    }

    $labels = explode('.', $host);
    if (count($labels) > 2) {
        $apex = implode('.', array_slice($labels, -2));
        $hosts[] = $apex;
        $hosts[] = 'www.'.$apex;
    }

    $origins = [];
    foreach (array_unique($hosts) as $name) {
        $origins[] = 'https://'.$name;
        $origins[] = 'http://'.$name;
    }

    return $origins;
};

$patterns = [];
$frontendHost = is_string($frontend) && $frontend !== ''
    ? parse_url($frontend, PHP_URL_HOST)
    : null;
if (is_string($frontendHost) && ! in_array($frontendHost, ['localhost', '127.0.0.1'], true)) {
    $labels = explode('.', $frontendHost);
    $apex = count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : $frontendHost;
    $patterns[] = '#^https://([a-z0-9-]+\.)*'.preg_quote($apex, '#').'$#';
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        ...$local,
        ...$expand($frontend),
        ...$extra,
    ]))),

    'allowed_origins_patterns' => $patterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
