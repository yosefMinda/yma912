<?php
// ============================================================
// webhook.php — Telegram Bot Indexer
// YMA912 ESSLCE Platform
//
// Deploy this file at your Railway PHP root.
// Set these environment variables in Railway's dashboard:
//   BOT_TOKEN      — your Telegram bot token
//   ADMIN_CHAT_ID  — your Telegram user ID (for DM confirmations)
//   DB_HOST        — MySQL host
//   DB_NAME        — database name
//   DB_USER        — database user
//   DB_PASS        — database password
//   WEBHOOK_SECRET — any random string you choose (20+ chars)
//                    set the same value when registering the webhook
// ============================================================

declare(strict_types=1);

// ── Shorthand map ────────────────────────────────────────────
// Maps terse caption tags → DB slugs
const SLUG_MAP = [
    'str' => [
        'NS' => 'natural-science',
        'SS' => 'social-science',
    ],
    'ct' => [
        'news' => 'news',
        'tb'   => 'tb',
        'sn'   => 'sn',
        'ws'   => 'ws',
        'pe'   => 'pe',
        'gk'   => 'gk',
    ],
    'sub' => [
        'phy'     => 'physics',
        'chem'    => 'chemistry',
        'bio'     => 'biology',
        'math-ns' => 'math-ns',
        'his'     => 'history',
        'geo'     => 'geography',
        'eco'     => 'economics',
        'math-ss' => 'math-ss',
        'eng'     => 'english',
        'sat'     => 'sat',
    ],
    'lang' => [
        'en'    => 'en',
        'am'    => 'am',
        'en_am' => 'en_am',
    ],
];

// ── Bootstrap ────────────────────────────────────────────────
$botToken    = getenv('BOT_TOKEN');
$adminChatId = getenv('ADMIN_CHAT_ID');
$webhookSecret = getenv('WEBHOOK_SECRET');

// Verify Telegram's secret token header
$incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($webhookSecret && $incomingSecret !== $webhookSecret) {
    http_response_code(403);
    exit('Forbidden');
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200); // Always 200 to Telegram or it retries
    exit();
}

// ── DB connection ────────────────────────────────────────────
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_NAME')),
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    dmAdmin($botToken, $adminChatId, "❌ DB connection failed: " . $e->getMessage());
    http_response_code(200);
    exit();
}

// ── Route the update ─────────────────────────────────────────
// We only care about channel_post events that contain a document
$post = $update['channel_post'] ?? null;

if ($post && isset($post['document'])) {
    handleDocumentPost($post, $pdo, $botToken, $adminChatId);
}

http_response_code(200);
exit();


