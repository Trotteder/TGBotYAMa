<?php

class Game {
    const ROLE_MAFIA = 'mafia';
    const ROLE_CITIZEN = 'citizen';
    const ROLE_DETECTIVE = 'detective';
    const ROLE_DOCTOR = 'doctor';
    const ROLE_HOMELESS = 'homeless';
    const ROLE_LOVER = 'lover';
    const ROLE_DON = 'don';
    const ROLE_MANIAC = 'maniac';
    const ROLE_LAWYER = 'lawyer';
    const ROLE_SUICIDE = 'suicide';
    const ROLE_LUCKY = 'lucky';
    const ROLE_KAMIKAZE = 'kamikaze';

    const PHASE_WAITING = 'waiting';
    const PHASE_REGISTRATION = 'registration';
    const PHASE_NIGHT = 'night';
    const PHASE_DISCUSSION = 'discussion';
    const PHASE_VOTE = 'vote';
    const PHASE_ENDED = 'ended';

    const REGISTRATION_TIMEOUT = 180;
    const ACTION_TIMEOUT = 180;
    const DISCUSSION_TIMEOUT = 5;
    const VOTE_TIMEOUT = 240;

    private $chatId;
    private $gameId;
    private $players = [];
    private $phase = self::PHASE_WAITING;
    private $day = 0;
    private $votes = [];
    private $nightActions = [];
    private $alive = [];
    private $dead = [];
    private $frozen = [];
    private $phaseStartTime = 0;
    private $actionDeadline = 0;
    private $notified60 = false;
    private $notified30 = false;
    private $timeoutSent = false;
    private $timerMessageId = null;
    private $messagesForDeletion = [];

    public function __construct($chatId, $gameId = null) {
        $this->chatId = $chatId;
        $this->gameId = $gameId ?? bin2hex(random_bytes(8));
    }

    public function addPlayer($userId, $firstName, $lastName = '', $username = '') {
        if (isset($this->players[$userId])) {
            return false;
        }

        $this->players[$userId] = [
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'name' => self::formatPlayerName($firstName, $lastName, $username),
            'role' => null,
            'alive' => true,
            'frozen_until' => 0
        ];

        $this->alive[$userId] = true;
        return true;
    }
    
    public static function formatPlayerName($firstName, $lastName = '', $username = '') {
        $fullName = trim($firstName . ' ' . $lastName);
        if (!empty($username)) {
            $fullName .= " (@{$username})";
        }
        return $fullName;
    }

    public function removePlayer($userId) {
        if (!isset($this->players[$userId])) {
            return false;
        }

        unset($this->players[$userId]);
        unset($this->alive[$userId]);
        return true;
    }

    public function beginRegistration() {
        $this->phase = self::PHASE_REGISTRATION;
        $this->phaseStartTime = time();
        $this->actionDeadline = time() + self::REGISTRATION_TIMEOUT;
        $this->notified60 = false;
        $this->notified30 = false;
        $this->timeoutSent = false;
    }

    public function extendRegistration($seconds = 30) {
        if ($this->phase === self::PHASE_REGISTRATION) {
            $this->actionDeadline += $seconds;
            return true;
        }
        return false;
    }

    public function startGame() {
        if (count($this->players) < 4) {
            return false;
        }

        $this->assignRoles();
        $this->day = 1;
        $this->beginNight();
        return true;
    }

    public function beginNight() {
        $this->phase = self::PHASE_NIGHT;
        $this->phaseStartTime = time();
        $this->actionDeadline = time() + self::ACTION_TIMEOUT;
        $this->notified60 = false;
        $this->notified30 = false;
        $this->timeoutSent = false;
        $this->timerMessageId = null;
        $this->nightActions = [];
    }

    public function beginDiscussion() {
        $this->phase = self::PHASE_DISCUSSION;
        $this->phaseStartTime = time();
        $this->actionDeadline = time() + self::DISCUSSION_TIMEOUT;
        $this->notified60 = false;
        $this->notified30 = false;
        $this->timeoutSent = false;
        $this->timerMessageId = null;
    }

