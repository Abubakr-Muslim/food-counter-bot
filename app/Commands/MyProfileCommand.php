<?php

namespace App\Commands;

use App\Models\Customer;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramSDKException;

class MyProfileCommand extends Command
{
    protected string $name = 'myprofile';
    protected string $description = 'Показать данные моего профиля';

    public function handle(): void
    {
        $userId = null;
        $chatId = null;

        try {
            $update = $this->getUpdate();
            if (!$update?->getMessage()?->getChat()?->getId() || !$update?->getMessage()?->getFrom()?->getId()) {
                Log::warning("MyProfileCommand: Invalid update or missing chat/user ID.");
                return;
            }
            $chatId = $update->getMessage()->getChat()->getId();
            $userId = $update->getMessage()->getFrom()->getId();

            Log::info("MyProfileCommand: Initiated by User={$userId}.");

            $customer = Customer::where('tg_id', $userId)->first();

            if (!$customer) {
                Log::warning("MyProfileCommand: Customer not found for User={$userId}");
                $this->replyWithMessage([
                    'text' => 'Я не нашел ваш профиль. 🤔 Пожалуйста, пройдите настройку с помощью команды /start.'
                ]);
                return;
            }

            $info = $customer->customerInfo()->latest()->first();

            if (!$info) {
                Log::warning("MyProfileCommand: CustomerInfo not found for User={$userId}");
                $this->replyWithMessage([
                    'text' => 'Ваш профиль ещё не настроен. 🙁 Завершите настройку через /start.'
                ]);
                return;
            }

            Log::info("MyProfileCommand: User={$userId}. Profile data found.");

            $birthdateFormatted = $info->birthdate ? Carbon::parse($info->birthdate)->isoFormat('LL') : 'Не указана';
            $weightFormatted = $info->weight ? $info->weight . ' кг' : 'Не указан';
            $heightFormatted = $info->height ? $info->height . ' см' : 'Не указан';

            $profileMessage = "<b>📋 Ваш профиль:</b>\n\n" .
                              "🎯 <b>Цель:</b> " . htmlspecialchars($info->goal ?? 'Не указана') . "\n" .
                              "👤 <b>Пол:</b> " . htmlspecialchars($info->gender ?? 'Не указан') . "\n" .
                              "📅 <b>Дата рождения:</b> " . htmlspecialchars($birthdateFormatted) . "\n" .
                              "🏃 <b>Активность:</b> " . htmlspecialchars($info->activity_level ?? 'Не указана') . "\n" .
                              "📏 <b>Рост:</b> " . htmlspecialchars($heightFormatted) . "\n" .
                              "⚖️ <b>Вес:</b> " . htmlspecialchars($weightFormatted) . "\n\n" .
                              "Если хотите обновить данные, используйте <i>/start</i>.";

            $this->sendMessage($chatId, $profileMessage, null, 'HTML');

            Log::info("MyProfileCommand: Finished for User={$userId}.");

        } catch (Exception $e) {
            Log::error("!!! MyProfileCommand FAILED !!!", [
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            if ($chatId) {
                try {
                    $this->replyWithMessage(['chat_id' => $chatId, 'text' => 'Ой! Произошла ошибка при загрузке профиля. Попробуйте позже.']);
                } catch (Exception $sendError) {
                    Log::error("MyProfileCommand: Could not send error message...", ['send_error' => $sendError->getMessage()]);
                }
            }
        }
    }

    protected function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
    {
        try {
            $params = ['chat_id' => $chatId, 'text' => $text];
            if ($replyMarkup !== null) {
                $params['reply_markup'] = $replyMarkup;
            }
            if ($parseMode !== null) {
                $params['parse_mode'] = $parseMode;
            }
            $this->getTelegram()->sendMessage($params);
        } catch (TelegramSDKException $e) {
            Log::error("Failed to send message from MyProfileCommand to chat {$chatId}", ['error' => $e->getMessage()]);
        }
    }
}