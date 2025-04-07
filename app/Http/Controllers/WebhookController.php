<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;
use App\Services\CalorieCalculatorService; 


class WebHookController extends Controller
{
    protected BotsManager $botsManager;
    protected CalorieCalculatorService $calculatorService;

    public function __construct(BotsManager $botsManager, CalorieCalculatorService $calculatorService)
    {
        $this->botsManager = $botsManager;
        $this->calculatorService = $calculatorService;
    }

    public function __invoke(Request $request)
    {

        try {
              $this->botsManager->bot()->commandsHandler(true);
        } catch (Exception $e) {
              Log::error("Error during commandsHandler execution", ['error' => $e->getMessage()]);
        }

        $update = Telegram::getWebhookUpdates();

        if ($update->has('callback_query')) {
            $callbackQuery = $update->getCallbackQuery();
            $chatId = $callbackQuery->getMessage()->getChat()->getId();
            $userId = $callbackQuery->getFrom()->getId();
            $data = $callbackQuery->getData();

            Log::info("Webhook: Received callback_query from user {$userId}", ['data' => $data]);

            try {
                $customer = Customer::where('tg_id', $userId)->firstOrFail();
                $this->handleCallbackQuery($chatId, $customer, $data);
                // Подтверждаем получение callback_query
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (ModelNotFoundException $e) {
                Log::error("Webhook: Customer not found for tg_id: {$userId}");
                $this->sendMessage($chatId, 'Ваш профиль не найден. Пожалуйста, нажмите /start для начала.');
            } catch (Exception $e) {
                Log::error("Webhook: Error handling callback_query for user {$userId}", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $this->sendMessage($chatId, 'Произошла ошибка. Попробуйте позже.');
            }

            return response(null, 200);
        }

        if (!$update->has('message') || !$update->getMessage()->getFrom()) {
            Log::info('Webhook: Update without message or user info.');
            return response(null, 200);
        }

        $message = $update->getMessage();
        $messageText = $message->getText(); 

        if ($messageText && str_starts_with($messageText, '/')) {
            Log::info("Webhook: Ignoring command '{$messageText}'.");
            return response(null, 200);
        }

        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();

         if ($messageText === null) {
              Log::info("Webhook: Ignoring non-text message from user {$userId}.");
              return response(null, 200);
         }

        try {
            $customer = Customer::where('tg_id', $userId)->firstOrFail();
            $currentState = $customer->state;

            Log::info("Webhook: User={$userId}, DB State='{$currentState}', Message='{$messageText}'");

            $this->handleUserState($currentState, $customer, $chatId, $messageText);
        } catch (ModelNotFoundException $e) {
            Log::error("Webhook: Customer не найден для tg_id: {$userId}");
            $this->sendMessage($chatId, 'Ваш профиль не найден. Пожалуйста, нажмите /start для начала.');
        } catch (Exception $e) {
            Log::error("Webhook: General error для пользователя: {$userId}", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->sendMessage($chatId, 'Произошла непредвиденная ошибка. Попробуйте позже.');
        }

        return response(null, 200);
    }
    protected function handleUserState(?string $currentState, Customer $customer, int $chatId, string $messageText): void
    {
        switch ($currentState) {
            case 'awaiting_goal':
                $validGoals = ['Сбросить вес', 'Удержать вес', 'Нарастить мышцы'];
                if (in_array($messageText, $validGoals)) {
                    if ($this->saveCustomerInfo($customer, ['goal' => $messageText], $chatId, 'saving goal', true)) {
                        $customer->update(['state' => 'awaiting_gender']);
                        $this->askGender($chatId);
                    }
                } else {
                    $this->sendMessage($chatId, 'Пожалуйста, выберите цель:');
                }
                break;

            case 'awaiting_gender':
                $validGenders = ['Мужской', 'Женский'];
                if (in_array($messageText, $validGenders)) {
                    if ($this->saveCustomerInfo($customer, ['gender' => $messageText], $chatId, 'saving gender')) { // false = use update
                        $customer->update(['state' => 'awaiting_birthdate']);
                        $this->askBirthdate($chatId);
                    }
                } else {
                    $this->sendMessage($chatId, 'Пожалуйста, выберите пол, используя кнопки.');
                }
                break;

            case 'awaiting_birthdate':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $messageText) && $this->isValidDate($messageText)) {
                     if ($this->saveCustomerInfo($customer, ['birthdate' => $messageText], $chatId, 'saving birthdate')) {
                        $customer->update(['state' => 'awaiting_activity']);
                        $this->askActivityLevel($chatId);
                     }
                } else {
                    $this->sendMessage($chatId, 'Неверный формат или дата. Введите, пожалуйста, в формате ГГГГ-ММ-ДД (например, 1990-05-21) и убедитесь, что дата корректна и не в будущем.');
                }
                break;

            case 'awaiting_activity':
                $validActivities = ['Высокая активность', 'Средняя активность', 'Минимум активности', 'Сидячий образ жизни'];
                if (in_array($messageText, $validActivities)) {
                     if ($this->saveCustomerInfo($customer, ['activity_level' => $messageText], $chatId, 'saving activity')) {
                        $customer->update(['state' => 'awaiting_height']);
                        $this->askHeight($chatId);
                     }
                } else {
                    $this->sendMessage($chatId, 'Пожалуйста, выберите уровень активности, используя кнопки.');
                }
                break;

            case 'awaiting_height':
                 $heightInput = filter_var($messageText, FILTER_SANITIZE_NUMBER_INT);
                 if (is_numeric($heightInput) && $heightInput >= 50 && $heightInput <= 280) {
                      if ($this->saveCustomerInfo($customer, ['height' => (int)$heightInput], $chatId, 'saving height')) {
                        $customer->update(['state' => 'awaiting_weight']);
                        $this->askWeight($chatId);
                      }
                 } else {
                     $this->sendMessage($chatId, 'Пожалуйста, введите ваш рост в сантиметрах (число от 50 до 280).');
                 }
                 break;

            case 'awaiting_weight':
                $weightInput = str_replace(',', '.', $messageText);
                $weightInput = filter_var($weightInput, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                if (is_numeric($weightInput) && $weightInput > 20 && $weightInput < 500) {
                    if ($this->saveCustomerInfo($customer, ['weight' => (float)$weightInput], $chatId, 'saving weight')) {
                        $customer->update(['state' => null]); 
                        Log::info("Onboarding completed for customer {$customer->id}. Session state cleared.");
                        $this->sendFinalSummary($chatId, $customer);
                        Log::info("Webhook: Attempting to call sendCalorieNorm for customer {$customer->id}");
                        $this->sendCalorieNorm($chatId, $customer);
                        Log::info("Webhook: Finished calling sendCalorieNorm for customer {$customer->id}");                  }
                } else {
                    $this->sendMessage($chatId, 'Пожалуйста, введите ваш вес в килограммах (число от 20 до 500, можно с точкой или запятой).');
                }
                break;

            default:
                 Log::info("Webhook: User={$customer->tg_id}, Unhandled state='{$currentState}', Message='{$messageText}'");
                break;
        } 
    }
    protected function saveCustomerInfo(Customer $customer, array $data, int $chatId, string $actionDescription, bool $forceCreate = false): bool
    {
        try {
            $info = null;
            if ($forceCreate) {
                $info = $customer->customerInfo()->create($data);
                 Log::info("Created new CustomerInfo for {$actionDescription}, customer {$customer->id}", ['customer_info_id' => $info->id] + $data);
            } else {
                $info = $customer->customerInfo()->latest()->first();
                if ($info) {
                    $info->update($data);
                     Log::info("Updated CustomerInfo for {$actionDescription}, customer {$customer->id}", ['customer_info_id' => $info->id] + $data);
                } else {
                    Log::error("Failed {$actionDescription}: CustomerInfo record not found for customer {$customer->id} when update was expected.");
                    $this->sendMessage($chatId, 'Произошла внутренняя ошибка: профиль не найден для обновления. Попробуйте /start.');
                     session()->forget("onboarding_state_{$customer->tg_id}"); 
                    return false; 
                }
            }
            return true; 
        } catch (Exception $e) {
            $this->handleError($e, $chatId, $customer->id, $actionDescription);
            return false; 
        }
    }
    protected function isValidDate(string $dateString): bool
    {
         try {
             $date = Carbon::createFromFormat('Y-m-d', $dateString);
             return $date && $date->format('Y-m-d') === $dateString && !$date->isFuture();
         } catch (Exception $e) {
             return false;
         }
    }
    protected function sendMessage(int $chatId, string $text, $replyMarkup = null, $parseMode = null): void
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
    protected function askGender(int $chatId): void
    {
         $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->row(['Мужской', 'Женский']);
         $this->sendMessage($chatId, 'Отлично! Теперь выберите свой пол:', $keyboard);
    }
    protected function askBirthdate(int $chatId): void
    {
        $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true);
            
        $this->sendMessage($chatId, 'Пожалуйста, введите свою дату рождения в формате ГГГГ-ММ-ДД (например, 1999-01-15):', $keyboard);
    }
    protected function askActivityLevel(int $chatId): void
    {
        $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->row(['Высокая активность', 'Средняя активность'])
            ->row(['Минимум активности', 'Сидячий образ жизни']);

        $this->sendMessage($chatId, 'Выберите ваш обычный уровень активности:', $keyboard);
    }
    protected function askHeight(int $chatId): void
    {
        $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true);

        $this->sendMessage($chatId, 'Введите ваш рост в сантиметрах (например, 175):', $keyboard);
    }
    protected function askWeight(int $chatId): void
    {
        $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true);

