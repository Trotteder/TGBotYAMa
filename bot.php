<?php

require_once 'TelegramAPI.php';
require_once 'Game.php';

class MafiaBot {
    private $telegram;
    private $sessionsDir;

    public function __construct($token) {
        $this->telegram = new TelegramAPI($token);
        $this->sessionsDir = __DIR__ . '/sessions';
        
        if (!is_dir($this->sessionsDir)) {
            mkdir($this->sessionsDir, 0755, true);
        }
    }

    public function handleUpdate($update) {
        if (isset($update['message'])) {
            if (isset($update['message']['timer_triggered']) && $update['message']['text'] === '/timer_timeout') {
                
                $chatId = $update['message']['chat']['id'];
                $gameId = $update['message']['game_id'] ?? null;
                
                
                if (!$gameId) {
                    return;
                }
                
                $game = $this->loadGame($chatId, $gameId);
                
                if (!$game) {
                    return;
                }
                
                
                if ($game && $game->getGameId() === $gameId && $game->isTimeout()) {
                    if ($game->getPhase() === Game::PHASE_REGISTRATION) {
                        $this->processRegistrationTimeout($chatId, $game);
                    } elseif ($game->getPhase() === Game::PHASE_NIGHT) {
                        $this->telegram->sendMessage($chatId, "⏱ Время вышло! Завершаем ночь...", $this->getGameMenu());
                        $this->processNight($chatId, $game);
                    } elseif ($game->getPhase() === Game::PHASE_DISCUSSION) {
                        $this->processDiscussionTimeout($chatId, $game);
                    } elseif ($game->getPhase() === Game::PHASE_VOTE) {
                        $this->telegram->sendMessage($chatId, "⏱ Время вышло! Подсчитываем голоса...", $this->getGameMenu());
                        $this->processVote($chatId, $game);
                    }
                }
                return;
            }
            
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }
    }

    private function handleMessage($message) {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $firstName = $message['from']['first_name'] ?? 'Игрок';
        $lastName = $message['from']['last_name'] ?? '';
        $username = $message['from']['username'] ?? '';
        $text = $message['text'] ?? '';

        $game = $this->loadGame($chatId);
        if ($game && $game->getPhase() === Game::PHASE_NIGHT && $message['chat']['type'] !== 'private') {
            if (!str_starts_with($text, '/')) {
                $this->telegram->deleteMessage($chatId, $message['message_id']);
                $this->telegram->sendMessage($chatId, "🌙 Ночью запрещено писать в чат!", $this->getGameMenu());
                return;
            }
        }

        if ($text === '/start') {
            $this->telegram->sendMessage($chatId, 
                "🎭 <b>Добро пожаловать в игру Мафия!</b>\n\n" .
                "Команды:\n" .
                "/newgame - Создать новую игру\n" .
                "/join - Присоединиться к игре\n" .
                "/players - Список игроков\n" .
                "/startgame - Начать игру\n" .
                "/endgame - Завершить игру досрочно\n" .
                "/suicide - Покинуть текущую игру\n" .
                "/status - Статус игры\n" .
                "/help - Помощь\n\n" .
                "Для игры нужно минимум 4 игрока.",
                $this->getMainMenu()
            );
        } elseif ($text === '/newgame' || $text === 'Начать игру') {
            $chatType = $message['chat']['type'] ?? 'private';
            $this->createNewGame($chatId, $userId, $firstName, $lastName, $username, $chatType);
        } elseif ($text === '/join' || $text === 'Присоединиться') {
            $this->joinGame($chatId, $userId, $firstName, $lastName, $username);
        } elseif ($text === '/players') {
            $this->showPlayers($chatId);
        } elseif ($text === '/startgame') {
            $this->startGame($chatId, $userId);
        } elseif ($text === '/endgame' || $text === 'Завершить игру') {
            $this->forceEndGame($chatId, $userId);
        } elseif ($text === '/status' || $text === 'Статус') {
            $this->showStatus($chatId);
        } elseif ($text === '/help' || $text === 'Помощь') {
            $this->showHelp($chatId);
        } elseif ($text === '/suicide') {
            $this->handleSuicide($chatId, $userId, $firstName, $lastName, $username);
        }
    }

    private function getMainMenu() {
        return null;
    }

    private function getGameMenu() {
        return null;
    }