    public function beginVote() {
        $this->phase = self::PHASE_VOTE;
        $this->phaseStartTime = time();
        $this->actionDeadline = time() + self::VOTE_TIMEOUT;
        $this->notified60 = false;
        $this->notified30 = false;
        $this->timeoutSent = false;
        $this->timerMessageId = null;
        $this->votes = [];
    }

    /**
     * Распределяет роли между игроками согласно количеству участников
     * 
     * Логика распределения:
     * - Квота мафии: floor(playerCount/3), включая Дона
     * - Обязательные роли: Дон (всегда), Детектив (всегда), Доктор (всегда)
     * - Специальные роли добавляются при достижении порогов по игрокам
     * - Счастливчик добавляется при 6-7 и 11-12 игроках (пропускается при 8-10 для баланса)
     * - Оставшиеся слоты заполняются Мирными жителями
     */
    public function assignRoles() {
        $playerCount = count($this->players);
        // Квота мафии = max(2, floor(playerCount/3)) - минимум 2 мафиози (Дон + 1 обычный)
        // Это обеспечивает наличие хотя бы 2 мафиози даже для 4-5 игроков
        $mafiaCount = max(2, floor($playerCount / 3));
        
        $roles = [];
        
        // ШАГ 1: Добавляем Дона (обязательная роль, всегда присутствует)
        $roles[] = self::ROLE_DON;
        
        // ШАГ 2: Добавляем обычных мафиози для заполнения квоты
        // Для 4-5 игроков: mafiaCount=2, добавляем 1 обычного мафиози (Дон + 1 Мафия)
        // Для 6-8 игроков: mafiaCount=2, добавляем 1 обычного мафиози (Дон + 1 Мафия)
        // Для 9-11 игроков: mafiaCount=3, добавляем 2 обычных мафиози (Дон + 2 Мафии)
        // Для 12+ игроков: mafiaCount=4, добавляем 3 обычных мафиози (Дон + 3 Мафии)
        for ($i = 1; $i < $mafiaCount; $i++) {
            $roles[] = self::ROLE_MAFIA;
        }
        
        // ШАГ 3: Добавляем обязательные мирные роли
        $roles[] = self::ROLE_DETECTIVE;  // Комиссар (всегда)
        $roles[] = self::ROLE_DOCTOR;     // Доктор (всегда)
        
        // ШАГ 4: Добавляем специальные роли в порядке приоритета (от высшего к низшему)
        // Приоритет: Lawyer(10+) > Maniac(9+) > Suicide(8+) > Kamikaze(7+) > Homeless(7+)
        $mandatorySpecials = [
            ['role' => self::ROLE_LAWYER, 'threshold' => 10],    // Адвокат (10+ игроков)
            ['role' => self::ROLE_MANIAC, 'threshold' => 9],     // Маньяк (9+ игроков)
            ['role' => self::ROLE_SUICIDE, 'threshold' => 8],    // Самоубийца (8+ игроков)
            ['role' => self::ROLE_KAMIKAZE, 'threshold' => 7],   // Камикадзе (7+ игроков)
            ['role' => self::ROLE_HOMELESS, 'threshold' => 7]    // Бомж (7+ игроков)
        ];
        
        // Вычисляем оставшиеся слоты после обязательных ролей
        $availableSlots = $playerCount - count($roles);
        
        // Добавляем специальные роли, если достигнут порог по количеству игроков
        foreach ($mandatorySpecials as $special) {
            if ($playerCount >= $special['threshold'] && $availableSlots > 0) {
                $roles[] = $special['role'];
                $availableSlots--;
            }
        }
        
        // ШАГ 5: Добавляем Счастливчика (только при 6-7 и 11-12 игроках)
        // При 8-10 игроках пропускаем для сохранения игрового баланса
        if ($playerCount >= 6 && $availableSlots > 0 && $playerCount != 8 && $playerCount != 9 && $playerCount != 10) {
            $roles[] = self::ROLE_LUCKY;
            $availableSlots--;
        }
        
        // ШАГ 6: Заполняем оставшиеся слоты Мирными жителями
        while (count($roles) < $playerCount) {
            $roles[] = self::ROLE_CITIZEN;
        }

        // ШАГ 7: Перемешиваем роли для случайного распределения
        shuffle($roles);

        // ШАГ 8: Назначаем роли игрокам
        $i = 0;
        foreach ($this->players as $userId => &$player) {
            $player['role'] = $roles[$i];
            $i++;
        }
    }

