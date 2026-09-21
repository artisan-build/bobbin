<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_URI'] === '/__health') {
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
    'method' => $_SERVER['REQUEST_METHOD'],
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR));

http_response_code(202);
