<?php

/**
 * fake-apns.php
 *
 * Router for the PHP built-in web server simulating the APNs HTTP API in tests.
 * - POST /3/device/{64 x "0"} : 400 BadDeviceToken
 * - POST /3/device/{token}    : 200, the request is appended to the file named
 *                               by the APNS_LOG environment variable
 */

$token = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($token === str_repeat('0', 64)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['reason' => 'BadDeviceToken']);
    return;
}

$log = getenv('APNS_LOG');
if ($log) {
    file_put_contents($log, json_encode([
        'token' => $token,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'topic' => $_SERVER['HTTP_APNS_TOPIC'] ?? null,
        'payload' => json_decode(file_get_contents('php://input'), true),
    ]) . "\n", FILE_APPEND);
}

http_response_code(200);
