<?php
require_once __DIR__ . '/../../vendor/autoload.php';

echo "=== NYX E2E API Verification Suite ===\n";

$baseUrl = "http://127.0.0.1:8080";

function curlRequest(string $method, string $endpoint, array $data = []): array {
    global $baseUrl;
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if (!empty($data)) {
        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code' => $httpCode,
        'header' => $header,
        'body' => json_decode($body, true) ?? $body
    ];
}

// 1. Test Health Endpoint
$health = curlRequest('GET', '/api/v1/health'); // or /status.html or similar
echo "Health Endpoint Status: {$health['code']}\n";
var_dump($health['body']);

// Check index or other endpoints if specific routes exist
$root = curlRequest('GET', '/');
echo "Root Page Status: {$root['code']}\n";

// Test registration / auth endpoints if available in routes
$register = curlRequest('POST', '/api/v1/auth/register', [
    'email' => 'qa_test_' . time() . '@nyx.local',
    'password' => 'SecurePass123!'
]);
echo "Register Endpoint Status: {$register['code']}\n";
var_dump($register['body']);

echo "=== E2E API Verification Complete ===\n";