// ════════════════════════════════════════════════════════════
// Core handler
// ════════════════════════════════════════════════════════════
function handleDocumentPost(array $post, PDO $pdo, string $token, string $adminId): void
{
    $caption   = $post['caption'] ?? '';
    $messageId = $post['message_id'];
    $doc       = $post['document'];
    $tgFileId  = $doc['file_id'];
    $fileSize  = isset($doc['file_size']) ? (int)round($doc['file_size'] / 1024) : null;
    $mimeType  = $doc['mime_type'] ?? '';
    $fileType  = mimeToExt($mimeType);

    // ── Parse caption ────────────────────────────────────────
    // Expected format (all on one line or multiple lines):
    // Title of the resource
    // #str:SS #ct:tb #gr:9 #sub:his #lang:en
    // Optional: #yr:2015 (past exams only)

    // Extract title: first non-empty line before any hashtags
    $lines = array_filter(array_map('trim', explode("\n", $caption)));
    $title = '';
    $tagLine = '';
    foreach ($lines as $line) {
        if (str_contains($line, '#')) {
            $tagLine .= ' ' . $line;
        } elseif (!$title) {
            $title = $line;
        }
    }

    if (!$title) {
        $title = $doc['file_name'] ?? 'Untitled';
    }

    // Extract key-value tags: #key:value
    $tags = [];
    preg_match_all('/#(\w[\w-]*):(\S+)/u', $tagLine, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $tags[strtolower($m[1])] = $m[2];
    }

    // ── Validate required tags ───────────────────────────────
    $errors = [];

    // str is always required
    if (empty($tags['str'])) {
        $errors[] = 'Missing #str (NS or SS)';
    }
    // ct is always required
    if (empty($tags['ct'])) {
        $errors[] = 'Missing #ct (tb/sn/ws/pe/news/gk)';
    }
    // lang is always required
    if (empty($tags['lang'])) {
        $errors[] = 'Missing #lang (en/am/en_am)';
    }

    if ($errors) {
        dmAdmin($token, $adminId,
            "⚠️ Skipped msg #{$messageId} — missing tags:\n" . implode("\n", $errors) .
            "\n\nCaption received:\n" . ($caption ?: '(empty)')
        );
        return;
    }

    // ── Resolve slugs → IDs ──────────────────────────────────
    $streamSlug  = SLUG_MAP['str'][$tags['str']]  ?? null;
    $ctSlug      = SLUG_MAP['ct'][$tags['ct']]    ?? null;
    $subSlug     = isset($tags['sub']) ? (SLUG_MAP['sub'][$tags['sub']] ?? null) : null;
    $langValue   = SLUG_MAP['lang'][$tags['lang']] ?? 'en';
    $gradeLabel  = isset($tags['gr']) ? 'G' . $tags['gr'] : null;
    $yearEc      = $tags['yr'] ?? null;

    if (!$streamSlug) {
        dmAdmin($token, $adminId, "⚠️ Unknown #str value: {$tags['str']}");
        return;
    }
    if (!$ctSlug) {
        dmAdmin($token, $adminId, "⚠️ Unknown #ct value: {$tags['ct']}");
        return;
    }

    // Fetch IDs from DB
    $streamId = lookupId($pdo, 'streams', 'slug', $streamSlug);
    $ctId     = lookupId($pdo, 'content_types', 'slug', $ctSlug);

    if (!$streamId || !$ctId) {
        dmAdmin($token, $adminId, "❌ DB lookup failed for stream or content_type.");
        return;
    }

    // Fetch content_type flags
    $ct = $pdo->prepare('SELECT needs_grade, needs_subject, needs_year_ec FROM content_types WHERE id = ?');
    $ct->execute([$ctId]);
    $ctFlags = $ct->fetch(PDO::FETCH_ASSOC);

    // Validate conditional tags based on content_type flags
    $subjectId = null;
    $gradeId   = null;

    if ($ctFlags['needs_subject']) {
        if (!$subSlug) {
            dmAdmin($token, $adminId, "⚠️ Content type '{$tags['ct']}' needs #sub tag.");
            return;
        }
        $subjectId = lookupId($pdo, 'subjects', 'slug', $subSlug);
        if (!$subjectId) {
            dmAdmin($token, $adminId, "⚠️ Unknown #sub value: {$tags['sub']}");
            return;
        }
    }

    if ($ctFlags['needs_grade']) {
        if (!$gradeLabel) {
            dmAdmin($token, $adminId, "⚠️ Content type '{$tags['ct']}' needs #gr tag.");
            return;
        }
        $gradeId = lookupId($pdo, 'grades', 'label', $gradeLabel);
        if (!$gradeId) {
            dmAdmin($token, $adminId, "⚠️ Unknown #gr value: {$tags['gr']}");
            return;
        }
    }

    if ($ctFlags['needs_year_ec'] && !$yearEc) {
        dmAdmin($token, $adminId, "⚠️ Content type '{$tags['ct']}' needs #yr tag.");
        return;
    }

    // Fetch channel ID
    $channelId = lookupId($pdo, 'tg_channels', 'channel_username', '@yma912');
    if (!$channelId) {
        dmAdmin($token, $adminId, "❌ Channel @yma912 not found in tg_channels table.");
        return;
    }

    // ── Insert into catalog ──────────────────────────────────
    $stmt = $pdo->prepare('
        INSERT INTO catalog
            (stream_id, content_type_id, subject_id, grade_id, tg_channel_id,
             tg_file_id, tg_message_id, storage_type,
             title, year_ec, file_type, file_size_kb, language, caption)
        VALUES
            (:stream_id, :ct_id, :subject_id, :grade_id, :channel_id,
             :tg_file_id, :tg_message_id, "telegram",
             :title, :year_ec, :file_type, :file_size_kb, :language, :caption)
    ');

    $stmt->execute([
        ':stream_id'    => $streamId,
        ':ct_id'        => $ctId,
        ':subject_id'   => $subjectId,
        ':grade_id'     => $gradeId,
        ':channel_id'   => $channelId,
        ':tg_file_id'   => $tgFileId,
        ':tg_message_id'=> $messageId,
        ':title'        => $title,
        ':year_ec'      => $yearEc,
        ':file_type'    => $fileType,
        ':file_size_kb' => $fileSize,
        ':language'     => $langValue,
        ':caption'      => $caption,
    ]);

    $newId = $pdo->lastInsertId();

    // ── Confirm to admin ─────────────────────────────────────
    $sizeStr = $fileSize ? round($fileSize / 1024, 1) . ' MB' : 'unknown size';
    dmAdmin($token, $adminId,
        "✅ Indexed #{$newId}\n" .
        "📄 {$title}\n" .
        "🔖 {$tags['str']} › {$tags['ct']}" .
        ($gradeLabel  ? " › {$gradeLabel}" : '') .
        ($subSlug     ? " › {$tags['sub']}" : '') .
        ($yearEc      ? " › {$yearEc} E.C." : '') . "\n" .
        "🌐 {$langValue} | 📦 {$sizeStr}"
    );
}


// ════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════

function lookupId(PDO $pdo, string $table, string $col, string $val): ?int
{
    // Whitelist table/column names (never interpolate user input)
    $allowed = [
        'streams'       => ['slug'],
        'subjects'      => ['slug'],
        'content_types' => ['slug'],
        'grades'        => ['label'],
        'tg_channels'   => ['channel_username'],
    ];
    if (!isset($allowed[$table]) || !in_array($col, $allowed[$table], true)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$col}` = ? LIMIT 1");
    $stmt->execute([$val]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function dmAdmin(string $token, string $chatId, string $text): void
{
    if (!$token || !$chatId) return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode(['chat_id' => $chatId, 'text' => $text]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 5,
    ]]);
    @file_get_contents($url, false, $ctx); // fire-and-forget
}

function mimeToExt(string $mime): string
{
    return match($mime) {
        'application/pdf'    => 'pdf',
        'audio/mpeg'         => 'mp3',
        'audio/ogg'          => 'ogg',
        'text/plain'         => 'txt',
        'text/markdown'      => 'md',
        'image/jpeg'         => 'jpg',
        'image/png'          => 'png',
        default              => explode('/', $mime)[1] ?? 'bin',
    };
}
