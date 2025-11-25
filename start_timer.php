<?php
header('Content-Type: text/html; charset=utf-8');

$pidFile = __DIR__ . '/timer_checker.pid';
$logFile = __DIR__ . '/timer_checker.log';
$scriptPath = __DIR__ . '/timer_checker.php';

$status = [];
$status[] = "=== Запуск Timer Checker ===\n";

if (file_exists($pidFile)) {
    $oldPid = trim(file_get_contents($pidFile));
    if (!empty($oldPid)) {
        exec("kill -0 $oldPid 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            exec("kill -9 $oldPid 2>&1");
            $status[] = "✓ Убит старый процесс PID: $oldPid";
            usleep(500000);
        }
    }
    unlink($pidFile);
}

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['file', '/dev/null', 'w'],
    2 => ['file', '/dev/null', 'w']
];

$process = proc_open(
    "php $scriptPath",
    $descriptorspec,
    $pipes,
    __DIR__,
    null
);

if (is_resource($process)) {
    $processStatus = proc_get_status($process);
    $newPid = $processStatus['pid'];
    
    proc_close($process);
    
    sleep(2);
    
    exec("ps -p $newPid 2>&1", $psOutput, $psReturn);
    if ($psReturn === 0 && count($psOutput) > 1) {
        $status[] = "✓ Timer Checker запущен успешно!";
        $status[] = "✓ PID: $newPid";
        $status[] = "✓ Лог-файл: timer_checker.log";
        
        sleep(1);
        if (file_exists($logFile)) {
            $logLines = file($logFile);
            $recentLines = array_slice($logLines, -5);
            $status[] = "\n--- Последние строки лога ---";
            $status[] = implode("", $recentLines);
        }
        
        $activeGames = count(glob(__DIR__ . '/sessions/game_*.json'));
        $status[] = "\n✓ Активных игр: $activeGames";
        
    } else {
        $status[] = "❌ Процесс не запустился";
        $status[] = "Попробуйте еще раз через несколько секунд";
    }
} else {
    $status[] = "❌ Ошибка при запуске процесса";
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Checker - Запуск</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: #1a202c;
            color: #48bb78;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        h1 {
            color: #48bb78;
            margin-bottom: 20px;
            font-size: 20px;
        }
        pre {
            background: #0d1117;
            padding: 20px;
            border-radius: 4px;
            overflow-x: auto;
            line-height: 1.6;
            border-left: 3px solid #48bb78;
        }
        .buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        a {
            background: #48bb78;
            color: #1a202c;
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s;
        }
        a:hover {
            background: #68d391;
            transform: translateY(-2px);
        }
        .reload {
            background: #667eea;
            color: white;
        }
        .reload:hover {
            background: #7c8ff0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Timer Checker Launcher</h1>
        <pre><?php echo implode("\n", $status); ?></pre>
        <div class="buttons">
            <a href="start_timer.php" class="reload">🔄 Перезапустить</a>
            <a href="admin.php">📊 Панель управления</a>
            <a href="/">🏠 Главная</a>
        </div>
    </div>
</body>
</html>
