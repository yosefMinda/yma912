<?php
/**
 * file.php — YMA912 file serving endpoint
 *
 * GET /file.php?id={catalog_id}
 *
 * Routing logic:
 *   storage_type = 'telegram' → fetch tg_file_id via Bot API, redirect to CDN URL
 *   storage_type = 'gdrive'   → redirect to storage_ref (full URL stored in DB)
 *   storage_type = 'r2'       → redirect to storage_ref (full URL stored in DB)
 *   any future backend        → redirect to storage_ref (full URL stored in DB)
 *
 * Design principle: storage_ref for all non-Telegram backends holds a complete,
 * ready-to-redirect URL. Switching backends means updating storage_ref in the DB
 * only — this file never needs to change for non-Telegram backends.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$bot_token = getenv('BOT_TOKEN');
$db_host   = getenv('DB_HOST');
$db_port   = getenv('DB_PORT') ?: '3306';
$db_name   = getenv('DB_NAME');
$db_user   = getenv('DB_USER');
$db_pass   = getenv('DB_PASS');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function abort(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function redirect(string $url): never {
    header('Location: ' . $url, true, 302);
    exit;
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    abort(400, 'Missing or invalid id parameter.');
}

// ---------------------------------------------------------------------------
// DB connection
// ---------------------------------------------------------------------------

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
} catch (PDOException $e) {
    abort(503, 'Database connection failed.');
}

// ---------------------------------------------------------------------------
// Fetch catalog row
// ---------------------------------------------------------------------------

$stmt = $pdo->prepare(
    'SELECT id, tg_file_id, storage_type, storage_ref, is_active
     FROM catalog
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    abort(404, 'Resource not found.');
}

if (!$row['is_active']) {
    abort(410, 'This resource is no longer available.');
}

// ---------------------------------------------------------------------------
// Route by storage_type
// ---------------------------------------------------------------------------

$storage_type = $row['storage_type'];
$storage_ref  = $row['storage_ref'];

// Non-Telegram backends: storage_ref IS the full redirect URL.
// No logic needed here — just redirect. Adding a new backend (Supabase,
// Puter, Backblaze, etc.) requires zero changes to this file.
if ($storage_type !== 'telegram') {
    if (empty($storage_ref)) {
        abort(500, 'File reference is missing for this resource.');
    }

    // Basic sanity check: must be an https URL
    if (!str_starts_with($storage_ref, 'https://')) {
        abort(500, 'Invalid file reference.');
    }

    redirect($storage_ref);
}

// ---------------------------------------------------------------------------
// Telegram: resolve tg_file_id → CDN URL via Bot API
// ---------------------------------------------------------------------------

if (empty($bot_token)) {
    abort(503, 'Bot token not configured.');
}

$tg_file_id = $row['tg_file_id'];
if (empty($tg_file_id)) {
    abort(500, 'Telegram file ID is missing for this resource.');
}

$api_url  = "https://api.telegram.org/bot{$bot_token}/getFile?file_id=" . urlencode($tg_file_id);
$response = @file_get_contents($api_url);

if ($response === false) {
    abort(503, 'Could not reach Telegram API.');
}

$data = json_decode($response, true);

if (empty($data['ok']) || empty($data['result']['file_path'])) {
    // Telegram returns ok=false with a description on failure
    $tg_error = $data['description'] ?? 'Unknown Telegram API error.';
    abort(502, 'Telegram API error: ' . $tg_error);
}

$file_path  = $data['result']['file_path'];
$cdn_url    = "https://api.telegram.org/file/bot{$bot_token}/{$file_path}";

// Note: Telegram Bot API limits file downloads to 20MB via getFile.
// Files larger than 20MB stored as storage_type='telegram' cannot be served
// this way. The hand-over approach (sending the file to the user's DM via the
// bot) is the intended fallback for those, to be implemented in the TMA layer.

redirect($cdn_url);
