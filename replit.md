# Telegram Mafia Bot

## Overview
This project is a Telegram bot designed to facilitate the game of Mafia. Its primary purpose is to manage the game flow, assign roles, and enforce the day/night cycle within a Telegram group chat. The bot aims to provide a complete, interactive Mafia game experience, ensuring fair play and adherence to game rules. It offers a rich set of roles and mechanics to support dynamic gameplay.

## User Preferences
- All interaction with the bot should be exclusively through inline buttons, without the use of a bottom keyboard.
- Player names should be displayed as full name (first name + last name) with Telegram username in parentheses, e.g., "Иван Петров (@ivan_p)".
- The game should only be creatable in group chats; a message should be sent to the user if they try to create a game in a private chat.

## System Architecture
The bot operates on a PHP 8.2 backend, interacting with the Telegram Bot API. Game state is managed through JSON files stored in a `sessions/` directory, uniquely identified by `gameId`.

**UI/UX Decisions:**
- All user interaction is driven by `InlineKeyboardMarkup`, eliminating the need for a bottom keyboard.
- Player names are formatted consistently as "FirstName LastName (@username)".

**Technical Implementations:**
- **Player Registration:** Players can join using `/start` and `/join` commands.
- **Game Rooms:** Games are initiated exclusively in group chats.
- **Phased Gameplay:** The game progresses through distinct phases: REGISTRATION (3 min) → NIGHT (3 min) → DISCUSSION (5 sec) → VOTE (4 min).
- **Role Assignment:** Automatic, deterministic distribution of 12 roles (Mafia, Don, Townspeople, Commissioner, Doctor, Bum, Mistress, Maniac, Lawyer, Lucky, Kamikaze, Suicide Bomber) based on player count, ensuring balanced gameplay.
- **Voting Mechanism:** Day voting requires a strict majority. Mafia votes for a common victim.
- **Night Actions:** Various roles perform specific actions at night (e.g., Commissioner checks/kills, Doctor protects, Bum checks, Mistress freezes, Maniac kills).
- **Timers:** An independent `timer_checker.php` script runs every 5 seconds to manage phase transitions, using file locking to prevent race conditions. Timer messages update in place to reduce chat clutter.
- **Game Lifecycle Service:** `GameLifecycleService.php` centralizes all phase transition logic and game state management.
- **File Locking & Concurrency Control:** All game session file operations use `flock()` (LOCK_EX for writing, LOCK_SH for reading) to ensure data integrity. Phase validation prevents stale data from overwriting current game states.
- **Atomic Action Submission (Nov 24, 2025):** Implemented `trySetNightAction()` and `tryAddVote()` atomic methods that prevent race conditions during concurrent player actions. These methods: 1) Lock the session file, 2) Reload fresh state from disk, 3) Verify no duplicate action exists, 4) Save the action, 5) Unlock and return updated Game object. All 7 action handlers (kill, save, check, freeze, protect, detectivekill, vote) now use atomic methods with null data protection.
- **Game State Management:** Game states are saved *before* sending messages to prevent race conditions.
- **Callback Data Format:** `action_userId_groupChatId_gameId` is used for inline button callback data.
- **Error Handling:** Enhanced error handling for private messages and API calls, providing warnings to the group chat if players haven't started a dialogue with the bot.
- **Timer Error Suppression (Nov 24, 2025):** Telegram API errors for "message not found" are no longer logged when attempting to edit/delete countdown timer messages. These are normal situations (user deleted message or message expired) and don't indicate actual problems.
- **Callback Button Fix (Nov 24, 2025):** Fixed issue where callback buttons in private messages were not responding. Root cause: When editing messages after action selection, `editMessageText` was called without keyboard parameter. Solution: Reverted erroneous emptyKeyboard additions - simply call editMessageText without the 4th parameter to leave keyboard untouched.

**Feature Specifications:**
- **Game Roles** (See ROLES.md for detailed descriptions):
    - **Don** 🎩: Mafia boss; has deciding vote and promotes a random mafia member upon death. (Always)
    - **Mafia** 🔪: Kills townspeople at night. (4+ players)
    - **Citizen** 👤: Votes to eliminate suspects. (Filler role)
    - **Detective/Commissioner** 🔍: Can check or kill a player at night. (Always)
    - **Doctor** 💊: Protects a player from being killed. (Always)
    - **Homeless/Bum** 🏚: Checks players for Mafia affiliation. (7+ players)
    - **Kamikaze** 💣: Takes a random victim when lynched. (7+ players)
    - **Suicide** 💀: Wins only if lynched during day vote. (8+ players)
    - **Maniac** 🔪: Neutral killer, wins by eliminating everyone. (9+ players)
    - **Lawyer** ⚖️: Protects a client from checks (appears as townspeople). (10+ players)
    - **Lucky** 🍀: 50% chance to survive attacks at night. (6-7, 11-12 players)
    - **Lover** 💋: ⚠️ NOT DISTRIBUTED - Code exists but role is excluded from game.
- **Auto-Advance:** Game automatically transitions to the next phase when all required actions are completed (e.g., all night actions, all votes).
- **/suicide command:** Players can voluntarily leave the game, triggering game-ending conditions if applicable.
- **Alive Players List (Nov 24, 2025):** At the start of each night phase, the bot displays a list of all living players in the group chat.
- **Death Announcements (Nov 24, 2025):** When a player is killed during the night, their name and role are revealed to all players in the morning announcement.

## External Dependencies
- **Telegram Bot API:** For all bot interactions and messaging.
- **JSON:** Used for storing game session data.
- **PHP's `flock()` function:** For file locking mechanisms to ensure data integrity during concurrent file access.
- **Cron-like mechanism:** For scheduling the `timer_checker.php` script to run periodically.