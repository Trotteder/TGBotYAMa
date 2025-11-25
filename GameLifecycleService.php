<?php

require_once 'TelegramAPI.php';
require_once 'Game.php';
require_once 'Logger.php';

class GameLifecycleService {
    private $telegram;
    private $sessionsDir;
    
    public function __construct(TelegramAPI $telegram, $sessionsDir = null) {
        $this->telegram = $telegram;
        $this->sessionsDir = $sessionsDir ?? __DIR__ . '/sessions';
    }
    
    public function handleRegistrationTimeout(Game $game) {
        $chatId = $game->getChatId();
        $timerMessageId = $game->getTimerMessageId();
        
        if ($timerMessageId) {
            $deleteResult = $this->telegram->deleteMessage($chatId, $timerMessageId);
            $game->clearTimerMessageId();
            
            // Если не удалось удалить и это не "сообщение не найдено" - добавим в очередь
            if (!$deleteResult || !isset($deleteResult['ok']) || !$deleteResult['ok']) {
                $errorDesc = isset($deleteResult['description']) ? $deleteResult['description'] : '';
                $isMessageNotFound = strpos($errorDesc, 'message to delete not found') !== false;
                
                if (!$isMessageNotFound) {
                    $game->addMessageForDeletion($timerMessageId, 1);
                }
            }
        }
        
        if (count($game->getPlayers()) < 4) {
            $this->deleteGame($game);
            $this->telegram->sendMessage($chatId, 
                "⏱ <b>Время регистрации истекло!</b>\n\n" .
                "За отведенное время не удалось собрать четырёх игроков, лобби удалено.",
                $this->getMainMenu()
            );
        } else {
            $this->startGameFromRegistration($game);
        }
    }
    
    public function handleNightTimeout(Game $game) {
        $this->processNight($game);
    }
    
    public function handleDiscussionTimeout(Game $game) {
        $this->processDiscussionTimeout($game);
    }
    
    public function handleVoteTimeout(Game $game) {
        $this->processVote($game);
    }
    
