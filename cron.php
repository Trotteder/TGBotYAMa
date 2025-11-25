<?php
// Альтернативный запуск timer_checker через HTTP для хостингов без cron
// Настройте внешний cron сервис (например cron-job.org) для вызова этого файла каждую минуту

// Запретить повторный запуск
$lockFile = __DIR__ . '/timer_checker.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 50) {
        die('Already running');
    }
}
touch($lockFile);

// Запускаем timer_checker в фоне
exec('php ' . __DIR__ . '/timer_checker.php > /dev/null 2>&1 &');

// Удаляем lock файл через 50 секунд
sleep(50);
unlink($lockFile);

echo 'OK';
