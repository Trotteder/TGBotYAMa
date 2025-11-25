<?php

$token = getenv('TELEGRAM_BOT_TOKEN');

if (!$token) {
    die('TELEGRAM_BOT_TOKEN not configured. Please set it in Replit Secrets.');
}

$webhookUrl = '';
if (isset($_SERVER['REPL_SLUG']) && isset($_SERVER['REPL_OWNER'])) {
    $webhookUrl = 'https://' . $_SERVER['REPL_SLUG'] . '.' . $_SERVER['REPL_OWNER'] . '.repl.co/webhook.php';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Mafia Bot</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 32px;
        }
        
        .emoji {
            font-size: 48px;
            margin-bottom: 20px;
            display: block;
        }
        
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .status {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .status-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .status-label {
            color: #666;
            font-weight: 500;
        }
        
        .status-value {
            color: #333;
            font-family: monospace;
        }
        
        .success {
            color: #10b981;
        }
        
        .error {
            color: #ef4444;
        }
        
        .commands {
            background: #f9fafb;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .commands h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .command {
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-family: monospace;
        }
        
        .command-name {
            color: #667eea;
            font-weight: bold;
        }
        
        .command-desc {
            color: #666;
            font-size: 14px;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <span class="emoji">🎭</span>
        <h1>Telegram Mafia Bot</h1>
        <p>Бот для игры в Мафию в Telegram</p>
        
        <div class="status">
            <div class="status-item">
                <span class="status-label">Статус:</span>
                <span class="status-value <?php echo $token ? 'success' : 'error'; ?>">
                    <?php echo $token ? '✓ Настроен' : '✗ Не настроен'; ?>
                </span>
            </div>
            <?php if ($webhookUrl): ?>
            <div class="status-item">
                <span class="status-label">Webhook URL:</span>
                <span class="status-value" style="font-size: 12px;"><?php echo htmlspecialchars($webhookUrl); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="commands">
            <h2>Команды бота</h2>
            <div class="command">
                <div class="command-name">/start</div>
                <div class="command-desc">Начать работу с ботом</div>
            </div>
            <div class="command">
                <div class="command-name">/newgame</div>
                <div class="command-desc">Создать новую игру</div>
            </div>
            <div class="command">
                <div class="command-name">/join</div>
                <div class="command-desc">Присоединиться к игре</div>
            </div>
            <div class="command">
                <div class="command-name">/players</div>
                <div class="command-desc">Список игроков</div>
            </div>
            <div class="command">
                <div class="command-name">/startgame</div>
                <div class="command-desc">Начать игру (минимум 4 игрока)</div>
            </div>
            <div class="command">
                <div class="command-name">/status</div>
                <div class="command-desc">Статус текущей игры</div>
            </div>
            <div class="command">
                <div class="command-name">/help</div>
                <div class="command-desc">Правила игры</div>
            </div>
        </div>
        
        <p style="margin-top: 20px; font-size: 14px; color: #999;">
            Для настройки webhook используйте Telegram Bot API
        </p>
    </div>
</body>
</html>
