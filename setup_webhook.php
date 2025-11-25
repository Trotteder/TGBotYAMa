<?php

$token = getenv('TELEGRAM_BOT_TOKEN');

if (!$token) {
    die("Error: TELEGRAM_BOT_TOKEN not set\n");
}

$webhookUrl = '';
if (isset($_SERVER['REPL_SLUG']) && isset($_SERVER['REPL_OWNER'])) {
    $webhookUrl = 'https://' . $_SERVER['REPL_SLUG'] . '.' . $_SERVER['REPL_OWNER'] . '.repl.co/webhook.php';
} elseif ($argc > 1 && !empty($argv[1])) {
    $webhookUrl = $argv[1];
} else {
    echo "Usage: php setup_webhook.php <webhook_url>\n";
    echo "Or run in Replit environment\n";
    exit(1);
}

echo "Setting webhook to: $webhookUrl\n";

$apiUrl = "https://api.telegram.org/bot{$token}/setWebhook";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'url' => $webhookUrl,
    'allowed_updates' => json_encode(['message', 'callback_query'])
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response = curl_exec($ch);

if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    die("cURL error: $error\n");
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

$result = json_decode($response, true);

if ($result && $result['ok']) {
    echo "\n✓ Webhook successfully set!\n";
    echo "Bot is ready to receive messages at: $webhookUrl\n";
} else {
    echo "\n✗ Failed to set webhook\n";
    if ($result && isset($result['description'])) {
        echo "Error: {$result['description']}\n";
    }
    exit(1);
}
