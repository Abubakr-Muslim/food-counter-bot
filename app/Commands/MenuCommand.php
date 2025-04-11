<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException;

class MenuCommand extends Command
{
    protected string $name = 'menu';
    protected string $description = 'Показать меню действий';

    public function handle()
    {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();

        $keyboard = Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton(['text' => '⚙️ Мой профиль', 'callback_data' => 'profile']),
                Keyboard::inlineButton(['text' => '🎯 Моя цель', 'callback_data' => 'norm']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📊 Сводка за сегодня', 'callback_data' => 'today'])
            ])
            ->row([
                Keyboard::inlineButton(['text' => 'Начать заново', 'callback_data' => 'start']),
            ]);

        $message = "Выберите действие:";

        try {
            $this->replyWithMessage([
                'text' => $message,
                'reply_markup' => $keyboard,
            ]);
            Log::info("MenuCommand: Sent inline keyboard to chat {$chatId}");
        } catch (TelegramSDKException $e) {
            Log::error("MenuCommand: Failed to send inline keyboard to chat {$chatId}", ['error' => $e->getMessage()]);
        }
    }
}