    public function getPlayers() {
        return $this->players;
    }

    public function getPlayerById($userId) {
        return $this->players[$userId] ?? null;
    }

    public function getPlayerName($userId) {
        if (!isset($this->players[$userId])) {
            return 'Неизвестный игрок';
        }
        return $this->players[$userId]['name'];
    }

    public function killPlayer($userId) {
        if (isset($this->players[$userId])) {
            $this->players[$userId]['alive'] = false;
            unset($this->alive[$userId]);
            $this->dead[] = $userId;
        }
    }

    public function getAlivePlayers() {
        $alive = [];
        foreach ($this->players as $player) {
            if ($player['alive']) {
                $alive[] = $player;
            }
        }
        return $alive;
    }

    public function getPlayerRole($userId) {
        return $this->players[$userId]['role'] ?? null;
    }

    public function isAlive($userId) {
        return $this->players[$userId]['alive'] ?? false;
    }

    public function isFrozen($userId) {
        if (!isset($this->players[$userId])) {
            return false;
        }
        return $this->players[$userId]['frozen_until'] > $this->day;
    }

    public function freezePlayer($userId, $turns) {
        if (isset($this->players[$userId])) {
            $this->players[$userId]['frozen_until'] = $this->day + $turns;
        }
    }

    public function startPhaseTimer($timeout) {
        $this->phaseStartTime = time();
        $this->actionDeadline = $this->phaseStartTime + $timeout;
        $this->notified60 = false;
        $this->notified30 = false;
    }

    public function isTimeout() {
        return time() >= $this->actionDeadline;
    }

    public function getTimeRemaining() {
        $remaining = $this->actionDeadline - time();
        return max(0, $remaining);
    }

    public function getActionDeadline() {
        return $this->actionDeadline;
    }

    public function getPhaseStartTime() {
        return $this->phaseStartTime;
    }

    public function hasNightAction($userId) {
        return isset($this->nightActions[$userId]);
    }

    public function setNightAction($userId, $action, $targetId) {
        $this->nightActions[$userId] = [
            'action' => $action,
            'target' => $targetId
        ];
    }

    public function getNightActions() {
        return $this->nightActions;
    }

    public function clearNightActions() {
        $this->nightActions = [];
    }

    public function checkWinner() {
        $alivePlayers = array_filter($this->players, function($p) {
            return $p['alive'];
        });
        
        if (empty($alivePlayers)) {
            return 'draw';
        }
        
        $mafiaCount = 0;
        $townCount = 0;
        
        foreach ($alivePlayers as $player) {
            if ($player['role'] === 'mafia') {
                $mafiaCount++;
            } else {
                $townCount++;
            }
        }
        
        if ($mafiaCount === 0) {
            return 'town';
        }
        
        if ($mafiaCount >= $townCount) {
            return 'mafia';
        }
        
        return null;
    }

