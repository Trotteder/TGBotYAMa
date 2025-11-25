<?php
header('Content-Type: text/html; charset=utf-8');

$pidFile = __DIR__ . '/timer_checker.pid';
$status = [];
$status[] = "=== Перезапуск Timer Checker (Replit Workflow) ===\n";

$killed = false;
if (file_exists($pidFile)) {
    $fp = fopen($pidFile, 'r');
    if ($fp && flock($fp, LOCK_SH)) {
        $pid = trim(fgets($fp));
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if (!empty($pid)) {
            exec("ps -p $pid 2>&1", $psOutput, $psReturn);
            if ($psReturn === 0) {
                $status[] = "✓ Найден процесс PID: $pid";
                exec("kill -9 $pid 2>&1", $killOutput);
                $status[] = "✓ Процесс остановлен";
                $killed = true;
                sleep(2);
            } else {
                $status[] = "⚠ Процесс PID $pid уже не работает";
            }
        }
    }
}

if (!$killed) {
    exec("ps aux | grep 'php timer_checker.php' | grep -v grep", $processes);
    if (!empty($processes)) {
        foreach ($processes as $proc) {
            if (preg_match('/^\S+\s+(\d+)/', $proc, $matches)) {
                $pid = $matches[1];
                exec("kill -9 $pid 2>&1");
                $status[] = "✓ Найден и остановлен процесс PID: $pid";
                $killed = true;
            }
        }
        sleep(2);
    }
}

if ($killed) {
    $status[] = "\n⏳ Ожидание автоматического перезапуска Replit Workflow...";
    sleep(3);
    
    if (file_exists($pidFile)) {
        $fp = fopen($pidFile, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            $newPid = trim(fgets($fp));
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if (!empty($newPid)) {
                exec("ps -p $newPid 2>&1", $psOutput, $psReturn);
                if ($psReturn === 0) {
                    $status[] = "✅ Timer Checker перезапущен автоматически!";
                    $status[] = "✅ Новый PID: $newPid";
                } else {
                    $status[] = "⚠ Ожидание запуска... (обновите страницу через 5 сек)";
                }
            }
        }
    }
    
    if (file_exists(__DIR__ . '/timer_checker.log')) {
        $logLines = file(__DIR__ . '/timer_checker.log');
        $recentLines = array_slice($logLines, -5);
        $status[] = "\n--- Последние строки лога ---";
        foreach ($recentLines as $line) {
            $status[] = rtrim($line);
        }
    }
} else {
    $status[] = "⚠ Timer Checker не найден";
    $status[] = "Возможно он уже перезапускается...";
}

$activeGames = count(glob(__DIR__ . '/sessions/game_*.json'));
$status[] = "\n✓ Активных игр: $activeGames";

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Checker - Перезапуск</title>
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
            color: #4299e1;
            padding: 30px;
            border-radius: 8px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        h1 {
            color: #4299e1;
            margin-bottom: 20px;
            font-size: 20px;
        }
        pre {
            background: #0d1117;
            padding: 20px;
            border-radius: 4px;
            overflow-x: auto;
            line-height: 1.6;
            border-left: 3px solid #4299e1;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        a {
            background: #4299e1;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s;
            display: inline-block;
        }
        a:hover {
            background: #63b3ed;
            transform: translateY(-2px);
        }
        .info {
            background: #2d3748;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 3px solid #f6ad55;
        }
        .info p {
            color: #f6ad55;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Timer Checker - Перезапуск</h1>
        <div class="info">
            <p>💡 Timer-checker работает как Replit Workflow и автоматически перезапускается после остановки.</p>
        </div>
        <pre><?php echo implode("\n", $status); ?></pre>
        <div class="buttons">
            <a href="restart_timer.php">🔄 Перезапустить снова</a>
            <a href="admin.php">📊 Панель управления</a>
            <a href="/">🏠 Главная</a>
        </div>
    </div>
</body>
</html>
