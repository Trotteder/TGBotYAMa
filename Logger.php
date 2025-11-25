<?php

class Logger {
    private static $logFile = 'game.log';
    
    public static function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    public static function clear() {
        file_put_contents(self::$logFile, '');
    }
}
