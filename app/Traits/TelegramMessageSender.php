<?php

namespace App\Traits;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;

trait TelegramMessageSender
{
    protected function sendMessage(int $chatId, string $text, $replyMarkup = null, ?string $parseMode = null): void
    {
        try {
            $params = ['chat_id' => $chatId, 'text' => $text];
            if ($replyMarkup !== null) {
                $params['reply_markup'] = $replyMarkup;
            }
            if ($parseMode !== null) {
                $params['parse_mode'] = $parseMode;
            }
            Telegram::sendMessage($params);
        } catch (TelegramSDKException $e) {
            Log::error("Failed to send message to chat {$chatId}", ['error' => $e->getMessage()]);
        }
    }
}