    public function processNight() {
        $mafiaTargets = [];
        $donVote = null;
        $detectiveKills = [];
        $maniacKill = null;
        $saved = null;
        $lawyerProtected = null;
        $checked = [];
        $frozen = null;

        foreach ($this->nightActions as $userId => $action) {
            $role = $this->getPlayerRole($userId);

            if (($role === self::ROLE_MAFIA || $role === self::ROLE_DON) && $action['action'] === 'kill') {
                if ($role === self::ROLE_DON) {
                    $donVote = $action['target'];
                }
                $mafiaTargets[] = $action['target'];
            } elseif ($role === self::ROLE_MANIAC && $action['action'] === 'kill') {
                $maniacKill = $action['target'];
            } elseif ($role === self::ROLE_DOCTOR && $action['action'] === 'save') {
                $saved = $action['target'];
            } elseif ($role === self::ROLE_LAWYER && $action['action'] === 'protect') {
                $lawyerProtected = $action['target'];
            } elseif ($role === self::ROLE_DETECTIVE && $action['action'] === 'check') {
                $checked[] = ['by' => $userId, 'target' => $action['target']];
            } elseif ($role === self::ROLE_DETECTIVE && $action['action'] === 'detectivekill') {
                $detectiveKills[] = $action['target'];
            } elseif ($role === self::ROLE_HOMELESS && $action['action'] === 'check') {
                $checked[] = ['by' => $userId, 'target' => $action['target']];
            } elseif ($role === self::ROLE_LOVER && $action['action'] === 'freeze') {
                $frozen = $action['target'];
            }
        }

        $killed = [];
        
        if (!empty($mafiaTargets)) {
            $targetCounts = array_count_values($mafiaTargets);
            arsort($targetCounts);
            $topTargets = array_keys($targetCounts, max($targetCounts));
            
            if (count($topTargets) > 1 && $donVote && in_array($donVote, $topTargets)) {
                $mafiaVictim = $donVote;
            } else {
                $mafiaVictim = $topTargets[0];
            }
            $killed[] = $mafiaVictim;
        }
        
        if ($maniacKill) {
            $killed[] = $maniacKill;
        }
        
        $killed = array_merge($killed, $detectiveKills);

        $result = [
            'killed' => [],
            'saved' => false,
            'checked' => [],
            'frozen' => null,
            'lawyer_protected' => $lawyerProtected
        ];

        foreach ($killed as $victimId) {
            $victimRole = $this->getPlayerRole($victimId);
            $canSurvive = ($victimRole === self::ROLE_LUCKY && rand(0, 1) === 1);
            
            if ($victimId !== $saved && !$canSurvive) {
                $wasDon = ($victimRole === self::ROLE_DON);
                
                $this->players[$victimId]['alive'] = false;
                unset($this->alive[$victimId]);
                $this->dead[] = $victimId;
                $result['killed'][] = $victimId;
                
                if ($wasDon) {
                    $newDonId = $this->promoteToDon($victimId);
                    if ($newDonId) {
                        $result['new_don'] = $newDonId;
                    }
                }
            } elseif ($victimId === $saved) {
                $result['saved'] = true;
            } elseif ($canSurvive) {
                $result['lucky_survived'] = $victimId;
            }
        }

        if ($frozen && !in_array($frozen, $result['killed'])) {
            $this->freezePlayer($frozen, 2);
            $result['frozen'] = $frozen;
        }

        $result['checked'] = $checked;

        $this->nightActions = [];

        return $result;
    }

    public function hasVoted($voterId) {
        return isset($this->votes[$voterId]);
    }

    public function addVote($voterId, $targetId) {
        $this->votes[$voterId] = $targetId;
    }

    public function getVotes() {
        return $this->votes;
    }

    public function clearVotes() {
        $this->votes = [];
    }

