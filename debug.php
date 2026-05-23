<?php
// debug.php — TEMPORARY. Delete after debugging.
// Visit: https://yma912-production.up.railway.app/debug.php?secret=yma912secret2026eth

if (($_GET['secret'] ?? '') !== getenv('WEBHOOK_SECRET')) {
    http_response_code(403); exit('Forbidden');
}

header('Content-Type: text/plain');

// 1. Check env vars are loaded
echo "=== ENV VARS ===\n";
$vars = ['BOT_TOKEN','ADMIN_CHAT_ID','DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'];
foreach ($vars as $v) {
    $val = getenv($v);
    echo "$v: " . ($val ? substr($val, 0, 6) . '...' : 'MISSING') . "\n";
}

// 2. Test DB connection
echo "\n=== DB CONNECTION ===\n";
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_NAME')),
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "DB connection: OK\n";
    $row = $pdo->query('SELECT COUNT(*) as c FROM catalog')->fetch();
    echo "Catalog rows: " . $row['c'] . "\n";
    $row2 = $pdo->query('SELECT COUNT(*) as c FROM tg_channels')->fetch();
    echo "tg_channels rows: " . $row2['c'] . "\n";
} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

// 3. Test bot can send a DM
echo "\n=== BOT DM TEST ===\n";
$token  = getenv('BOT_TOKEN');
$chatId = getenv('ADMIN_CHAT_ID');
$url    = "https://api.telegram.org/bot{$token}/sendMessage";
$payload = json_encode(['chat_id' => $chatId, 'text' => '✅ Debug test — bot is working.']);
$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $payload,
    'timeout' => 10,
]]);
$result = @file_get_contents($url, false, $ctx);
$decoded = json_decode($result, true);
if ($decoded && $decoded['ok']) {
    echo "Bot DM: OK — message sent to $chatId\n";
} else {
    echo "Bot DM ERROR: " . ($result ?: 'No response') . "\n";
}
