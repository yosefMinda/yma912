<?php
// Entry point — nothing to serve here publicly.
// Telegram webhook is at /webhook.php
http_response_code(200);
echo 'ok';