    public function processVote() {
        $voteCount = [];
        
        foreach ($this->votes as $targetId) {
            if (!isset($voteCount[$targetId])) {
                $voteCount[$targetId] = 0;
            }
            $voteCount[$targetId]++;
        }

        if (empty($voteCount)) {
            $this->votes = [];
            $this->phase = self::PHASE_NIGHT;
            $this->day++;
            return ['eliminated' => null];
        }

        arsort($voteCount);
        $maxVotes = reset($voteCount);
        $eliminated = key($voteCount);

        $unfrozenCount = 0;
        foreach ($this->alive as $playerId => $alive) {
            if (!$this->isFrozen($playerId)) {
                $unfrozenCount++;
            }
        }

        $requiredMajority = floor($unfrozenCount / 2) + 1;
        
        $result = [
            'eliminated' => null,
            'kamikaze_victim' => null,
            'suicide_win' => false
        ];

        if ($maxVotes >= $requiredMajority) {
            $eliminatedRole = $this->getPlayerRole($eliminated);
            
            $wasDon = ($eliminatedRole === self::ROLE_DON);
            
            $this->players[$eliminated]['alive'] = false;
            unset($this->alive[$eliminated]);
            $this->dead[] = $eliminated;
            $result['eliminated'] = $eliminated;
            
            if ($wasDon) {
                $newDonId = $this->promoteToDon($eliminated);
                if ($newDonId) {
                    $result['new_don'] = $newDonId;
                }
            }
            
            if ($eliminatedRole === self::ROLE_SUICIDE) {
                $result['suicide_win'] = true;
            }
            
            // Камикадзе забирает с собой случайного живого игрока
            if ($eliminatedRole === self::ROLE_KAMIKAZE) {
                $alivePlayers = [];
                foreach ($this->players as $userId => $player) {
                    if ($player['alive'] && $userId != $eliminated) {
                        $alivePlayers[] = $userId;
                    }
                }
                
                if (!empty($alivePlayers)) {
                    $victimId = $alivePlayers[array_rand($alivePlayers)];
                    $this->players[$victimId]['alive'] = false;
                    unset($this->alive[$victimId]);
                    $this->dead[] = $victimId;
                    $result['kamikaze_victim'] = $victimId;
                }
            }
        }

        $this->votes = [];
        $this->phase = self::PHASE_NIGHT;
        $this->day++;

        return $result;
    }

    public function isMafia($userId) {
        $role = $this->getPlayerRole($userId);
        return $role === self::ROLE_MAFIA || $role === self::ROLE_DON;
    }
    
    public function getMafiaMembers() {
        $mafia = [];
        foreach ($this->players as $player) {
            if ($player['alive'] && ($player['role'] === self::ROLE_MAFIA || $player['role'] === self::ROLE_DON)) {
                $mafia[] = $player;
            }
        }
        return $mafia;
    }
    
    public function promoteToDon($excludeUserId = null) {
        $mafiaMembers = [];
        foreach ($this->players as $userId => $player) {
            if ($player['alive'] && $player['role'] === self::ROLE_MAFIA && $userId != $excludeUserId) {
                $mafiaMembers[] = $userId;
            }
        }
        
        if (!empty($mafiaMembers)) {
            $newDonId = $mafiaMembers[array_rand($mafiaMembers)];
            $this->players[$newDonId]['role'] = self::ROLE_DON;
            return $newDonId;
        }
        
        return null;
    }

    public function checkWinCondition() {
        $mafiaCount = 0;
        $citizenCount = 0;
        $maniacAlive = false;

        foreach ($this->players as $player) {
            if ($player['alive']) {
                if ($player['role'] === self::ROLE_MAFIA || $player['role'] === self::ROLE_DON) {
                    $mafiaCount++;
                } elseif ($player['role'] === self::ROLE_MANIAC) {
                    $maniacAlive = true;
                } elseif ($player['role'] !== self::ROLE_SUICIDE) {
                    $citizenCount++;
                }
            }
        }

        if ($maniacAlive && $mafiaCount === 0 && $citizenCount === 0) {
            $this->phase = self::PHASE_ENDED;
            return 'maniac';
        }

        if ($mafiaCount === 0 && !$maniacAlive) {
            $this->phase = self::PHASE_ENDED;
            return 'citizens';
        }

        if ($citizenCount === 0 && $mafiaCount > 0 && !$maniacAlive) {
            $this->phase = self::PHASE_ENDED;
            return 'mafia';
        }

        return null;
    }

