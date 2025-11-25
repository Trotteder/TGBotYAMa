<?php
/**
 * Скрипт для проверки статуса timer_checker.php
 * Использование: php check_timer_status.php
 */

echo "=== Статус timer_checker.php ===\n\n";

// Проверить запущенные процессы
$output = [];
exec("ps aux | grep timer_checker.php | grep -v grep | grep -v check_timer_status", $output);

if (empty($output)) {
    echo "✗ Процессы timer_checker.php не запущены\n";
    $running = false;
} else {
    echo "✓ Найдено процессов: " . count($output) . "\n\n";
    foreach ($output as $line) {
        // Парсим PID из вывода ps
        if (preg_match('/^\S+\s+(\d+)/', $line, $matches)) {
            $pid = $matches[1];
            echo "  PID: $pid\n";
            echo "  " . $line . "\n\n";
        }
    }
    $running = true;
}

// Проверить PID-файл
$pidFile = __DIR__ . '/timer_checker.pid';
if (file_exists($pidFile)) {
    $storedPid = trim(file_get_contents($pidFile));
    echo "PID-файл существует: $storedPid\n";
    
    // Проверить существует ли процесс с этим PID
    $processExists = file_exists("/proc/$storedPid");
    
    if ($processExists) {
        echo "✓ Процесс с PID $storedPid активен\n";
    } else {
        echo "⚠ Процесс с PID $storedPid не найден (stale PID-файл)\n";
        echo "  Рекомендуется удалить PID-файл и перезапустить\n";
    }
} else {
    echo "PID-файл не существует\n";
}

// Проверить последние записи в логе
$logFile = __DIR__ . '/timer_checker.log';
if (file_exists($logFile)) {
    echo "\n--- Последние 5 строк из лога ---\n";
    $lines = file($logFile);
    $lastLines = array_slice($lines, -5);
    foreach ($lastLines as $line) {
        echo $line;
    }
} else {
    echo "\nЛог-файл не найден\n";
}

// Итоговый статус
echo "\n=== Итого ===\n";
if ($running) {
    echo "✓ timer_checker.php работает нормально\n";
} else {
    echo "✗ timer_checker.php не запущен\n";
    echo "  Для запуска: php timer_checker.php &\n";
    echo "  Или через nohup: nohup php timer_checker.php > /dev/null 2>&1 &\n";
}
