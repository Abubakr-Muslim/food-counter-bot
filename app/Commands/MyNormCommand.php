<?php

namespace App\Commands;

use App\Models\Customer;
use App\Services\CalorieCalculatorService;
use Exception;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramSDKException;

class MyNormCommand extends Command
{
    protected string $name = 'mynorm';
    protected string $description = 'Показать мою дневную норму калорий';

    public function __construct(protected CalorieCalculatorService $calculatorService)
    {
    }

    public function handle(): void
    {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $userId = $this->getUpdate()->getMessage()->getFrom()->getId();

        try {
            $update = $this->getUpdate();
            if (!$update?->getMessage()?->getChat()?->getId() || !$update?->getMessage()?->getFrom()?->getId()) {
                Log::warning("MyNormCommand: Invalid update or missing chat/user ID.");
                return;
            }
            $chatId = $update->getMessage()->getChat()->getId();
            $userId = $update->getMessage()->getFrom()->getId();

            Log::info("MyNormCommand: Initiated by User={$userId}.");

            $customer = Customer::where('tg_id', $userId)->first();

            if (!$customer) {
                Log::warning("MyNormCommand: Customer not found for User={$userId}");
                $this->replyWithMessage([
                    'text' => 'Я не нашел ваш профиль. 🤔 Пожалуйста, пройдите быструю настройку с помощью команды /start.'
                ]);
                return;
            }

            $info = $customer->customerInfo()->latest()->first();

            if (!$info || !$this->calculatorService->hasRequiredData($info)) {
                Log::warning("MyNormCommand: CustomerInfo not found or incomplete for User={$userId}");
                $this->replyWithMessage([
                    'text' => 'Ваш профиль заполнен не полностью. 🙁 Пожалуйста, завершите настройку через /start, чтобы я мог рассчитать вашу норму.'
                ]);
                return;
            }

            Log::info("MyNormCommand: User={$userId}. Profile data found. Calculating norm...");

            $result = $this->calculatorService->calculateNorm($info); 

            if ($result !== null && isset($result['calories'])) {
                $responseText = sprintf(
                    "✅ Ваша текущая цель: *%s*\n\n" .
                    "📊 *Дневная норма: ~%d ккал*\n\n" .
                    "🍽 *БЖУ:*\n" .
                    " 🍗 Белки: *~%dг*\n" .
                    " 🥑 Жиры: *~%dг*\n" .
                    " 🍞 Углеводы: *~%dг*",
                    htmlspecialchars($info->goal ?? 'Не указана'),
                    $result['calories'],
                    $result['protein'],
                    $result['fat'],
                    $result['carbs'],
                );
                $this->sendMessage($chatId, $responseText, null, 'Markdown'); 
           } else {
                Log::error("MyNormCommand: Calculator service returned null or invalid array for User={$userId}");
                $this->replyWithMessage([
                    'text' => 'К сожалению, не удалось рассчитать вашу норму калорий и БЖУ... Попробуйте позже.'
                ]);
           }

            Log::info("MyNormCommand: Finished for User={$userId}.");

        } catch (Exception $e) {
            Log::error("!!! MyNormCommand FAILED !!!", [
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            if ($chatId) {
                try {
                    $this->replyWithMessage(['chat_id' => $chatId, 'text' => 'Ой! Произошла ошибка... Попробуйте позже.']);
                } catch (Exception $sendError) {
                    Log::error("MyNormCommand: Could not send error message...", ['send_error' => $sendError->getMessage()]);
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
            Log::error("Failed to send message from MyNormCommand to chat {$chatId}", ['error' => $e->getMessage()]);
        }
    }
}