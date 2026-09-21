<?php

declare(strict_types=1);

if (serverInput('REQUEST_URI') === '/__health') {
    http_response_code(204);

    return;
}

$output = getenv('BOBBIN_LIVE_RECEIVER_OUTPUT');

if (! is_string($output) || $output === '') {
    http_response_code(500);

    return;
}

$headers = [];

foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}

file_put_contents($output, json_encode([
    'method' => serverInput('REQUEST_METHOD'),
    'query' => serverInput('QUERY_STRING'),
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR));

http_response_code(202);

function serverInput(string $name): string
{
    $value = filter_input(INPUT_SERVER, $name, FILTER_UNSAFE_RAW);

    if (! is_string($value)) {
        $value = $GLOBALS['_SERVER'][$name] ?? null;
    }

    return is_string($value) ? $value : '';
}
