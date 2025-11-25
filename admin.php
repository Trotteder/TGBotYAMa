<?php
session_start();

$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($action === 'restart_timer') {
    exec('php kill_timer_checker.php 2>&1', $output, $returnCode);
    sleep(2);
    exec('nohup php timer_checker.php > /dev/null 2>&1 & echo $!', $output2, $returnCode2);
    $message = "Timer checker перезапущен. PID: " . ($output2[0] ?? 'unknown');
    $messageType = 'success';
}

if ($action === 'check_status') {
    exec('php check_timer_status.php 2>&1', $output, $returnCode);
    $message = implode("\n", $output);
    $messageType = 'info';
}

if ($action === 'view_logs') {
    if (file_exists('timer_checker.log')) {
        $logs = file_get_contents('timer_checker.log');
        $logLines = explode("\n", $logs);
        $recentLogs = array_slice($logLines, -50);
        $message = implode("\n", $recentLogs);
        $messageType = 'info';
    } else {
        $message = "Лог-файл не найден";
        $messageType = 'error';
    }
}

$timerRunning = false;
$timerPid = null;
if (file_exists('timer_checker.pid')) {
    $pidContent = trim(file_get_contents('timer_checker.pid'));
    if (!empty($pidContent)) {
        exec("ps -p $pidContent 2>&1", $psOutput, $psReturn);
        $timerRunning = ($psReturn === 0 && count($psOutput) > 1);
        $timerPid = $pidContent;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель управления - Mafia Bot</title>
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
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .content {
            padding: 30px;
        }
        
        .status-box {
            background: #f7fafc;
            border-left: 4px solid <?php echo $timerRunning ? '#48bb78' : '#f56565'; ?>;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .status-box h2 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: <?php echo $timerRunning ? '#48bb78' : '#f56565'; ?>;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: <?php echo $timerRunning ? '#48bb78' : '#f56565'; ?>;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .btn {
            padding: 15px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #edf2f7;
            color: #2d3748;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 101, 101, 0.4);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #742a2a;
            border-left: 4px solid #f56565;
        }
        
        .alert-info {
            background: #ebf8ff;
            color: #2c5282;
            border-left: 4px solid #4299e1;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .info-item {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎭 Панель управления Mafia Bot</h1>
            <p>Управление процессами и мониторинг</p>
        </div>
        
        <div class="content">
            <div class="status-box">
                <h2>Статус Timer Checker</h2>
                <div class="status-indicator">
                    <span class="status-dot"></span>
                    <?php if ($timerRunning): ?>
                        Работает (PID: <?php echo $timerPid; ?>)
                    <?php else: ?>
                        Остановлен
                    <?php endif; ?>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Лог-файл</div>
                        <div class="info-value"><?php echo file_exists('timer_checker.log') ? '✓' : '✗'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">PID-файл</div>
                        <div class="info-value"><?php echo file_exists('timer_checker.pid') ? '✓' : '✗'; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Активные игры</div>
                        <div class="info-value"><?php echo count(glob('sessions/game_*.json')); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="buttons">
                <a href="start_timer.php" class="btn btn-danger">
                    🚀 Запустить Timer
                </a>
                <a href="stop_timer.php" class="btn btn-danger">
                    🛑 Остановить Timer
                </a>
                <a href="?action=check_status" class="btn btn-secondary">
                    ✓ Проверить статус
                </a>
                <a href="?action=view_logs" class="btn btn-secondary">
                    📋 Показать логи
                </a>
                <a href="/" class="btn btn-primary">
                    🏠 На главную
                </a>
            </div>
        </div>
    </div>
</body>
</html>