    private function handleCallbackQuery($callbackQuery) {
        $messageId = $callbackQuery['message']['message_id'];
        $userId = $callbackQuery['from']['id'];
        $data = $callbackQuery['data'];
        

        $parts = explode('_', $data);
        $action = $parts[0];
        

        if ($action === 'start' && $parts[1] === 'game') {
            $groupChatId = $parts[2];
            $gameId = $parts[3];
            $game = $this->loadGame($groupChatId, $gameId);
            
            if (!$game || $game->getGameId() !== $gameId) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Эта игра уже закончилась');
                return;
            }
            
            if ($game->getPhase() !== Game::PHASE_REGISTRATION) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Регистрация уже завершена');
                return;
            }
            
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Запускаю игру...');
            $this->startGameFromRegistration($groupChatId, $game);
            return;
        }
        
        if ($action === 'add' && $parts[1] === 'time') {
            $groupChatId = $parts[2];
            $gameId = $parts[3];
            $game = $this->loadGame($groupChatId, $gameId);
            
            if (!$game || $game->getGameId() !== $gameId) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Эта игра уже закончилась');
                return;
            }
            
            if ($game->getPhase() !== Game::PHASE_REGISTRATION) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Регистрация уже завершена');
                return;
            }
            
            $game->extendRegistration(30);
            
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Добавлено 30 секунд');
            $result = $this->telegram->sendMessage($groupChatId, 
                "⏰ <b>Время продлено!</b>\n\n" .
                "К регистрации добавлено 30 секунд."
            );
            
            if ($result && isset($result['result']['message_id'])) {
                $game->addMessageForDeletion($result['result']['message_id'], 5);
            }
            
            $this->saveGame($game);
            return;
        }
        
        if ($action === 'join' && $parts[1] === 'game') {
            $groupChatId = $parts[2];
            $gameId = $parts[3];
            $game = $this->loadGame($groupChatId, $gameId);
            
            if (!$game || $game->getGameId() !== $gameId) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Эта игра уже закончилась');
                return;
            }
            
            if ($game->getPhase() !== Game::PHASE_REGISTRATION) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Регистрация уже завершена');
                return;
            }
            
            $firstName = $callbackQuery['from']['first_name'];
            $lastName = $callbackQuery['from']['last_name'] ?? '';
            $username = $callbackQuery['from']['username'] ?? '';
            
            if ($game->addPlayer($userId, $firstName, $lastName, $username)) {
                $this->saveGame($game);
                $playerCount = count($game->getPlayers());
                $playerFullName = Game::formatPlayerName($firstName, $lastName, $username);
                $keyboard = $this->getRegistrationKeyboard($game);
                
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Вы присоединились к игре!');
                $this->telegram->sendMessage($groupChatId, 
                    "✅ $playerFullName присоединился к игре!\n\n" .
                    "Игроков в игре: $playerCount",
                    $keyboard
                );
            } else {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Вы уже в игре!');
            }
            return;
        }

        $gameId = end($parts);
        $groupChatId = $parts[count($parts) - 2];
        
        
        $game = $this->loadGame($groupChatId, $gameId);
        
        
        if (!$game || $game->getGameId() !== $gameId) {
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Эта игра уже закончилась');
            return;
        }
        

        $chatId = $game->getChatId();
        
        

        if (!$game->isAlive($userId)) {
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Вы мертвы и не можете участвовать');
            return;
        }
        

        if ($action === 'kill' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            
            // Атомарная установка действия с защитой от race condition
            $result = $this->trySetNightAction($game, $userId, 'kill', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Цель выбрана');
            $this->telegram->editMessageText($userId, $messageId, "✅ Цель выбрана: " . $target['name']);
            $this->telegram->sendMessage($chatId, "🔪 Мафия сделала свой выбор...", $this->getGameMenu());
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'save' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            $result = $this->trySetNightAction($game, $userId, 'save', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Игрок будет защищен');
            $this->telegram->editMessageText($userId, $messageId, "✅ Защищаете: " . $target['name']);
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'check' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            $role = $game->getPlayerRole($userId);
            $result = $this->trySetNightAction($game, $userId, 'check', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Игрок проверен');
            $this->telegram->editMessageText($userId, $messageId, "✅ Проверяете: " . $target['name']);
            
            if ($role === Game::ROLE_DETECTIVE) {
                $this->telegram->sendMessage($chatId, "🔍 Комиссар проверил игрока...", $this->getGameMenu());
            } elseif ($role === Game::ROLE_HOMELESS) {
                $this->telegram->sendMessage($chatId, "🏚 Бомж проверил игрока...", $this->getGameMenu());
            }
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'freeze' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            $result = $this->trySetNightAction($game, $userId, 'freeze', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Игрок будет заморожен');
            $this->telegram->editMessageText($userId, $messageId, "✅ Замораживаете: " . $target['name']);
            $this->telegram->sendMessage($chatId, "💋 Любовница сделала свой выбор...", $this->getGameMenu());
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'protect' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            $result = $this->trySetNightAction($game, $userId, 'protect', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Подзащитный выбран');
            $this->telegram->editMessageText($userId, $messageId, "✅ Защищаете: " . $target['name']);
            $this->telegram->sendMessage($chatId, "⚖️ Адвокат выбрал подзащитного...", $this->getGameMenu());
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'detectivekill' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            $result = $this->trySetNightAction($game, $userId, 'detectivekill', $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Цель устранена');
            $this->telegram->editMessageText($userId, $messageId, "✅ Устраняете: " . $target['name']);
            $this->telegram->sendMessage($chatId, "🔫 Комиссар принял решение...", $this->getGameMenu());
            $this->checkNightComplete($chatId, $game);
        } elseif ($action === 'detectivecheck') {
            // Убираем кнопки выбора действия
            $this->telegram->editMessageText($userId, $messageId, 
                "🔍 Вы выбрали: Проверить игрока"
            );
            $keyboard = $this->getPlayerKeyboard($game, 'check', $userId);
            $this->telegram->sendMessage($userId, '🔍 Выберите кого проверить:', $keyboard);
            $this->telegram->answerCallbackQuery($callbackQuery['id']);
        } elseif ($action === 'detectivekillaction') {
            // Убираем кнопки выбора действия
            $this->telegram->editMessageText($userId, $messageId, 
                "🔫 Вы выбрали: Устранить игрока"
            );
            $keyboard = $this->getPlayerKeyboard($game, 'detectivekill', $userId);
            $this->telegram->sendMessage($userId, '🔫 Выберите кого устранить:', $keyboard);
            $this->telegram->answerCallbackQuery($callbackQuery['id']);
        } elseif ($action === 'vote' && count($parts) > 2) {
            $targetId = $parts[1];
            $target = $game->getPlayerById($targetId);
            if (!$target) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Ошибка: игрок не найден');
                return;
            }
            
            $voterName = $game->getPlayerName($userId);
            $result = $this->tryAddVote($game, $userId, $targetId);
            if (!$result['success']) {
                $this->telegram->answerCallbackQuery($callbackQuery['id'], $result['error']);
                return;
            }
            $game = $result['game'];
            
            $this->telegram->answerCallbackQuery($callbackQuery['id'], 'Голос учтен');
            $this->telegram->editMessageText($userId, $messageId, "✅ Вы проголосовали за: " . $target['name']);
            $this->telegram->sendMessage($chatId, "🗳 <b>$voterName</b> проголосовал", $this->getGameMenu());
            $this->checkVoteComplete($chatId, $game);
        }
    }

    private function createNewGame($chatId, $userId, $firstName, $lastName, $username, $chatType) {
        if ($chatType === 'private') {
            $this->telegram->sendMessage($chatId, 
                '⚠️ Игра может быть создана только в групповом чате.\n\n' .
                'Добавьте бота в группу и используйте команду /newgame там.',
                $this->getMainMenu()
            );
            return;
        }

        $existingGame = $this->loadGame($chatId);
        
        if ($existingGame && $existingGame->getPhase() !== Game::PHASE_ENDED) {
            $this->telegram->sendMessage($chatId, 
                '⚠️ Игра уже создана! Используйте /join для присоединения или дождитесь окончания текущей игры.',
                $this->getGameMenu()
            );
            return;
        }

        $game = new Game($chatId);
        $game->addPlayer($userId, $firstName, $lastName, $username);
        $game->beginRegistration();
        $this->saveGame($game);
        
        $keyboard = $this->getRegistrationKeyboard($game);
        $creatorName = Game::formatPlayerName($firstName, $lastName, $username);
        
        $lobbyImage = __DIR__ . '/attached_assets/stock_images/people_playing_board_a10eecf4.jpg';
        $caption = "🎮 <b>Новая игра создана!</b>\n\n" .
            "Создатель: $creatorName\n" .
            "Игроков: 1 / минимум 4\n\n" .
            "⏰ У вас есть 3 минуты для сбора игроков.\n\n" .
            "Используйте кнопку 'Присоединиться' для участия.\n\n" .
            "⚠️ <b>Важно!</b> Всем игрокам нужно написать /start боту в личных сообщениях, " .
            "иначе вы не получите кнопки для действий во время игры!";
        
        $this->telegram->sendPhoto($chatId, $lobbyImage, $caption, $keyboard);
    }

    private function joinGame($chatId, $userId, $firstName, $lastName, $username) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, 'Игра не найдена. Создайте новую игру командой /newgame', $this->getMainMenu());
            return;
        }

        if ($game->getPhase() !== Game::PHASE_WAITING && $game->getPhase() !== Game::PHASE_REGISTRATION) {
            $this->telegram->sendMessage($chatId, 'Игра уже началась. Дождитесь окончания.', $this->getGameMenu());
            return;
        }

        if ($game->addPlayer($userId, $firstName, $lastName, $username)) {
            $this->saveGame($game);
            $playerCount = count($game->getPlayers());
            
            $keyboard = $game->getPhase() === Game::PHASE_REGISTRATION ? 
                $this->getRegistrationKeyboard($game) : 
                $this->getGameMenu();
            
            $playerFullName = Game::formatPlayerName($firstName, $lastName, $username);
            
            $this->telegram->sendMessage($chatId, 
                "✅ $playerFullName присоединился к игре!\n\n" .
                "Игроков в игре: $playerCount",
                $keyboard
            );
        } else {
            $this->telegram->sendMessage($chatId, 'Вы уже в игре!', $this->getGameMenu());
        }
    }

    private function showPlayers($chatId) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, 'Игра не найдена.', $this->getMainMenu());
            return;
        }

        $players = $game->getPlayers();
        $text = "👥 <b>Список игроков:</b>\n\n";
        
        foreach ($players as $player) {
            $status = $player['alive'] ? '✅' : '💀';
            $text .= "$status {$player['name']}\n";
        }

        $keyboard = ($game->getPhase() === Game::PHASE_WAITING || $game->getPhase() === Game::PHASE_ENDED) 
            ? $this->getMainMenu() 
            : $this->getGameMenu();
        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    private function startGame($chatId, $userId) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, 'Игра не найдена.', $this->getMainMenu());
            return;
        }

        if ($game->getPhase() !== Game::PHASE_WAITING) {
            $this->telegram->sendMessage($chatId, 'Игра уже началась!', $this->getGameMenu());
            return;
        }

        if (!$game->startGame()) {
            $this->telegram->sendMessage($chatId, 'Для начала игры нужно минимум 4 игрока!', $this->getGameMenu());
            return;
        }

        $this->saveGame($game);

        $mafiaMembers = [];
        foreach ($game->getPlayers() as $player) {
            if ($player['role'] === Game::ROLE_MAFIA) {
                $mafiaMembers[] = $player;
            }
        }

        foreach ($game->getPlayers() as $player) {
            $role = $this->getRoleText($player['role']);
            $message = "🎭 <b>Ваша роль: $role</b>\n\n" . $this->getRoleDescription($player['role']);
            
            if ($player['role'] === Game::ROLE_MAFIA && count($mafiaMembers) > 1) {
                $message .= "\n\n🤝 <b>Ваши соратники:</b>\n";
                foreach ($mafiaMembers as $mafia) {
                    if ($mafia['user_id'] !== $player['user_id']) {
                        $message .= "{$mafia['name']}\n";
                    }
                }
            }
            
            $this->telegram->sendMessage($player['user_id'], $message);
        }

        $nightImage = __DIR__ . '/attached_assets/stock_images/night_moon_stars_dar_23032965.jpg';
        $this->telegram->sendPhoto($chatId, $nightImage, 
            "🌙 <b>Игра началась!</b>\n\n" .
            "День 1. Наступила ночь...\n" .
            "Роли распределены. Проверьте личные сообщения."
        );

        $this->startNight($chatId, $game);
    }

    private function startNight($chatId, $game) {
        $alivePlayers = array_filter($game->getPlayers(), function($p) {
            return $p['alive'];
        });
        
        $playersList = "👥 <b>Живые игроки (" . count($alivePlayers) . "):</b>\n";
        foreach ($alivePlayers as $player) {
            $playersList .= "• " . $player['name'] . "\n";
        }
        
        $this->telegram->sendMessage($chatId, $playersList, $this->getGameMenu());
        
        $failedPlayers = [];
        
        foreach ($game->getPlayers() as $player) {
            if (!$player['alive']) continue;
            if ($game->isFrozen($player['user_id'])) {
                $result = $this->telegram->sendMessage($player['user_id'], 
                    "❄️ Вы заморожены и пропускаете этот ход"
                );
                if (!$result) {
                    $failedPlayers[] = $player['name'];
                }
                continue;
            }

            $role = $player['role'];
            $userId = $player['user_id'];
            $result = null;

            if ($role === Game::ROLE_MAFIA) {
                $keyboard = $this->getPlayerKeyboard($game, 'kill', $userId);
                $result = $this->telegram->sendMessage($userId, 
                    "🔪 Выберите жертву (3 минуты):", 
                    $keyboard
                );
            } elseif ($role === Game::ROLE_DOCTOR) {
                $keyboard = $this->getPlayerKeyboard($game, 'save', $userId);
                $result = $this->telegram->sendMessage($userId, 
                    "💊 Выберите кого защитить (3 минуты):", 
                    $keyboard
                );
            } elseif ($role === Game::ROLE_DETECTIVE) {
                $keyboard = ['inline_keyboard' => [
                    [['text' => '🔍 Проверить', 'callback_data' => 'detectivecheck_' . $chatId . '_' . $game->getGameId()]],
                    [['text' => '🔫 Убить', 'callback_data' => 'detectivekillaction_' . $chatId . '_' . $game->getGameId()]]
                ]];
                $result = $this->telegram->sendMessage($userId, 
                    "🕵️ Выберите действие (3 минуты):", 
                    $keyboard
                );
            } elseif ($role === Game::ROLE_HOMELESS) {
                $keyboard = $this->getPlayerKeyboard($game, 'check', $userId);
                $result = $this->telegram->sendMessage($userId, 
                    "🔍 Выберите кого проверить (3 минуты):", 
                    $keyboard
                );
            } elseif ($role === Game::ROLE_LOVER) {
                $keyboard = $this->getPlayerKeyboard($game, 'freeze', $userId);
                $result = $this->telegram->sendMessage($userId, 
                    "💋 Выберите кого заморозить на 2 хода (3 минуты):", 
                    $keyboard
                );
            }
            
            if ($result === false && $role !== Game::ROLE_CITIZEN) {
                $failedPlayers[] = $player['name'];
            }
        }
        
        if (!empty($failedPlayers)) {
            $playersList = implode("\n", $failedPlayers);
            $this->telegram->sendMessage($chatId, 
                "⚠️ <b>Внимание!</b>\n\n" .
                "Следующим игрокам не удалось отправить личное сообщение:\n" .
                "$playersList\n\n" .
                "Пожалуйста, напишите боту /start в личных сообщениях, " .
                "чтобы получать кнопки для выбора действий!"
            );
        }
    }

    private function checkNightComplete($chatId, $game) {
        $players = $game->getPlayers();
        $nightActions = $game->getNightActions();

        $allActionsComplete = true;
        foreach ($players as $player) {
            if (!$player['alive']) continue;
            if ($game->isFrozen($player['user_id'])) continue;
            
            $role = $player['role'];
            $needsAction = in_array($role, [
                Game::ROLE_MAFIA, 
                Game::ROLE_DOCTOR, 
                Game::ROLE_DETECTIVE,
                Game::ROLE_HOMELESS,
                Game::ROLE_LOVER
            ]);
            
            if ($needsAction && !isset($nightActions[$player['user_id']])) {
                $allActionsComplete = false;
                break;
            }
        }

        if ($allActionsComplete || $game->isTimeout()) {
            $this->processNight($chatId, $game);
        }
    }

    private function processNight($chatId, $game) {
        $result = $game->processNight();
        $this->saveGame($game);

        $dayImage = __DIR__ . '/attached_assets/stock_images/bright_daylight_sunr_43795e3c.jpg';
        $text = "☀️ <b>Наступил день {$game->getDay()}</b>\n\n";

        if (!empty($result['killed'])) {
            foreach ($result['killed'] as $victimId) {
                $victim = $game->getPlayers()[$victimId];
                $role = $this->getRoleText($victim['role']);
                $text .= "💀 Этой ночью был убит {$victim['name']}\n";
                $text .= "Его роль: {$role}\n";
            }
            $text .= "\n";
        } elseif ($result['saved']) {
            $text .= "🛡 Доктор спас игрока этой ночью!\n\n";
        } else {
            $text .= "✨ Прошлая ночь была спокойной\n\n";
        }

        if ($result['frozen']) {
            $frozenPlayer = $game->getPlayers()[$result['frozen']];
            $text .= "❄️ {$frozenPlayer['name']} заморожен на 2 хода\n\n";
        }

        if (!empty($result['checked'])) {
            $lawyerProtected = $result['lawyer_protected'] ?? null;
            foreach ($result['checked'] as $check) {
                $checkedPlayer = $game->getPlayers()[$check['target']];
                $targetRole = $game->getPlayerRole($check['target']);
                $isMafia = ($targetRole === Game::ROLE_MAFIA || $targetRole === Game::ROLE_DON);
                
                // Адвокат защищает своего клиента от проверок - показывает как мирного
                if ($lawyerProtected === $check['target']) {
                    $isMafia = false;
                }
                
                $this->telegram->sendMessage($check['by'], 
                    $isMafia ? 
                    "🔍 {$checkedPlayer['name']} - МАФИЯ!" : 
                    "🔍 {$checkedPlayer['name']} - НЕ мафия"
                );
            }
        }

        $winner = $game->checkWinCondition();
        if ($winner) {
            $this->endGame($chatId, $game, $winner);
            return;
        }

        $game->beginDiscussion();
        $this->saveGame($game);
        
        $this->telegram->sendPhoto($chatId, $dayImage, $text, $this->getGameMenu());
        
        $this->telegram->sendMessage($chatId, 
            "💬 <b>Время обсудить результаты ночи и проголосовать за вылет!</b>\n\n" .
            "У вас есть 2 минуты на обсуждение, затем начнется голосование.",
            $this->getGameMenu()
        );
    }

    private function checkVoteComplete($chatId, $game) {
        $alivePlayers = $game->getAlivePlayers();
        $votes = $game->getVotes();

        $unfrozenCount = 0;
        foreach ($alivePlayers as $player) {
            if (!$game->isFrozen($player['user_id'])) {
                $unfrozenCount++;
            }
        }
        
        if (count($votes) >= $unfrozenCount || $game->isTimeout()) {
            $this->processVote($chatId, $game);
        }
    }

    private function processVote($chatId, $game) {
        $eliminated = $game->processVote();
        $this->saveGame($game);

        $this->telegram->sendMessage($chatId, "⏳ Подсчитываем голоса...", $this->getGameMenu());
        sleep(3);

        if ($eliminated) {
            $victim = $game->getPlayers()[$eliminated];
            $role = $this->getRoleText($victim['role']);
            
            $this->telegram->sendMessage($chatId, 
                "⚖️ <b>Результаты голосования</b>\n\n" .
                "💀 Исключен: {$victim['name']}\n" .
                "Роль: $role",
                $this->getGameMenu()
            );
        } else {
            $this->telegram->sendMessage($chatId, "Никто не был исключен", $this->getGameMenu());
        }

        $winner = $game->checkWinCondition();
        if ($winner) {
            $this->endGame($chatId, $game, $winner);
            return;
        }

        $game->incrementDay();
        $game->beginNight();
        $this->saveGame($game);

        $nightImage = __DIR__ . '/attached_assets/stock_images/night_moon_stars_dar_23032965.jpg';
        $this->telegram->sendPhoto($chatId, $nightImage, "🌙 Наступила ночь...");
        
        $this->startNight($chatId, $game);
    }

    private function endGame($chatId, $game, $winner) {
        $winnerText = $winner === 'mafia' ? 'Мафия' : 'Мирные жители';
        
        $text = "🎉 <b>Игра окончена!</b>\n\n";
        $text .= "🏆 Победили: <b>$winnerText</b>\n\n";
        $text .= "👥 Роли игроков:\n";
        
        foreach ($game->getPlayers() as $player) {
            $role = $this->getRoleText($player['role']);
            $status = $player['alive'] ? '✅' : '💀';
            $text .= "$status {$player['name']} - $role\n";
        }

        $game->setPhase(Game::PHASE_ENDED);
        $this->saveGame($game);
        
        $this->telegram->sendMessage($chatId, $text, $this->getMainMenu());
        $this->deleteOldGames($chatId);
    }

    private function showStatus($chatId) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, 'Нет активной игры.', $this->getMainMenu());
            return;
        }

        $phase = $this->getPhaseText($game->getPhase());
        $alive = count($game->getAlivePlayers());
        
        $text = "📊 <b>Статус игры</b>\n\n";
        $text .= "Фаза: $phase\n";
        $text .= "День: {$game->getDay()}\n";
        $text .= "Живых игроков: $alive\n";

        $this->telegram->sendMessage($chatId, $text, $this->getGameMenu());
    }

    private function forceEndGame($chatId, $userId) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, 'Нет активной игры.', $this->getMainMenu());
            return;
        }

        if ($game->getPhase() === Game::PHASE_WAITING) {
            $this->deleteGame($game);
            $this->telegram->sendMessage($chatId, '🛑 Игра отменена.', $this->getMainMenu());
            return;
        }

        $text = "🛑 <b>Игра завершена досрочно!</b>\n\n";
        $text .= "👥 Роли игроков:\n";
        
        foreach ($game->getPlayers() as $player) {
            $role = $this->getRoleText($player['role']);
            $status = $player['alive'] ? '✅' : '💀';
            $text .= "$status {$player['name']} - $role\n";
        }

        $game->setPhase(Game::PHASE_ENDED);
        $this->saveGame($game);
        
        $this->telegram->sendMessage($chatId, $text, $this->getMainMenu());
        $this->deleteOldGames($chatId);
    }

    private function showHelp($chatId) {
        $this->telegram->sendMessage($chatId, 
            "📖 <b>Правила игры в Мафию</b>\n\n" .
            "<b>Основные роли:</b>\n" .
            "🎩 Дон - главарь мафии, решающий голос\n" .
            "🔪 Мафия - убивает игроков ночью\n" .
            "👤 Мирный житель - голосует днём\n" .
            "🔍 Комиссар - проверяет или убивает ночью\n" .
            "💊 Доктор - защищает игроков ночью\n\n" .
            "<b>Специальные роли:</b>\n" .
            "🏚 Бомж - проверяет на мафию (7+ игроков)\n" .
            "💣 Камикадзе - забирает жертву при линчевании (7+)\n" .
            "💀 Самоубийца - победа при линчевании (8+)\n" .
            "🔪 Маньяк - убивает всех, играет сам за себя (9+)\n" .
            "⚖️ Адвокат - защищает от проверок (10+)\n" .
            "🍀 Счастливчик - 50% шанс выжить (6-7, 11-12)\n\n" .
            "<b>Особенности:</b>\n" .
            "⏰ Таймеры: 3 мин на ночь, 4 мин на голосование\n" .
            "🎯 Победа мафии: если их ≥ мирных\n" .
            "🎯 Победа мирных: устранить всю мафию"
        );
    }

    private function getPlayerKeyboard($game, $action, $excludeUserId = null) {
        $buttons = [];
        $groupChatId = $game->getChatId();
        $gameId = $game->getGameId();
        
        foreach ($game->getAlivePlayers() as $player) {
            if ($player['user_id'] == $excludeUserId) continue;
            
            $buttons[] = [
                [
                    'text' => '@' . $player['name'],
                    'callback_data' => $action . '_' . $player['user_id'] . '_' . $groupChatId . '_' . $gameId
                ]
            ];
        }

        return ['inline_keyboard' => $buttons];
    }

    private function getRoleText($role) {
        $roles = [
            Game::ROLE_MAFIA => '🔪 Мафия',
            Game::ROLE_DON => '🎩 Дон',
            Game::ROLE_CITIZEN => '👤 Мирный житель',
            Game::ROLE_DETECTIVE => '🔍 Комиссар',
            Game::ROLE_DOCTOR => '💊 Доктор',
            Game::ROLE_HOMELESS => '🏚 Бомж',
            Game::ROLE_LOVER => '💋 Любовница',
            Game::ROLE_MANIAC => '🔪 Маньяк',
            Game::ROLE_LAWYER => '⚖️ Адвокат',
            Game::ROLE_SUICIDE => '💀 Самоубийца',
            Game::ROLE_LUCKY => '🍀 Счастливчик',
            Game::ROLE_KAMIKAZE => '💣 Камикадзе'
        ];
        return $roles[$role] ?? 'Неизвестно';
    }

    private function getRoleDescription($role) {
        $descriptions = [
            Game::ROLE_MAFIA => 'Вы мафия! Ваша цель - убить всех мирных жителей. Каждую ночь выбирайте жертву.',
            Game::ROLE_DON => 'Вы - Дон мафии. Ваш голос решающий при выборе жертвы. Если вас убьют, один из мафиози станет новым Доном.',
            Game::ROLE_CITIZEN => 'Вы мирный житель. Ваша цель - найти и устранить всю мафию через голосование.',
            Game::ROLE_DETECTIVE => 'Вы комиссар! Каждую ночь вы можете ПРОВЕРИТЬ игрока или УБИТЬ его. Используйте с умом!',
            Game::ROLE_DOCTOR => 'Вы доктор! Каждую ночь вы можете защитить одного игрока от убийства.',
            Game::ROLE_HOMELESS => 'Вы бомж! Каждую ночь вы можете проверить одного игрока на принадлежность к мафии.',
            Game::ROLE_LOVER => 'Вы любовница! Каждую ночь вы можете заморозить игрока на 2 хода. Замороженный игрок пропускает все действия.',
            Game::ROLE_MANIAC => 'Вы - маньяк. Убиваете каждую ночь одного игрока. Играете сам за себя - ваша цель убить всех!',
            Game::ROLE_LAWYER => 'Вы - адвокат. Ночью выбираете подзащитного. Комиссар и бомж увидят его как мирного жителя.',
            Game::ROLE_SUICIDE => 'Вы - самоубийца. Ваша цель - погибнуть при дневном голосовании. Только тогда вы победите!',
            Game::ROLE_LUCKY => 'Вы - счастливчик. При покушении у вас 50% шанс выжить.',
            Game::ROLE_KAMIKAZE => 'Вы - камикадзе. Если вас линчуют днём, вы можете забрать с собой одного игрока.'
        ];
        return $descriptions[$role] ?? '';
    }

    private function getPhaseText($phase) {
        $phases = [
            Game::PHASE_WAITING => 'Ожидание игроков',
            Game::PHASE_REGISTRATION => 'Регистрация',
            Game::PHASE_NIGHT => 'Ночь',
            Game::PHASE_DISCUSSION => 'Обсуждение',
            Game::PHASE_VOTE => 'Голосование',
            Game::PHASE_ENDED => 'Игра окончена'
        ];
        return $phases[$phase] ?? 'Неизвестно';
    }

    private function getRegistrationKeyboard($game) {
        $buttons = [];
        $gameId = $game->getGameId();
        $chatId = $game->getChatId();
        
        if (count($game->getPlayers()) >= 4) {
            $buttons[] = [['text' => '🎮 Старт игры', 'callback_data' => "start_game_{$chatId}_{$gameId}"]];
        }
        
        $buttons[] = [
            ['text' => '👥 Присоединиться', 'callback_data' => "join_game_{$chatId}_{$gameId}"],
            ['text' => '⏰ Добавить 30 секунд', 'callback_data' => "add_time_{$chatId}_{$gameId}"]
        ];
        
        return ['inline_keyboard' => $buttons];
    }

    private function processRegistrationTimeout($chatId, $game) {
        
        if (count($game->getPlayers()) < 4) {
            $this->deleteGame($game);
            
            $this->telegram->sendMessage($chatId, 
                "⏱ <b>Время регистрации истекло!</b>\n\n" .
                "За отведенное время не удалось собрать четырёх игроков, лобби удалено.",
                $this->getMainMenu()
            );
        } else {
            $this->startGameFromRegistration($chatId, $game);
        }
    }

    private function processDiscussionTimeout($chatId, $game) {
        $game->beginVote();
        $this->saveGame($game);
        
        $this->telegram->sendMessage($chatId, 
            "⏱ <b>Время обсуждения закончилось!</b>\n\n" .
            "Начинается голосование (4 минуты).",
            $this->getGameMenu()
        );

        foreach ($game->getAlivePlayers() as $player) {
            if ($game->isFrozen($player['user_id'])) continue;
            
            $keyboard = $this->getPlayerKeyboard($game, 'vote', $player['user_id']);
            $this->telegram->sendMessage($player['user_id'], 
                "🗳 Голосуйте за исключение (4 минуты):", 
                $keyboard
            );
        }
    }

    private function startGameFromRegistration($chatId, $game) {
        if (count($game->getPlayers()) < 4) {
            $this->telegram->sendMessage($chatId, 
                '⚠️ Недостаточно игроков для начала игры. Минимум 4 игрока.\n\n' .
                "Игроков сейчас: " . count($game->getPlayers()),
                $this->getRegistrationKeyboard($game)
            );
            return;
        }
        
        $game->startGame();
        $this->saveGame($game);
        
        $this->telegram->sendMessage($chatId, 
            "⏱ Игра началась! Роли распределены.",
            $this->getGameMenu()
        );

        foreach ($game->getPlayers() as $player) {
            $role = $player['role'];
            $roleText = $this->getRoleText($role);
            $roleDescription = $this->getRoleDescription($role);
            
            $this->telegram->sendMessage($player['user_id'], 
                "🎭 <b>Ваша роль: $roleText</b>\n\n$roleDescription"
            );
        }

        $nightImage = __DIR__ . '/attached_assets/stock_images/night_moon_stars_dar_23032965.jpg';
        $this->telegram->sendPhoto($chatId, $nightImage, "🌙 Наступила ночь...");
        $this->startNight($chatId, $game);
    }

    /**
     * Атомарная установка ночного действия с защитой от race condition
     * @return array ['success' => bool, 'error' => string|null, 'game' => Game|null]
     */
    private function trySetNightAction($game, $userId, $action, $targetId) {
        $filename = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        $fp = fopen($filename, 'c+');
        if (!$fp) {
            return ['success' => false, 'error' => 'Не удалось открыть файл игры'];
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return ['success' => false, 'error' => 'Не удалось заблокировать файл игры'];
        }
        
        // Перечитываем СВЕЖИЕ данные из файла под блокировкой
        rewind($fp);
        $content = stream_get_contents($fp);
        $data = json_decode($content, true);
        
        // Защита от пустого/поврежденного файла
        if (!is_array($data)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['success' => false, 'error' => 'Файл игры поврежден'];
        }
        
        // Сохраняем timer flags чтобы не потерять их
        $preservedTimerFlags = [
            'notified_60' => $data['notified_60'] ?? false,
            'notified_30' => $data['notified_30'] ?? false,
            'timeout_sent' => $data['timeout_sent'] ?? false,
            'timer_message_id' => $data['timer_message_id'] ?? null
        ];
        
        // Создаем СВЕЖИЙ объект Game из файловых данных
        $freshGame = Game::fromArray($data);
        
        // Проверяем есть ли уже действие у этого игрока на СВЕЖИХ данных (защита от double-submit)
        if ($freshGame->hasNightAction($userId)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['success' => false, 'error' => 'Вы уже сделали свой выбор'];
        }
        
        // Добавляем действие в СВЕЖИЙ объект
        $freshGame->setNightAction($userId, $action, $targetId);
        
        // Сохраняем обновленные данные с сохранением timer flags
        $gameData = $freshGame->toArray();
        $gameData = array_merge($gameData, $preservedTimerFlags);
        
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($gameData));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        // Возвращаем обновленный объект Game
        return ['success' => true, 'error' => null, 'game' => $freshGame];
    }

    /**
     * Атомарное добавление голоса с защитой от race condition
     * @return array ['success' => bool, 'error' => string|null, 'game' => Game|null]
     */
    private function tryAddVote($game, $voterId, $targetId) {
        $filename = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        $fp = fopen($filename, 'c+');
        if (!$fp) {
            return ['success' => false, 'error' => 'Не удалось открыть файл игры'];
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return ['success' => false, 'error' => 'Не удалось заблокировать файл игры'];
        }
        
        // Перечитываем СВЕЖИЕ данные из файла под блокировкой
        rewind($fp);
        $content = stream_get_contents($fp);
        $data = json_decode($content, true);
        
        // Защита от пустого/поврежденного файла
        if (!is_array($data)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['success' => false, 'error' => 'Файл игры поврежден'];
        }
        
        // Сохраняем timer flags чтобы не потерять их
        $preservedTimerFlags = [
            'notified_60' => $data['notified_60'] ?? false,
            'notified_30' => $data['notified_30'] ?? false,
            'timeout_sent' => $data['timeout_sent'] ?? false,
            'timer_message_id' => $data['timer_message_id'] ?? null
        ];
        
        // Создаем СВЕЖИЙ объект Game из файловых данных
        $freshGame = Game::fromArray($data);
        
        // Проверяем проголосовал ли уже игрок на СВЕЖИХ данных (защита от double-submit)
        if ($freshGame->hasVoted($voterId)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['success' => false, 'error' => 'Вы уже проголосовали'];
        }
        
        // Добавляем голос в СВЕЖИЙ объект
        $freshGame->addVote($voterId, $targetId);
        
        // Сохраняем обновленные данные с сохранением timer flags
        $gameData = $freshGame->toArray();
        $gameData = array_merge($gameData, $preservedTimerFlags);
        
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($gameData));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        // Возвращаем обновленный объект Game
        return ['success' => true, 'error' => null, 'game' => $freshGame];
    }

    private function saveGame($game, $expectedPhase = null) {
        $filename = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        $fp = fopen($filename, 'c+');
        if (!$fp) {
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        
        $preservedTimerFlags = [];
        if (file_exists($filename) && filesize($filename) > 0) {
            rewind($fp);
            $currentContent = stream_get_contents($fp);
            $currentData = json_decode($currentContent, true);
            
            if ($expectedPhase !== null && $currentData && isset($currentData['phase']) && $currentData['phase'] !== $expectedPhase) {
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
        
        $gameData = $game->toArray();
        if (!empty($preservedTimerFlags)) {
            $gameData = array_merge($gameData, $preservedTimerFlags);
        }
        
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($gameData));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    private function loadGame($chatId, $gameId = null) {
        if ($gameId) {
            $filename = $this->sessionsDir . '/game_' . $chatId . '_' . $gameId . '.json';
            if (!file_exists($filename)) {
                return null;
            }
            $fp = fopen($filename, 'r');
            if (!$fp) {
                return null;
            }
            if (!flock($fp, LOCK_SH)) {
                fclose($fp);
                return null;
            }
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            
            $data = json_decode($content, true);
            if (!$data || $data['game_id'] !== $gameId) {
                return null;
            }
            return Game::fromArray($data);
        }
        
        $files = glob($this->sessionsDir . '/game_' . $chatId . '_*.json');
        if (empty($files)) {
            return null;
        }
        
        $activeFiles = [];
        foreach ($files as $file) {
            $fp = fopen($file, 'r');
            if (!$fp) continue;
            if (!flock($fp, LOCK_SH)) {
                fclose($fp);
                continue;
            }
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            
            $data = json_decode($content, true);
            if ($data && $data['phase'] !== Game::PHASE_ENDED) {
                $activeFiles[$file] = filemtime($file);
            }
        }
        
        if (empty($activeFiles)) {
            return null;
        }
        
        arsort($activeFiles);
        $latestFile = array_key_first($activeFiles);
        $fp = fopen($latestFile, 'r');
        if (!$fp) {
            return null;
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $data = json_decode($content, true);
        return Game::fromArray($data);
    }

    private function deleteGame($game) {
        $filename = $this->sessionsDir . '/game_' . $game->getChatId() . '_' . $game->getGameId() . '.json';
        
        if (file_exists($filename)) {
            unlink($filename);
        }
    }
    
    private function handleSuicide($chatId, $userId, $firstName, $lastName, $username) {
        $game = $this->loadGame($chatId);
        
        if (!$game) {
            $this->telegram->sendMessage($chatId, "❌ Активная игра не найдена.");
            return;
        }
        
        if ($game->getPhase() === Game::PHASE_REGISTRATION) {
            $this->telegram->sendMessage($chatId, "❌ Нельзя покинуть игру во время регистрации. Просто не нажимайте кнопку старта.");
            return;
        }
        
        if ($game->getPhase() === Game::PHASE_ENDED) {
            $this->telegram->sendMessage($chatId, "❌ Игра уже завершена.");
            return;
        }
        
        $playerName = "$firstName $lastName" . ($username ? " (@$username)" : "");
        
        $alivePlayers = $game->getAlivePlayers();
        $playerFound = false;
        $playerRole = null;
        
        foreach ($alivePlayers as $player) {
            if ($player['user_id'] == $userId) {
                $playerFound = true;
                $playerRole = $player['role'];
                break;
            }
        }
        
        if (!$playerFound) {
            $this->telegram->sendMessage($chatId, "❌ Вы не участвуете в игре или уже погибли.");
            return;
        }
        
        $game->killPlayer($userId);
        
        $roleText = $this->getRoleText($playerRole);
        $this->telegram->sendMessage($chatId, 
            "💀 <b>$playerName</b> покинул игру.\n" .
            "Роль: $roleText"
        );
        
        $winner = $game->checkWinner();
        if ($winner) {
            $this->saveGame($game);
            $this->lifecycleService->announceWinner($chatId, $game, $winner);
            $game->setPhase(Game::PHASE_ENDED);
            $this->saveGame($game);
            return;
        }
        
        $this->saveGame($game);
        
        $this->telegram->sendMessage($chatId, "Игра продолжается...");
    }
    
    private function deleteOldGames($chatId) {
        $files = glob($this->sessionsDir . '/game_' . $chatId . '_*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['phase'] === Game::PHASE_ENDED) {
                unlink($file);
            }
        }
    }
}
