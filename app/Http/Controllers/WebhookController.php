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
use App\Traits\TelegramMessageSender;

class WebHookController extends Controller
{
    use TelegramMessageSender;
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
                $this->handleCallbackQuery($chatId, $customer, $data, $callbackQuery);
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
        $handlers = [
            'awaiting_goal' => [$this, 'handleAwaitingGoal'],
            'awaiting_gender' => [$this, 'handleAwaitingGender'],
            'awaiting_age' => [$this, 'handleAwaitingAge'],
            'awaiting_activity' => [$this, 'handleAwaitingActivity'],
            'awaiting_height' => [$this, 'handleAwaitingHeight'],
            'awaiting_weight' => [$this, 'handleAwaitingWeight'],
        ];
    
        if (isset($handlers[$currentState])) {
            $handlers[$currentState]($customer, $chatId, $messageText);
        } else {
            Log::info("Webhook: Unhandled state='{$currentState}' for User={$customer->tg_id}");
        }
    }
    protected function handleAwaitingGoal(Customer $customer, int $chatId, string $messageText): void
    {
        $validGoals = ['Сбросить вес', 'Удержать вес', 'Нарастить мышцы'];
        if (in_array($messageText, $validGoals)) {
            if ($this->saveCustomerInfo($customer, ['goal' => $messageText], $chatId, 'saving goal', true)) {
                $customer->update(['state' => 'awaiting_gender']);
                $this->askGender($chatId);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, выберите цель:');
        }
    }
    protected function handleAwaitingGender(Customer $customer, int $chatId, string $messageText): void
    {
        $validGenders = ['Мужской', 'Женский'];
        if (in_array($messageText, $validGenders)) {
            if ($this->saveCustomerInfo($customer, ['gender' => $messageText], $chatId, 'saving gender')) {
                $customer->update(['state' => 'awaiting_age']);
                $this->askAge($chatId);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, выберите пол:');
        }
    }
    protected function handleAwaitingAge(Customer $customer, int $chatId, string $messageText): void
    {
        $ageInput = filter_var($messageText, FILTER_SANITIZE_NUMBER_INT);

        if (is_numeric($ageInput) && $ageInput >= 7 && $ageInput <= 100) {
            $age = (int)$ageInput;
            $birthYear = Carbon::now()->year - $age;

            if ($this->saveCustomerInfo($customer, ['birth_year' => $birthYear], $chatId, 'saving birth year')) {
                $customer->update(['state' => 'awaiting_activity']);
                $this->askActivityLevel($chatId);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, введите ваш возраст цифрами (например, 25). Допустимый возраст от 12 до 100 лет.');
        }
    }
    protected function handleAwaitingActivity(Customer $customer, int $chatId, string $messageText): void
    {
        $validActivities = ['Высокая активность', 'Средняя активность', 'Минимум активности', 'Сидячий образ жизни'];
        if (in_array($messageText, $validActivities)) {
            if ($this->saveCustomerInfo($customer, ['activity_level' => $messageText], $chatId, 'saving activity')) {
                $customer->update(['state' => 'awaiting_height']);
                $this->askHeight($chatId);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, выберите уровень активности, используя кнопки.');
        }
    }
    protected function handleAwaitingHeight(Customer $customer, int $chatId, string $messageText): void
    {
        $heightInput = filter_var($messageText, FILTER_SANITIZE_NUMBER_INT);
        if (is_numeric($heightInput) && $heightInput >= 50 && $heightInput <= 280) {
            if ($this->saveCustomerInfo($customer, ['height' => (int)$heightInput], $chatId, 'saving height')) {
                $customer->update(['state' => 'awaiting_weight']);
                $this->askWeight($chatId);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, введите ваш рост в сантиметрах (число от 50 до 280).');
        }
    }
    protected function handleAwaitingWeight(Customer $customer, int $chatId, string $messageText): void
    {
        $weightInput = str_replace(',', '.', $messageText);
        $weightInput = filter_var($weightInput, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        if (is_numeric($weightInput) && $weightInput > 20 && $weightInput < 500) {
            if ($this->saveCustomerInfo($customer, ['weight' => (float)$weightInput], $chatId, 'saving weight')) {
                $customer->update(['state' => null]);
                Log::info("Onboarding completed for customer {$customer->id}. Session state cleared.");
                $this->sendFinalSummary($chatId, $customer);
                $this->sendCalorieNorm($chatId, $customer);
            }
        } else {
            $this->sendMessage($chatId, 'Пожалуйста, введите ваш вес в килограммах (число от 20 до 500, можно с точкой или запятой).');
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
                    Log::debug("saveCustomerInfo: Attempting to update CustomerInfo ID {$info->id} with data:", $data);
                    $updateResult = $info->update($data); 
                    Log::debug("saveCustomerInfo: Update executed for CustomerInfo ID {$info->id}. Result: " . ($updateResult ? 'true' : 'false'));
    
                    if (!$updateResult) { 
                         Log::error("saveCustomerInfo: info->update() returned false.", ['data' => $data]);
                         throw new Exception("Failed to update CustomerInfo."); 
                    }
    
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
    protected function askGender(int $chatId): void
    {
         $keyboard = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->row(['Мужской', 'Женский']);
         $this->sendMessage($chatId, 'Отлично! Теперь выберите свой пол:', $keyboard);
    }
    protected function askAge(int $chatId): void
    {
        $keyboard = Keyboard::make()->setRemoveKeyboard(true);

        $this->sendMessage(
            $chatId,
            'Пожалуйста, введите ваш возраст (полных лет):',
            $keyboard 
        );            
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
                "✅ <b>Ваша текущая цель:</b> %s\n\n" .
                "📊 <b>Дневная норма:</b> ~%d ккал\n\n" .
                "🍽 <b>Б|Ж|У:</b>\n" .
                " 🍗 <b>Белки:</b> ~%dг\n" .
                " 🥑 <b>Жиры:</b> ~%dг\n" .
                " 🍞 <b>Углеводы:</b> ~%dг",
                htmlspecialchars($info->goal ?? 'Не указана'),
                $result['calories'],
                $result['protein'],
                $result['fat'],
                $result['carbs'],
            );

            $this->sendMessage($chatId, $messageText, null, 'HTML');
            Log::info("sendCalorieNorm: Message supposedly sent.");
        } else {
            Log::error("sendCalorieNorm: Calculation returned null or invalid array. Sending fallback message.");
            $this->sendMessage($chatId, "Не удалось рассчитать вашу норму калорий и БЖУ. Вы можете попробовать команду /mynorm позже.");
        }
        Log::info("sendCalorieNorm: Method finished for customer {$customer->id}");
    }
    protected function handleCallbackQuery(int $chatId, Customer $customer, string $data, $callbackQuery): void
    {
        $messageId = $callbackQuery->getMessage()->getMessageId();
    
        switch ($data) {
            case 'profile':
                $info = $customer->customerInfo()->latest()->first();
                if ($info) {
                    $ageText = $info->birth_year ? (Carbon::now()->year - $info->birth_year) . ' лет' : 'Не указан';
                    $text = "📋 <b>Ваш профиль:</b>\n\n" .
                            "🎯 <b>Цель:</b> " . ($info->goal ?? 'Не указана') . "\n" .
                            "👤 <b>Пол:</b> " . ($info->gender ?? 'Не указан') . "\n" .
                            "📅 <b>Возраст:</b> " . $ageText . "\n" .
                            "🏃 <b>Активность:</b> " . ($info->activity_level ?? 'Не указана') . "\n" .
                            "📏 <b>Рост:</b> " . ($info->height ? $info->height . ' см' : 'Не указан') . "\n" .
                            "⚖️ <b>Вес:</b> " . ($info->weight ? $info->weight . ' кг' : 'Не указан');
                } else {
                    $text = "Профиль не найден. Завершите настройку через /start.";
                }
                break;
            case 'norm':
                $info = $customer->customerInfo()->latest()->first();
                if ($info) {
                    $calculator = new CalorieCalculatorService();
                    $result = $calculator->calculateNorm($info);
                    if ($result) {
                        $text = sprintf(
                            "✅ <b>Ваша текущая цель:</b> %s\n\n" .
                            "📊 <b>Дневная норма:</b> ~%d ккал\n\n" .
                            "🍽 <b>Б|Ж|У:</b>\n" .
                            " 🍗 <b>Белки:</b> ~%dг\n" .
                            " 🥑 <b>Жиры:</b> ~%dг\n" .
                            " 🍞 <b>Углеводы:</b> ~%dг",
                            htmlspecialchars($info->goal ?? 'Не указана'),
                            $result['calories'],
                            $result['protein'],
                            $result['fat'],
                            $result['carbs'],
                        );
                    } else {
                        $text = "Не удалось рассчитать норму. Проверьте данные через /myprofile.";
                    }
                } else {
                    $text = "Данные профиля не найдены. Используйте /start.";
                }
                break;
            case 'start':
                $customer->update(['state' => 'awaiting_goal']);
                $text = "Давайте начнём заново. Выберите вашу цель:";
                break;
            default:
                $text = "Неизвестное действие. Попробуйте снова.";
                break;
        }
    
        try {
            Telegram::editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => Keyboard::make()->inline()
                    ->row([
                        Keyboard::inlineButton(['text' => 'Мой профиль', 'callback_data' => 'profile']),
                        Keyboard::inlineButton(['text' => 'Моя норма', 'callback_data' => 'norm']),
                    ])
                    ->row([
                        Keyboard::inlineButton(['text' => 'Начать заново', 'callback_data' => 'start']),
                    ]),
            ]);
            Log::info("Webhook: Updated message {$messageId} in chat {$chatId} with data '{$data}'");
        } catch (TelegramSDKException $e) {
            Log::error("Webhook: Failed to edit message {$messageId} in chat {$chatId}", ['error' => $e->getMessage()]);
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

        $ageText = $info->birth_year ? (Carbon::now()->year - $info->birth_year) . ' лет' : 'Не указан';

        $finalMessage = "Спасибо! 👍 Ваш профиль успешно настроен:\n\n" .
                        "<b>🎯 Цель:</b> " . ($info->goal ?? 'Не указана') . "\n" .
                        "<b>👤 Пол:</b> " . ($info->gender ?? 'Не указан') . "\n" .
                        "<b>🎂 Возраст:</b> " . $ageText . "\n" .
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