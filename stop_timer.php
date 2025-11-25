<?php
header('Content-Type: text/html; charset=utf-8');

$pidFile = __DIR__ . '/timer_checker.pid';
$status = [];
$status[] = "=== Остановка Timer Checker ===\n";

if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    if (!empty($pid)) {
        exec("kill -0 $pid 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            exec("kill -9 $pid 2>&1");
            $status[] = "✓ Процесс остановлен (PID: $pid)";
            unlink($pidFile);
        } else {
            $status[] = "⚠ Процесс уже не работает (PID: $pid)";
            unlink($pidFile);
        }
    } else {
        $status[] = "⚠ PID файл пуст";
    }
} else {
    $status[] = "⚠ PID файл не найден - процесс возможно не запущен";
}

$status[] = "\n✓ Операция завершена";

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Checker - Остановка</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: #1a202c;
            color: #fc8181;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        h1 {
            color: #fc8181;
            margin-bottom: 20px;
            font-size: 20px;
        }
        pre {
            background: #0d1117;
            padding: 20px;
            border-radius: 4px;
            overflow-x: auto;
            line-height: 1.6;
            border-left: 3px solid #fc8181;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🛑 Timer Checker Stopper</h1>
        <pre><?php echo implode("\n", $status); ?></pre>
        <div class="buttons">
            <a href="start_timer.php">🚀 Запустить снова</a>
            <a href="admin.php">📊 Панель управления</a>
        </div>
    </div>
</body>
</html>
