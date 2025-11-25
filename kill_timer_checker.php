<?php
/**
 * Скрипт для остановки всех процессов timer_checker.php
 * Использование: php kill_timer_checker.php
 */

echo "Поиск запущенных процессов timer_checker.php...\n";

// Найти все процессы timer_checker
$output = [];
exec("ps aux | grep timer_checker.php | grep -v grep | grep -v kill_timer_checker", $output);

if (empty($output)) {
    echo "✓ Процессы timer_checker.php не найдены\n";
    
    // Проверить и удалить PID-файл если он есть
    $pidFile = __DIR__ . '/timer_checker.pid';
    if (file_exists($pidFile)) {
        unlink($pidFile);
        echo "✓ Удален старый PID-файл\n";
    }
    
    exit(0);
}

echo "Найдено процессов: " . count($output) . "\n\n";

// Показать найденные процессы
foreach ($output as $line) {
    echo "  " . $line . "\n";
}

echo "\n";

// Убить все процессы
$killed = 0;
exec("pkill -f timer_checker.php", $killOutput, $returnCode);

if ($returnCode === 0 || $returnCode === 1) {
    // Подождать немного
    sleep(1);
    
    // Проверить что процессы убиты
    $check = [];
    exec("ps aux | grep timer_checker.php | grep -v grep | grep -v kill_timer_checker", $check);
    
    if (empty($check)) {
        echo "✓ Все процессы timer_checker.php успешно остановлены\n";
        
        // Удалить PID-файл
        $pidFile = __DIR__ . '/timer_checker.pid';
        if (file_exists($pidFile)) {
            unlink($pidFile);
            echo "✓ PID-файл удален\n";
        }
    } else {
        echo "⚠ Некоторые процессы все еще работают, пробую kill -9...\n";
        exec("pkill -9 -f timer_checker.php");
        sleep(1);
        
        $finalCheck = [];
        exec("ps aux | grep timer_checker.php | grep -v grep | grep -v kill_timer_checker", $finalCheck);
        
        if (empty($finalCheck)) {
            echo "✓ Процессы принудительно остановлены\n";
            
            $pidFile = __DIR__ . '/timer_checker.pid';
            if (file_exists($pidFile)) {
                unlink($pidFile);
                echo "✓ PID-файл удален\n";
            }
        } else {
            echo "✗ Не удалось остановить некоторые процессы:\n";
            foreach ($finalCheck as $line) {
                echo "  " . $line . "\n";
            }
            exit(1);
        }
    }
} else {
    echo "✗ Ошибка при остановке процессов (код: $returnCode)\n";
    exit(1);
}

echo "\nГотово! Теперь можно запустить timer_checker.php заново.\n";