    private function startGameFromRegistration(Game $game) {
        $chatId = $game->getChatId();
        $game->setPhase(Game::PHASE_NIGHT);
        $game->setDay(1);
        $this->saveGame($game);
        
        $botUsername = getenv('BOT_USERNAME') ?: 'bot';
        $botUrl = "https://t.me/" . $botUsername;
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🤖 Перейти к боту', 'url' => $botUrl]
                ]
            ]
        ];
        
        $this->telegram->sendMessage($chatId, 
            "🎮 <b>Игра начинается!</b>\n\n" .
            "Распределяю роли и отправляю личные сообщения...",
            $keyboard
        );
        
        $game->assignRoles();
        $this->saveGame($game);
        
        foreach ($game->getPlayers() as $player) {
            $roleEmoji = $this->getRoleEmoji($player['role']);
            $roleDescription = $this->getRoleDescription($player['role']);
            
            $this->telegram->sendMessage($player['user_id'],
                "$roleEmoji <b>Ваша роль: " . $this->getRoleName($player['role']) . "</b>\n\n" .
                $roleDescription
            );
        }
        
        $this->startNight($game);
    }
    
    private function startNight(Game $game) {
        $chatId = $game->getChatId();
        $nightImagePath = __DIR__ . '/attached_assets/stock_images/night_moon_stars_dar_23032965.jpg';
        
        if (file_exists($nightImagePath)) {
            $this->telegram->sendPhoto($chatId, $nightImagePath, 
                "🌙 <b>Наступает ночь #" . $game->getDay() . "</b>\n\n" .
                "Город засыпает... Особые роли, действуйте!"
            );
        }
        
        $game->beginNight();
        $this->saveGame($game);
        
        $activePlayers = array_filter($game->getPlayers(), function($p) {
            return $p['alive'];
        });
        
        $playersList = array_map(function($p) {
            return $p['name'];
        }, $activePlayers);
        
        $this->telegram->sendMessage($chatId,
            "🌙 <b>Ночь #" . $game->getDay() . "</b>\n\n" .
            "Живые игроки (" . count($activePlayers) . "):\n" .
            implode("\n", array_map(function($name) { return "• $name"; }, $playersList)) . "\n\n" .
            "⏱ Время на действия: 3 минуты",
            $this->getGameMenu()
        );
        
        $failedPlayers = [];
        
        foreach ($game->getPlayers() as $player) {
            if (!$player['alive'] || $game->isFrozen($player['user_id'])) {
                continue;
            }
            
            $result = null;
            
            if ($player['role'] === Game::ROLE_MAFIA || $player['role'] === Game::ROLE_DON) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'kill');
                $rolePrefix = ($player['role'] === Game::ROLE_DON) ? "🎩" : "🔫";
                $result = $this->telegram->sendMessage($player['user_id'],
                    "$rolePrefix <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Выберите жертву для убийства:",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_MANIAC) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'kill');
                $result = $this->telegram->sendMessage($player['user_id'],
                    "🔪 <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Выберите жертву для убийства:",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_LAWYER) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'protect');
                $result = $this->telegram->sendMessage($player['user_id'],
                    "⚖️ <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Выберите подзащитного (будет выглядеть как мирный при проверках):",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_DETECTIVE) {
                $keyboard = $this->getCommissarActionKeyboard($game, $player['user_id']);
                $result = $this->telegram->sendMessage($player['user_id'],
                    "🕵️ <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Выберите действие:",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_DOCTOR) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'heal');
                $result = $this->telegram->sendMessage($player['user_id'],
                    "💉 <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Кого будете защищать?",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_HOMELESS) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'check_mafia');
                $result = $this->telegram->sendMessage($player['user_id'],
                    "🔍 <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Кого хотите проверить на принадлежность к мафии?",
                    $keyboard
                );
            } elseif ($player['role'] === Game::ROLE_LOVER) {
                $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'freeze');
                $result = $this->telegram->sendMessage($player['user_id'],
                    "💋 <b>Ночь #" . $game->getDay() . "</b>\n\n" .
                    "Кого хотите заморозить на 2 хода?",
                    $keyboard
                );
            }
            
            if ($result === false || (isset($result['ok']) && !$result['ok'])) {
                $failedPlayers[] = $player['name'];
                Logger::log("GameLifecycleService: Failed to send night action to {$player['name']}");
            }
        }
        
        if (!empty($failedPlayers)) {
            $playerList = implode(', ', $failedPlayers);
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Внимание!</b>\n\n" .
                "Следующие игроки не получили кнопки ночных действий, так как не начали диалог с ботом:\n\n" .
                "$playerList\n\n" .
                "Напишите /start боту в личных сообщениях!"
            );
        }
    }
    
    private function processNight(Game $game) {
        $chatId = $game->getChatId();
        
        $result = $game->processNight();
        
        $killed = !empty($result['killed']) ? $result['killed'] : [];
        $frozen = $result['frozen'] ?? null;
        $newDon = $result['new_don'] ?? null;
        $luckySurvived = $result['lucky_survived'] ?? null;
        
        if ($newDon) {
            $newDonPlayer = $game->getPlayerById($newDon);
            $this->telegram->sendMessage($newDon,
                "🎩 <b>Вы стали новым Доном!</b>\n\n" .
                "Старый Дон погиб, и теперь вы возглавляете мафию. Ваш голос решающий при выборе жертвы."
            );
        }
        
        $killedId = !empty($killed) ? $killed[0] : null;
        
        $this->beginDiscussion($game, $killedId, $frozen, $result);
    }
    
    private function beginDiscussion(Game $game, $killedId = null, $frozenId = null, $nightResult = []) {
        $chatId = $game->getChatId();
        $game->beginDiscussion();
        if (!$this->saveGame($game)) {
            Logger::log("GameLifecycleService: Failed to save game in beginDiscussion, aborting");
            return;
        }
        
        $dayImagePath = __DIR__ . '/attached_assets/stock_images/day_sunrise_morning_23032966.jpg';
        if (file_exists($dayImagePath)) {
            $this->telegram->sendPhoto($chatId, $dayImagePath,
                "☀️ <b>Наступает день #" . $game->getDay() . "</b>"
            );
        }
        
        $message = "☀️ <b>День #" . $game->getDay() . "</b>\n\n";
        
        $luckySurvived = $nightResult['lucky_survived'] ?? null;
        $saved = $nightResult['saved'] ?? false;
        
        if ($killedId) {
            $victim = $game->getPlayerById($killedId);
            $message .= "😵 Этой ночью был убит <b>" . $victim['name'] . "</b>\n";
            $message .= "Его роль: " . $this->getRoleEmoji($victim['role']) . " " . $this->getRoleName($victim['role']) . "\n\n";
        } elseif ($luckySurvived) {
            $luckyPlayer = $game->getPlayerById($luckySurvived);
            $message .= "🍀 <b>" . $luckyPlayer['name'] . "</b> чудом выжил этой ночью!\n\n";
        } elseif ($saved) {
            $message .= "💉 Доктор спас жизнь этой ночью!\n\n";
        } else {
            $message .= "✨ Этой ночью никто не пострадал!\n\n";
        }
        
        if ($frozenId) {
            $frozenPlayer = $game->getPlayerById($frozenId);
            if ($frozenPlayer && $frozenPlayer['alive']) {
                $message .= "❄️ <b>" . $frozenPlayer['name'] . "</b> заморожен на 2 хода!\n\n";
            }
        }
        
        $activePlayers = array_filter($game->getPlayers(), function($p) {
            return $p['alive'];
        });
        
        $message .= "Живых игроков: " . count($activePlayers) . "\n\n";
        $message .= "⏱ Время на обсуждение: 5 секунд";
        
        $this->telegram->sendMessage($chatId, $message, $this->getGameMenu());
        
        $winner = $game->checkWinCondition();
        if ($winner) {
            $this->endGame($game, $winner);
        }
    }
    
    private function processDiscussionTimeout(Game $game) {
        $chatId = $game->getChatId();
        $game->beginVote();
        if (!$this->saveGame($game)) {
            Logger::log("GameLifecycleService: Failed to save game in processDiscussionTimeout, aborting");
            return;
        }
        
        $botUsername = getenv('BOT_USERNAME') ?: 'bot';
        $botUrl = "https://t.me/" . $botUsername;
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗳 Голосовать', 'url' => $botUrl]
                ]
            ]
        ];
        
        $this->telegram->sendMessage($chatId,
            "🗳 <b>Начинается голосование!</b>\n\n" .
            "Город решает, кого исключить из игры.\n" .
            "⏱ Время на голосование: 4 минуты",
            $keyboard
        );
        
        $failedPlayers = [];
        
        foreach ($game->getPlayers() as $player) {
            if (!$player['alive'] || $game->isFrozen($player['user_id'])) {
                Logger::log("GameLifecycleService: Skipping player {$player['name']} - alive=" . ($player['alive'] ? 'yes' : 'no') . ", frozen=" . ($game->isFrozen($player['user_id']) ? 'yes' : 'no'));
                continue;
            }
            
            Logger::log("GameLifecycleService: Sending vote keyboard to {$player['name']} (ID: {$player['user_id']})");
            $keyboard = $this->getTargetKeyboard($game, $player['user_id'], 'vote');
            $result = $this->telegram->sendMessage($player['user_id'],
                "🗳 <b>Голосование (День #" . $game->getDay() . ")</b>\n\n" .
                "За кого голосуете?",
                $keyboard
            );
            Logger::log("GameLifecycleService: sendMessage result: " . json_encode($result));
            
            if (!$result || !isset($result['ok']) || !$result['ok']) {
                $failedPlayers[] = $player['name'];
                Logger::log("GameLifecycleService: Failed to send vote message to {$player['name']}");
            }
        }
        
        if (!empty($failedPlayers)) {
            $playerList = implode(', ', $failedPlayers);
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Внимание!</b>\n\n" .
                "Следующие игроки не получили кнопки для голосования, так как не начали диалог с ботом:\n\n" .
                "$playerList\n\n" .
                "Напишите /start боту @" . getenv('BOT_USERNAME') . " в личных сообщениях!"
            );
        }
    }
    
    private function processVote(Game $game) {
        $chatId = $game->getChatId();
        
        $result = $game->processVote();
        $excluded = $result['eliminated'] ?? null;
        $newDon = $result['new_don'] ?? null;
        $suicideWin = $result['suicide_win'] ?? false;
        $kamikazeVictim = $result['kamikaze_victim'] ?? null;
        
        if ($excluded) {
            $victim = $game->getPlayerById($excluded);
            $this->telegram->sendMessage($chatId,
                "⚖️ <b>Результаты голосования</b>\n\n" .
                "Город решил исключить: <b>" . $victim['name'] . "</b>\n" .
                "Его роль: " . $this->getRoleEmoji($victim['role']) . " " . $this->getRoleName($victim['role']),
                $this->getGameMenu()
            );
            
            // Камикадзе забирает с собой случайного игрока
            if ($kamikazeVictim) {
                $kamikazeVictimPlayer = $game->getPlayerById($kamikazeVictim);
                $this->telegram->sendMessage($chatId,
                    "💣 <b>Взрыв!</b>\n\n" .
                    "Камикадзе забрал с собой: <b>" . $kamikazeVictimPlayer['name'] . "</b>\n" .
                    "Его роль: " . $this->getRoleEmoji($kamikazeVictimPlayer['role']) . " " . $this->getRoleName($kamikazeVictimPlayer['role']),
                    $this->getGameMenu()
                );
            }
            
            if ($newDon) {
                $this->telegram->sendMessage($newDon,
                    "🎩 <b>Вы стали новым Доном!</b>\n\n" .
                    "Ваш голос теперь решающий при выборе жертвы мафии."
                );
            }
            
            if ($suicideWin) {
                $this->endGame($game, 'suicide');
                return;
            }
        } else {
            $this->telegram->sendMessage($chatId,
                "⚖️ <b>Результаты голосования</b>\n\n" .
                "Город не смог принять решение. Никто не исключен.",
                $this->getGameMenu()
            );
        }
        
        $winner = $game->checkWinCondition();
        if ($winner) {
            $this->endGame($game, $winner);
            return;
        }
        
        $this->saveGame($game);
        
        $this->startNight($game);
    }
    
    private function endGame(Game $game, $winner) {
        $chatId = $game->getChatId();
        $game->setPhase(Game::PHASE_ENDED);
        $this->saveGame($game);
        
        $winnerTexts = [
            'mafia' => '🔫 <b>Победа мафии!</b>',
            'citizens' => '👥 <b>Победа мирных жителей!</b>',
            'maniac' => '🔪 <b>Победа маньяка!</b>',
            'suicide' => '💀 <b>Победа самоубийцы!</b>'
        ];
        
        $winnerText = $winnerTexts[$winner] ?? '🎮 <b>Игра завершена!</b>';
        
        $roles = "\n\n📋 <b>Роли игроков:</b>\n";
        foreach ($game->getPlayers() as $player) {
            $status = $player['alive'] ? '✅' : '💀';
            $roles .= "$status " . $player['name'] . " - " . $this->getRoleEmoji($player['role']) . " " . $this->getRoleName($player['role']) . "\n";
        }
        
        $this->telegram->sendMessage($chatId,
            "$winnerText\n" .
            "Игра завершена!\n" .
            $roles,
            $this->getMainMenu()
        );
        
        $this->deleteOldGames($chatId, $game->getGameId());
    }
    
    private function deleteGame(Game $game) {
        $file = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    private function deleteOldGames($chatId, $currentGameId) {
        $files = glob($this->sessionsDir . '/game_' . $chatId . '_*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['gameId'] !== $currentGameId) {
                unlink($file);
            }
        }
    }
    
    private function saveGame(Game $game, $expectedPhase = null) {
        $file = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        $fp = fopen($file, 'c+');
        if (!$fp) {
            Logger::log("GameLifecycleService: Cannot open file for writing: $file");
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            Logger::log("GameLifecycleService: Cannot acquire lock for: $file");
            fclose($fp);
            return false;
        }
        
        $preservedTimerFlags = [];
        if (file_exists($file) && filesize($file) > 0) {
            rewind($fp);
            $currentContent = stream_get_contents($fp);
            $currentData = json_decode($currentContent, true);
            
            if ($expectedPhase !== null && $currentData && isset($currentData['phase']) && $currentData['phase'] !== $expectedPhase) {
                Logger::log("GameLifecycleService: Phase mismatch - expected $expectedPhase, got " . $currentData['phase']);
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            if ($currentData) {
                $preservedTimerFlags['notified_60'] = $currentData['notified_60'] ?? false;
                $preservedTimerFlags['notified_30'] = $currentData['notified_30'] ?? false;
                $preservedTimerFlags['timeout_sent'] = $currentData['timeout_sent'] ?? false;
                $preservedTimerFlags['timer_message_id'] = $currentData['timer_message_id'] ?? null;
            }
        }
        
        $newPhase = $game->getPhase();
        Logger::log("GameLifecycleService: Saving game with phase=$newPhase to $file");
        
        $gameData = $game->toArray();
        if (!empty($preservedTimerFlags)) {
            $gameData = array_merge($gameData, $preservedTimerFlags);
        }
        
        rewind($fp);
        ftruncate($fp, 0);
        $written = fwrite($fp, json_encode($gameData));
        if ($written === false) {
            Logger::log("GameLifecycleService: Failed to write to file");
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        Logger::log("GameLifecycleService: Successfully saved game, phase=$newPhase");
        return true;
    }
    
    private function getRoleEmoji($role) {
        $emojis = [
            Game::ROLE_MAFIA => '🔫',
            Game::ROLE_CITIZEN => '👤',
            Game::ROLE_DETECTIVE => '🕵️',
            Game::ROLE_DOCTOR => '💉',
            Game::ROLE_HOMELESS => '🔍',
            Game::ROLE_LOVER => '💋',
            Game::ROLE_DON => '🎩',
            Game::ROLE_MANIAC => '🔪',
            Game::ROLE_LAWYER => '⚖️',
            Game::ROLE_SUICIDE => '💀',
            Game::ROLE_LUCKY => '🍀',
            Game::ROLE_KAMIKAZE => '💣'
        ];
        return $emojis[$role] ?? '❓';
    }
    
    private function getRoleDescription($role) {
        $descriptions = [
            Game::ROLE_MAFIA => "Вы - член мафии. Каждую ночь вы выбираете жертву вместе с семьей. Ваша цель - уничтожить всех мирных жителей.",
            Game::ROLE_CITIZEN => "Вы - мирный житель. Днем голосуйте за исключение подозрительных игроков.",
            Game::ROLE_DETECTIVE => "Вы - комиссар. Ночью можете ПРОВЕРИТЬ или УБИТЬ одного игрока.",
            Game::ROLE_DOCTOR => "Вы - доктор. Ночью защищаете одного игрока от убийства.",
            Game::ROLE_HOMELESS => "Вы - бомж. Ночью можете проверить игрока на принадлежность к мафии.",
            Game::ROLE_LOVER => "Вы - любовница. Можете заморозить игрока на 2 хода.",
            Game::ROLE_DON => "Вы - Дон мафии. Ваш голос решающий при выборе жертвы. Если вас убьют, один из мафиози станет новым Доном.",
            Game::ROLE_MANIAC => "Вы - маньяк. Убиваете каждую ночь одного игрока. Играете сам за себя - ваша цель убить всех!",
            Game::ROLE_LAWYER => "Вы - адвокат. Ночью выбираете подзащитного. Комиссар и бомж увидят его как мирного жителя.",
            Game::ROLE_SUICIDE => "Вы - самоубийца. Ваша цель - погибнуть при дневном голосовании. Только тогда вы победите!",
            Game::ROLE_LUCKY => "Вы - счастливчик. При покушении у вас 50% шанс выжить.",
            Game::ROLE_KAMIKAZE => "Вы - камикадзе. Если вас линчуют днём, вы можете забрать с собой одного игрока."
        ];
        return $descriptions[$role] ?? "Неизвестная роль.";
    }
    
    private function getRoleName($role) {
        $names = [
            Game::ROLE_MAFIA => 'Мафия',
            Game::ROLE_CITIZEN => 'Мирный житель',
            Game::ROLE_DETECTIVE => 'Комиссар',
            Game::ROLE_DOCTOR => 'Доктор',
            Game::ROLE_HOMELESS => 'Бомж',
            Game::ROLE_LOVER => 'Любовница',
            Game::ROLE_DON => 'Дон',
            Game::ROLE_MANIAC => 'Маньяк',
            Game::ROLE_LAWYER => 'Адвокат',
            Game::ROLE_SUICIDE => 'Самоубийца',
            Game::ROLE_LUCKY => 'Счастливчик',
            Game::ROLE_KAMIKAZE => 'Камикадзе'
        ];
        return $names[$role] ?? 'Неизвестная роль';
    }
    
    private function getMainMenu() {
        return null;
    }
    
    private function getGameMenu() {
        return null;
    }
    
    private function getTargetKeyboard($game, $userId, $action) {
        $buttons = [];
        $groupChatId = $game->getChatId();
        $gameId = $game->getGameId();
        
        Logger::log("GameLifecycleService: Creating target keyboard for action=$action, userId=$userId");
        
        foreach ($game->getPlayers() as $player) {
            Logger::log("GameLifecycleService: Player check - name={$player['name']}, alive=" . ($player['alive'] ? 'yes' : 'no') . ", userId={$player['user_id']}");
            
            if (!$player['alive']) continue;
            if ($player['user_id'] == $userId) continue;
            
            $buttons[] = [
                [
                    'text' => $player['name'],
                    'callback_data' => $action . '_' . $player['user_id'] . '_' . $groupChatId . '_' . $gameId
                ]
            ];
            
            Logger::log("GameLifecycleService: Added button for {$player['name']}");
        }

        Logger::log("GameLifecycleService: Total buttons created: " . count($buttons));
        Logger::log("GameLifecycleService: Keyboard structure: " . json_encode(['inline_keyboard' => $buttons]));
        
        return ['inline_keyboard' => $buttons];
    }
    
    private function getCommissarActionKeyboard($game, $userId) {
        $groupChatId = $game->getChatId();
        $gameId = $game->getGameId();
        
        return ['inline_keyboard' => [
            [['text' => '🔍 Проверить', 'callback_data' => 'detectivecheck_' . $groupChatId . '_' . $gameId]],
            [['text' => '🔫 Убить', 'callback_data' => 'detectivekillaction_' . $groupChatId . '_' . $gameId]]
        ]];
    }
}
