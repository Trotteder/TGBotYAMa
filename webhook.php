<?php

require_once 'bot.php';

$token = getenv('TELEGRAM_BOT_TOKEN');

if (!$token) {
    http_response_code(500);
    die('TELEGRAM_BOT_TOKEN not set');
}

$bot = new MafiaBot($token);

$input = file_get_contents('php://input');
file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . " Received webhook, input length: " . strlen($input) . "\n", FILE_APPEND);

$update = json_decode($input, true);

if ($update) {
    file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . " Update decoded: " . json_encode($update) . "\n", FILE_APPEND);
    $bot->handleUpdate($update);
} else {
    file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . " Failed to decode update\n", FILE_APPEND);
}

http_response_code(200);
echo 'OK';
