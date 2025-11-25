<?php
require_once 'TelegramAPI.php';
require_once 'Game.php';
require_once 'GameLifecycleService.php';

$botToken = getenv('TELEGRAM_BOT_TOKEN');
if (!$botToken) {
    die("Error: TELEGRAM_BOT_TOKEN not set\n");
}

$telegram = new TelegramAPI($botToken);
$lifecycleService = new GameLifecycleService($telegram);
$sessionsDir = __DIR__ . '/sessions';
$logFile = __DIR__ . '/timer_checker.log';
$pidFile = __DIR__ . '/timer_checker.pid';

if (!is_dir($sessionsDir)) {
    mkdir($sessionsDir, 0777, true);
}

function logTimer($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$lockFp = fopen($pidFile, 'c+');
if (!$lockFp) {
    die("Cannot open PID file\n");
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    logTimer("Timer checker already running (lock held), exiting");
    fclose($lockFp);
    die("Timer checker already running\n");
}

ftruncate($lockFp, 0);
fwrite($lockFp, getmypid());
fflush($lockFp);

register_shutdown_function(function() use ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    logTimer("Released lock on shutdown");
});

logTimer("Timer checker started with PID " . getmypid());

while (true) {
    try {
        $files = glob($sessionsDir . '/game_*_*.json');
        logTimer("Checking " . count($files) . " game files new");
        
        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $fp = fopen($file, 'c+');
            if (!$fp) {
                logTimer("Cannot open file $file");
                continue;
            }
            if (!flock($fp, LOCK_EX)) {
                logTimer("Cannot acquire exclusive lock for $file");
                fclose($fp);
                continue;
            }
            
            rewind($fp);
            $content = stream_get_contents($fp);
            $data = json_decode($content, true);
            if (!$data) {
                logTimer("Skipping invalid JSON in $file");
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }
            
            $game = Game::fromArray($data);
            $chatId = $game->getChatId();
            $gameId = $game->getGameId();
            $phase = $game->getPhase();
            $remaining = $game->getTimeRemaining();
            
            logTimer("Game $gameId: phase=$phase, remaining={$remaining}s, timerMessageId=" . ($game->getTimerMessageId() ?? 'none') . ", timeoutSent=" . ($game->isTimeoutSent() ? 'yes' : 'no'));
            
            if ($game->getPhase() === Game::PHASE_ENDED || $game->getPhase() === Game::PHASE_WAITING) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }
            
            if ($game->getPhase() !== Game::PHASE_REGISTRATION && 
                $game->getPhase() !== Game::PHASE_NIGHT && 
                $game->getPhase() !== Game::PHASE_DISCUSSION && 
                $game->getPhase() !== Game::PHASE_VOTE) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }
            
            $needsSave = false;
            $phaseDuration = $game->getActionDeadline() - $game->getPhaseStartTime();
            
            if ($remaining <= 60 && $remaining > 0) {
                $timerMessageId = $game->getTimerMessageId();
                $secondsText = $remaining === 1 ? "секунда" : ($remaining < 5 ? "секунды" : "секунд");
                $newText = "⏰ Осталось $remaining $secondsText!";
                
                if (!$timerMessageId) {
                    logTimer("Creating countdown timer for game $gameId (remaining: {$remaining}s)");
                    $result = $telegram->sendMessage($chatId, $newText);
                    if ($result && isset($result['result']['message_id'])) {
                        $game->setTimerMessageId($result['result']['message_id']);
                        $needsSave = true;
                        logTimer("Created timer message ID: " . $result['result']['message_id']);
                    }
                } else {
                    logTimer("Updating countdown timer for game $gameId: {$remaining}s");
                    $result = $telegram->editMessageText($chatId, $timerMessageId, $newText);
                    
                    if (!$result || !isset($result['ok']) || !$result['ok']) {
                        $errorDesc = isset($result['description']) ? $result['description'] : '';
                        
                        if (strpos($errorDesc, 'message to edit not found') !== false || 
                            strpos($errorDesc, 'message to delete not found') !== false ||
                            strpos($errorDesc, "message can't be edited") !== false ||
                            strpos($errorDesc, 'message is not modified') !== false) {
                            logTimer("Timer message $timerMessageId not found or can't be edited - clearing ID");
                            $game->clearTimerMessageId();
                            $needsSave = true;
                        } else {
                            logTimer("Temporary error updating timer $timerMessageId: " . ($errorDesc ?: 'connection issue') . " - will retry");
                        }
                    }
                }
            } elseif ($remaining > 60) {
                // Если время > 60 секунд и есть таймер - удаляем его
                $timerMessageId = $game->getTimerMessageId();
                if ($timerMessageId) {
                    logTimer("Removing countdown timer for game $gameId (time extended above 60s)");
                    $telegram->deleteMessage($chatId, $timerMessageId);
                    $game->clearTimerMessageId();
                    $needsSave = true;
                }
            }
            
            $messagesForDeletion = $game->getMessagesForDeletion();
            $currentTime = time();
            foreach ($messagesForDeletion as $messageId => $deleteTime) {
                if ($currentTime >= $deleteTime) {
                    logTimer("Attempting to delete message $messageId for game $gameId");
                    $result = $telegram->deleteMessage($chatId, $messageId);
                    if ($result && isset($result['ok']) && $result['ok']) {
                        logTimer("Successfully deleted message $messageId");
                        $game->removeMessageFromDeletion($messageId);
                        $needsSave = true;
                    } elseif ($result === false || (isset($result['ok']) && !$result['ok'])) {
                        $errorDesc = isset($result['description']) ? $result['description'] : 'Unknown error';
                        if (strpos($errorDesc, 'message to delete not found') !== false || 
                            strpos($errorDesc, "message can't be deleted") !== false) {
                            logTimer("Message $messageId already deleted or not found, removing from queue");
                            $game->removeMessageFromDeletion($messageId);
                            $needsSave = true;
                        } else {
                            logTimer("Failed to delete message $messageId: $errorDesc");
                        }
                    }
                }
            }
            
            if ($game->isTimeout() && !$game->isTimeoutSent()) {
                logTimer("Marking timeout for game $gameId, phase=$phase");
                $game->setTimeoutSent();
                $needsSave = true;
            }
            
            if ($needsSave) {
                rewind($fp);
                ftruncate($fp, 0);
                fwrite($fp, json_encode($game->toArray()));
                fflush($fp);
                logTimer("Saved updated flags for game $gameId");
            }
            
            $shouldProcessTimeout = $game->isTimeout() && $game->isTimeoutSent();
            
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if ($shouldProcessTimeout) {
                $reloadFp = fopen($file, 'r');
                if (!$reloadFp || !flock($reloadFp, LOCK_SH)) {
                    if ($reloadFp) fclose($reloadFp);
                    logTimer("Cannot reload game for timeout processing");
                    continue;
                }
                
                rewind($reloadFp);
                $reloadContent = stream_get_contents($reloadFp);
                flock($reloadFp, LOCK_UN);
                fclose($reloadFp);
                
                $reloadData = json_decode($reloadContent, true);
                if (!$reloadData) {
                    logTimer("Cannot decode reloaded game");
                    continue;
                }
                
                $freshGame = Game::fromArray($reloadData);
                
                if ($freshGame->getPhase() !== $phase) {
                    logTimer("Phase changed from $phase to " . $freshGame->getPhase() . ", skipping timeout handler");
                    continue;
                }
                
                try {
                    if ($phase === Game::PHASE_REGISTRATION) {
                        logTimer("Handling registration timeout");
                        $lifecycleService->handleRegistrationTimeout($freshGame);
                    } elseif ($phase === Game::PHASE_NIGHT) {
                        logTimer("Handling night timeout");
                        $lifecycleService->handleNightTimeout($freshGame);
                    } elseif ($phase === Game::PHASE_DISCUSSION) {
                        logTimer("Handling discussion timeout");
                        $lifecycleService->handleDiscussionTimeout($freshGame);
                    } elseif ($phase === Game::PHASE_VOTE) {
                        logTimer("Handling vote timeout");
                        $lifecycleService->handleVoteTimeout($freshGame);
                    }
                    logTimer("Successfully processed timeout for game $gameId");
                } catch (Exception $e) {
                    logTimer("ERROR processing timeout: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        logTimer("ERROR: " . $e->getMessage());
    }
    
    sleep(5);
}
