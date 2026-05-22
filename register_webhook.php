<?php
// ============================================================
// register_webhook.php
// Run this ONCE from your browser or CLI after deploying.
// Then delete or protect this file.
//
// Usage:
//   https://yourapp.railway.app/register_webhook.php?secret=WEBHOOK_SECRET
// ============================================================

$botToken      = getenv('BOT_TOKEN');
$webhookSecret = getenv('WEBHOOK_SECRET');

// Simple guard — must pass the secret in the query string
if (($_GET['secret'] ?? '') !== $webhookSecret) {
    http_response_code(403);
    exit('Forbidden');
}

// Your Railway app URL — set this as a Railway env var too
$appUrl = rtrim(getenv('APP_URL'), '/');
$webhookUrl = $appUrl . '/webhook.php';

$apiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook";

$payload = json_encode([
    'url'          => $webhookUrl,
    'secret_token' => $webhookSecret,
    'allowed_updates' => ['channel_post'], // only channel posts, nothing else
]);

$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $payload,
]]);

$result = file_get_contents($apiUrl, false, $ctx);
$decoded = json_decode($result, true);

header('Content-Type: application/json');
echo json_encode($decoded, JSON_PRETTY_PRINT);
