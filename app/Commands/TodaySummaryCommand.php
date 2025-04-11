<?php

namespace App\Commands;

use App\Models\Customer;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\CalorieCalculatorService;
use Exception;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException; 


class TodaySummaryCommand extends Command
{
    protected string $name = 'today';
    protected string $description = 'Показать сводку КБЖУ за сегодня';

    public function __construct(protected CalorieCalculatorService $calculatorService)
    {
    }
    public function handle()
    {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $userId = $this->getUpdate()->getMessage()->getFrom()->getId();

        try {
            $update = $this->getUpdate();

            $chatId = $update->getMessage()->getChat()->getId();
            $userId = $update->getMessage()->getFrom()->getId();

            Log::info("TodaySummaryCommand: Initiated by User={$userId}.");

            $customer = Customer::where('tg_id', $userId)->first();
            if (!$customer) {
                Log::warning("TodaySummaryCommand: Customer not found for User={$userId}");
                $this->replyWithMessage(['text' => 'Профиль не найден. Используйте /start.']);
                return;
            }

            $info = $customer->customerInfo()->latest()->first();
            if (!$info || !$this->calculatorService->hasRequiredData($info)) {
                Log::warning("TodaySummaryCommand: CustomerInfo not found or incomplete for User={$userId}");
                $this->replyWithMessage(['text' => 'Профиль не заполнен. Используйте /start.']);
                return;
            }

            $dailyTotals = $customer->getDailyTotals();
            $normData = $this->calculatorService->calculateNorm($info);

            $responseText = "📊 *Сводка за сегодня:*\n\n";
            if ($normData && isset($normData['calories'])) {
                $responseText .= sprintf(
                    "Калории: *%d* / %d ккал\n".
                    "Белки: *%.1f* / %d г\n".
                    "Жиры: *%.1f* / %d г\n".
                    "Углеводы: *%.1f* / %d г",
                    $dailyTotals['total_calories'], $normData['calories'],
                    $dailyTotals['total_protein'], $normData['protein'] ?? 0,
                    $dailyTotals['total_fat'], $normData['fat'] ?? 0,
                    $dailyTotals['total_carbs'], $normData['carbs'] ?? 0
                );
                    if ($dailyTotals['total_calories'] > $normData['calories']) {
                        $exceeded = $dailyTotals['total_calories'] - $normData['calories'];
                        $responseText .= "\n\n⚠️ *Превышение нормы калорий на {$exceeded} ккал!*";
                    } elseif ($dailyTotals['total_calories'] > $normData['calories'] * 0.9) {
                        $responseText .= "\n\n👀 *Норма калорий почти достигнута.*";
                    }
            } else {
                    $responseText .= sprintf(
                    "Калории: *%d* ккал\n".
                    "Белки: *%.1f* г\n".
                    "Жиры: *%.1f* г\n".
                    "Углеводы: *%.1f* г",
                    $dailyTotals['total_calories'],
                    $dailyTotals['total_protein'],
                    $dailyTotals['total_fat'],
                    $dailyTotals['total_carbs']
                );
                $responseText .= "\n_(Не удалось получить норму для сравнения)_";
            }

            $this->replyWithMessage(['text' => $responseText, 'parse_mode' => 'Markdown']);

            Log::info("TodaySummaryCommand: Finished for User={$userId}.");

        } catch (Exception $e) {
            Log::error("!!! TodaySummaryCommand FAILED !!!", []);
            if ($chatId) {}
        }
    }
}