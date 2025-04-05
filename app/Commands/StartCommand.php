<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Exception;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Начало работы с ботом и настройка профиля';

    public function handle()
    {
        $update = $this->getUpdate();
        if (!$update || !$update->getMessage()) {
            Log::warning('StartCommand: Получено обновление без сообщения.');
            return;
        }

        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $tgUser = $message->getFrom();

        if (!$tgUser) {
            Log::warning('StartCommand: Получено сообщение без информации о пользователе.', ['chat_id' => $chatId]);
            return;
        }

        $telegramUserId = $tgUser->getId();
        $firstName = $tgUser->getFirstName();
        $lastName = $tgUser->getLastName(); 
        $username = $tgUser->getUsername(); 

        try {
            $customer = Customer::updateOrCreate(
                ['tg_id' => $telegramUserId],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'login' => $username
                ]
            );

            Log::info("StartCommand: Запись клиента обработана.", ['customer_id' => $customer->id]);

            $stateKey = "onboarding_state_{$telegramUserId}";
            $dataKey = "onboarding_data_{$telegramUserId}"; 

            session()->forget($stateKey);
            session()->forget($dataKey);

            session([$stateKey => 'awaiting_goal']);
            Log::info("StartCommand: Состояние сеанса для пользователя установлено: {$telegramUserId}");

            $welcomeMessage = "Привет, {$firstName}! 👋 Я твой личный помощник по здоровому питанию и помогу тебе следить за калориями и вести дневник питания." . PHP_EOL . PHP_EOL .
                              "Ты можешь присылать мне фото еды, и я вычислю её калорийность чтобы твой рацион был сбалансированным и эффективным 📸🍽️" . PHP_EOL . PHP_EOL .
                              "Чтобы я помогал тебе ещё лучше, давай настроим твой профиль - это займёт всего несколько секунд! ✨" . PHP_EOL . PHP_EOL .
                              "Используй команду /help, чтобы увидеть список доступных команд.";

            $this->replyWithMessage(['text' => $welcomeMessage]);

            $goalKeyboard = Keyboard::make()
                ->setResizeKeyboard(true)
                ->setOneTimeKeyboard(true)
                ->row([
                    'Сбросить вес',
                    'Удержать вес',
                    'Набор массы',
                ]);

            $this->replyWithMessage([
                'text' => 'Какая у тебя основная цель?',
                'reply_markup' => $goalKeyboard
            ]);

            Log::info("StartCommand: Пользователю отправлен выбор цели {$telegramUserId}");

        } catch (Exception $e) {
            Log::error("StartCommand: Ошибка обработки пользователя {$telegramUserId}", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->replyWithMessage([
                'text' => 'К сожалению, произошла ошибка при настройке вашего профиля. 😥 Пожалуйста, попробуйте нажать /start еще раз чуть позже.'
            ]);
        }
    }
}
