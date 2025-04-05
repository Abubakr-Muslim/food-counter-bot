<?php

namespace App\Commands;

use App\Models\Customer;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;
use Exception;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Начало работы с ботом и настройка профиля';

    public function handle()
    {
        Log::info("StartCommand: инициализация");

        try {
            $update = $this->getUpdate();
            if (!$update || !$update->getMessage()) {
                 Log::warning('StartCommand: Received update without message.');
                 return; 
            }
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $tgUser = $message->getFrom();

            if (!$tgUser) {
                Log::warning('StartCommand: Received message without user info.', ['chat_id' => $chatId]);
                return; 
            }

            $telegramUserId = $tgUser->getId();
            $firstName = $tgUser->getFirstName();
            $lastName = $tgUser->getLastName(); 
            $username = $tgUser->getUsername(); 

            Log::info("StartCommand: User ID {$telegramUserId}, Username: {$username}. Attempting DB operation..."); 

            $customer = Customer::updateOrCreate(
                ['tg_id' => $telegramUserId], 
                [ 
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'login' => $username,
                    'state' => 'awaiting_goal'
                ]
            );
            Log::info("StartCommand: Customer ID={$customer->id} установлен. State установлен на 'awaiting_goal' в DB. Отправление сообщения...");

            $welcomeMessage = "Привет, {$firstName}! 👋 Я твой личный помощник по здоровому питанию и помогу тебе следить за калориями и вести дневник питания." . PHP_EOL . PHP_EOL .
                              "Ты можешь присылать мне фото еды, и я вычислю её калорийность чтобы твой рацион был сбалансированным и эффективным 📸🍽️" . PHP_EOL . PHP_EOL .
                              "Чтобы я помогал тебе ещё лучше, давай настроим твой профиль - это займёт всего несколько секунд! ✨" . PHP_EOL . PHP_EOL .
                              "Используй команду /help, чтобы увидеть список доступных команд.";

            $this->replyWithMessage(['text' => $welcomeMessage]);
            Log::info("StartCommand: Welcome message отправлен.");

            $goalKeyboard = Keyboard::make()
                ->setResizeKeyboard(true)
                ->setOneTimeKeyboard(true)
                ->row(['Сбросить вес', 'Удержать вес', 'Нарастить мышцы']);

            $this->replyWithMessage([
                'text' => 'Какая у тебя основная цель?',
                'reply_markup' => $goalKeyboard
            ]);
            Log::info("StartCommand: Задан вопрос про цель."); 

        } catch (Exception $e) {
             Log::error("!!! StartCommand FAILED !!!", [ 
                 'user_id' => $telegramUserId ?? 'unknown', 
                 'error_message' => $e->getMessage(),
                 'file' => $e->getFile(),
                 'line' => $e->getLine(),
                 'trace' => $e->getTraceAsString() 
             ]);
            try {
                 $chatIdForError = $this->getUpdate()?->getMessage()?->getChat()?->getId();
                 if ($chatIdForError) {
                     $this->replyWithMessage(['chat_id' => $chatIdForError, 'text' => 'Произошла ошибка при запуске команды. Попробуйте позже.']);
                 }
            } catch (Exception $sendError) {
                 Log::error("StartCommand: Could not send error message to user.", ['send_error' => $sendError->getMessage()]);
            }
        }
    }
}