        $this->sendMessage($chatId, 'Введите ваш текущий вес в килограммах (например, 68.5):', $keyboard);
    }
    protected function sendCalorieNorm(int $chatId, Customer $customer): void
    {
        Log::info("sendCalorieNorm: Method entered for customer {$customer->id}");
        $info = $customer->customerInfo()->latest()->first();

        if (!$info) {
            Log::error("sendCalorieNorm: CustomerInfo not found for customer {$customer->id}!");
            return;
        }

        Log::info("sendCalorieNorm: CustomerInfo found (ID: {$info->id}). Calling calculatorService->calculateNorm...");

        if (!isset($this->calculatorService)) {
            Log::error("sendCalorieNorm: CalorieCalculatorService is not set!");
            return;
        }

        $result = $this->calculatorService->calculateNorm($info);
        Log::info("sendCalorieNorm: Calculation result: " . json_encode($result)); 

        if ($result !== null && isset($result['calories'])) {
            $messageText = sprintf(
                "✅ Исходя из ваших данных и цели '%s':\n\n".
                "Ваша примерная дневная норма: ~<b>%d ккал</b>\n".
                "БЖУ: <b>~%dг</b> белка / <b>~%dг</b> жира / <b>~%dг</b> углеводов",
                htmlspecialchars($info->goal ?? 'Не указана'),
                $result['calories'],
                $result['protein'] ?? 0,
                $result['fat'] ?? 0,
                $result['carbs'] ?? 0
            );

            Log::info("sendCalorieNorm: Attempting to send norm message...");
            $this->sendMessage($chatId, $messageText, null, 'HTML');
            Log::info("sendCalorieNorm: Message supposedly sent.");
        } else {
            Log::error("sendCalorieNorm: Calculation returned null or invalid array. Sending fallback message.");
            $this->sendMessage($chatId, "Не удалось рассчитать вашу норму калорий и БЖУ. Вы можете попробовать команду /mynorm позже.");
        }
        Log::info("sendCalorieNorm: Method finished for customer {$customer->id}");
    }
    protected function handleCallbackQuery(int $chatId, Customer $customer, string $data): void
    {
        switch ($data) {
            case 'profile':
                $this->sendFinalSummary($chatId, $customer);
                break;
            case 'norm':
                $this->sendCalorieNorm($chatId, $customer); 
                break;
            case 'start':
                $customer->update(['state' => 'awaiting_goal']);
                $this->sendMessage($chatId, 'Давайте начнём заново. Выберите вашу цель:');
                break;
            default:
                Log::warning("Webhook: Unknown callback data '{$data}' for customer {$customer->id}");
                $this->sendMessage($chatId, 'Неизвестное действие. Попробуйте снова.');
                break;
        }
    }
    protected function sendFinalSummary(int $chatId, Customer $customer): void
    {
        $customer->load('customerInfo');
        $info = $customer->customerInfo()->latest()->first(); 

        if (!$info) {
             Log::error("Cannot send summary, CustomerInfo not found for customer {$customer->id}");
             $this->sendMessage($chatId, 'Не удалось загрузить ваш профиль для отображения.');
             return;
        }

        $birthdateFormatted = $info->birthdate ? Carbon::parse($info->birthdate)->isoFormat('LL') : 'Не указана';

        $finalMessage = "Спасибо! 👍 Ваш профиль успешно настроен:\n\n" .
                        "<b>🎯 Цель:</b> " . ($info->goal ?? 'Не указана') . "\n" .
                        "<b>👤 Пол:</b> " . ($info->gender ?? 'Не указан') . "\n" .
                        "<b>📅 Дата рождения:</b> " . $birthdateFormatted . "\n" .
                        "<b>🏃 Активность:</b> " . ($info->activity_level ?? 'Не указана') . "\n" .
                        "<b>📏 Рост:</b> " . ($info->height ? $info->height . ' см' : 'Не указан') . "\n" .
                        "<b>⚖️ Вес:</b> " . ($info->weight ? $info->weight . ' кг' : 'Не указан') . "\n\n" .
                        "Теперь вы можете отправлять мне <i>фотографии еды</i> для анализа! 📸";
        
        $keyboard = Keyboard::make()->setRemoveKeyboard(true);
        $this->sendMessage($chatId, $finalMessage, $keyboard, 'HTML');
    }
    protected function handleError(Exception $e, int $chatId, int $customerId, string $action): void
    {
        Log::error("Error during '{$action}' for customer {$customerId}", [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        $this->sendMessage($chatId, "Произошла ошибка при обработке ваших данных. Пожалуйста, попробуйте еще раз.");
    }
} 