    public function getPhase() {
        return $this->phase;
    }

    public function setPhase($phase) {
        $this->phase = $phase;
    }

    public function getDay() {
        return $this->day;
    }

    public function setDay($day) {
        $this->day = $day;
    }

    public function incrementDay() {
        $this->day++;
    }

    public function getChatId() {
        return $this->chatId;
    }

    public function getGameId() {
        return $this->gameId;
    }

    public function setNotified60() {
        $this->notified60 = true;
    }
    
    public function clearNotified60() {
        $this->notified60 = false;
    }

    public function setNotified30() {
        $this->notified30 = true;
    }
    
    public function clearNotified30() {
        $this->notified30 = false;
    }

    public function isNotified60() {
        return $this->notified60;
    }

    public function isNotified30() {
        return $this->notified30;
    }

    public function setTimerMessageId($messageId) {
        $this->timerMessageId = $messageId;
    }
    
    public function clearTimerMessageId() {
        $this->timerMessageId = null;
    }

    public function getTimerMessageId() {
        return $this->timerMessageId;
    }

    public function setTimeoutSent() {
        $this->timeoutSent = true;
    }

    public function isTimeoutSent() {
        return $this->timeoutSent;
    }

    public function addMessageForDeletion($messageId, $deleteAfterSeconds = 5) {
        $this->messagesForDeletion[$messageId] = time() + $deleteAfterSeconds;
    }

    public function getMessagesForDeletion() {
        return $this->messagesForDeletion;
    }

    public function removeMessageFromDeletion($messageId) {
        unset($this->messagesForDeletion[$messageId]);
    }

    public function toArray() {
        return [
            'chat_id' => $this->chatId,
            'game_id' => $this->gameId,
            'players' => $this->players,
            'phase' => $this->phase,
            'day' => $this->day,
            'votes' => $this->votes,
            'night_actions' => $this->nightActions,
            'alive' => $this->alive,
            'dead' => $this->dead,
            'frozen' => $this->frozen,
            'phase_start_time' => $this->phaseStartTime,
            'action_deadline' => $this->actionDeadline,
            'notified_60' => $this->notified60,
            'notified_30' => $this->notified30,
            'timeout_sent' => $this->timeoutSent,
            'timer_message_id' => $this->timerMessageId,
            'messages_for_deletion' => $this->messagesForDeletion
        ];
    }

    public static function fromArray($data) {
        $game = new self($data['chat_id'], $data['game_id'] ?? null);
        
        $players = $data['players'];
        foreach ($players as $userId => &$player) {
            if (!isset($player['first_name'])) {
                $player['first_name'] = $player['name'] ?? $player['username'] ?? 'Unknown';
            }
            if (!isset($player['last_name'])) {
                $player['last_name'] = '';
            }
            if (!isset($player['username'])) {
                $player['username'] = '';
            }
            $player['name'] = self::formatPlayerName(
                $player['first_name'], 
                $player['last_name'], 
                $player['username']
            );
        }
        unset($player);
        
        $game->players = $players;
        $game->phase = $data['phase'];
        $game->day = $data['day'];
        $game->votes = $data['votes'];
        $game->nightActions = $data['night_actions'];
        $game->alive = $data['alive'];
        $game->dead = $data['dead'];
        $game->frozen = $data['frozen'] ?? [];
        $game->phaseStartTime = $data['phase_start_time'] ?? 0;
        $game->actionDeadline = $data['action_deadline'] ?? 0;
        $game->notified60 = $data['notified_60'] ?? false;
        $game->notified30 = $data['notified_30'] ?? false;
        $game->timeoutSent = $data['timeout_sent'] ?? false;
        $game->timerMessageId = $data['timer_message_id'] ?? null;
        $game->messagesForDeletion = $data['messages_for_deletion'] ?? [];
        return $game;
    }
}
