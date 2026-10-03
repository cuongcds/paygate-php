<?php

// Router for PHP's built-in server, used by ClientTest. Records each request
// to capture.json and answers with the canned reply in reply.json, both in
// the directory named by the PAYGATE_TEST_DIR env var.

$dir = getenv('PAYGATE_TEST_DIR');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

file_put_contents($dir . '/capture.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]));

$reply = json_decode((string) file_get_contents($dir . '/reply.json'), true);
http_response_code($reply['status']);
header('Content-Type: application/json');
echo $reply['raw'];
