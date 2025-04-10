<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoggedMeal;
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

            if ($currentState === null) {
                Log::info("Webhook: State is null. Checking message type for food logging.");

                if ($message instanceof \Telegram\Bot\Objects\Message) {
                    $this->handleFoodLogging($customer, $message, $chatId);
                } else {
                    Log::warning("Webhook: Expected Message object for food logging, but got " . gettype($message) . " for User={$userId}. Update data:", $update->toArray());
                    $this->sendMessage($chatId, "Получен неожиданный тип сообщения. Не могу обработать.");
                }
            } else {
                 $messageText = $message instanceof \Telegram\Bot\Objects\Message ? $message->getText() : null;
                 if ($messageText !== null) {
                     Log::info("Webhook: State is '{$currentState}'. Handling stateful message.");
                     $this->handleUserState($currentState, $customer, $chatId, $messageText);
                 } else {
                     Log::info("Webhook: Ignoring non-text message while in state '{$currentState}'.");
                 }
            }
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
                    if (!$updateResult) { 
                         Log::error("saveCustomerInfo: info->update() returned false.", ['data' => $data]);
                         throw new Exception("Failed to update CustomerInfo."); 
                    }
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
    protected function handleFoodlogging(Customer $customer, \Telegram\Bot\Objects\Message $message, int $chatId)
    {
        $customerInfo = $customer->customerInfo()->latest()->first();
        if (!$customerInfo || !$this->calculatorService->hasRequiredData($customerInfo)) {
            Log::warning("handleFoodLogging: CustomerInfo not found or incomplete for Customer ID {$customer->id}. Cannot log food.");
            $this->sendMessage($chatId, "Пожалуйста, сначала завершите настройку профиля через /start, чтобы начать вести дневник.");
            return;
        };

        if ($message->has('text')) {
            $messageText = $message->getText();
            Log::info("handleFoodLogging: Processing text '{$messageText}' for User={$customer->tg_id}");
            $foodData = $this->analyzeFoodTextPlaceholder($messageText);
        } elseif ($message->has('photo')) {
            $photoSizes = $message->getPhoto();
            $photo = end($photoSizes);
            $fileId = $photo->getFileId();
            Log::info("handleFoodLogging: Processing photo with file_id {$fileId} for User={$customer->tg_id}");
            $this->sendMessage($chatId, "📸 Фото получил!");
            return;
        } else {
             Log::info("handleFoodLogging: Ignoring non-text/non-photo message for User={$customer->tg_id}.");
             return; 
        }

        if ($foodData !== null && isset($foodData['calories'])) {
            if ($this->saveLoggedMeal($customer, $foodData, $chatId)) {
                $dailyTotals = $this->calculateDailyTotals($customer);
                $normData = $this->calculatorService->calculateNorm($customerInfo);
                $this->sendDailyIntakeUpdate($chatId, $foodData, $dailyTotals, $normData);
            }
        } else {
            $this->sendMessage($chatId, "Не удалось распознать '{$message->getText()}'. Попробуйте ввести название проще (например, 'яблоко', 'гречка 100г').");
        }   
    }
    protected function analyzeFoodTextPlaceholder(string $text): ?array // Временно
    {
        $textLower = mb_strtolower(trim($text), 'UTF-8'); 

        $foodDatabase = [
            'яблоко' => ['name'=>'Яблоко (среднее)', 'grams'=>150, 'calories'=>80, 'protein'=>0.4, 'fat'=>0.3, 'carbs'=>20],
            'банан' => ['name'=>'Банан (средний)', 'grams'=>120, 'calories'=>110, 'protein'=>1.3, 'fat'=>0.4, 'carbs'=>27],
            'куриная грудка 100г' => ['name'=>'Куриная грудка (100г)', 'grams'=>100, 'calories'=>165, 'protein'=>31, 'fat'=>3.6, 'carbs'=>0],
            'гречка 100г' => ['name'=>'Гречка отварная (100г)', 'grams'=>100, 'calories'=>110, 'protein'=>4.2, 'fat'=>1.1, 'carbs'=>21.3],
            'овсянка 50г' => ['name'=>'Овсянка сухая (50г)', 'grams'=>50, 'calories'=>190, 'protein'=>6, 'fat'=>3.5, 'carbs'=>32],
            'творог 100г' => ['name'=>'Творог 5% (100г)', 'grams'=>100, 'calories'=>120, 'protein'=>17, 'fat'=>5, 'carbs'=>1.8],
            'яйцо' => ['name'=>'Яйцо куриное (1 шт)', 'grams'=>55, 'calories'=>75, 'protein'=>6.5, 'fat'=>5, 'carbs'=>0.6],
            'хлеб' => ['name'=>'Хлеб ржаной (1 кусок)', 'grams'=>30, 'calories'=>70, 'protein'=>2, 'fat'=>0.5, 'carbs'=>14],
            'кофе' => ['name'=>'Кофе черный', 'grams'=>200, 'calories'=>2, 'protein'=>0, 'fat'=>0, 'carbs'=>0],
            'чай' => ['name'=>'Чай без сахара', 'grams'=>200, 'calories'=>1, 'protein'=>0, 'fat'=>0, 'carbs'=>0],
        ];

        if (isset($foodDatabase[$textLower])) {
            return $foodDatabase[$textLower];
        }

        if (preg_match('/^(.*?)\s+(\d+)\s*(г|гр|грам[м]?)$/ui', $text, $matches)) {
            $foodNameKey = mb_strtolower(trim($matches[1]), 'UTF-8') . ' 100г';
            $grams = (int)$matches[2];
            if (isset($foodDatabase[$foodNameKey]) && $grams > 0) {
                $base = $foodDatabase[$foodNameKey];
                $multiplier = $grams / 100.0;
                $baseName = trim(str_ireplace('(100г)', '', $base['name']));
                return [
                    'name' => sprintf('%s (%dг)', $baseName, $grams),
                    'grams' => $grams,
                    'calories' => (int)round($base['calories'] * $multiplier),
                    'protein' => round($base['protein'] * $multiplier, 1),
                    'fat' => round($base['fat'] * $multiplier, 1),
                    'carbs' => round($base['carbs'] * $multiplier, 1),
                ];
            }
        }
        return null;
    }
    protected function saveLoggedMeal(Customer $customer, array $foodData, int $chatId): bool
    {
        try {
            LoggedMeal::create([
                'customer_id' => $customer->id,
                'food_name' => $foodData['name'] ?? 'Неизвестное блюдо',
                'grams' => $foodData['grams'] ?? null,
                'calories' => $foodData['calories'] ?? 0,
                'protein' => $foodData['protein'] ?? 0,
                'fat' => $foodData['fat'] ?? 0,
                'carbs' => $foodData['carbs'] ?? 0,
                'logged_at' => now() 
            ]);
            Log::info("Logged meal '{$foodData['name']}' for customer {$customer->id}");
            return true;
        } catch (Exception $e) {
            Log::error("Failed to save logged meal for customer {$customer->id}", ['error' => $e->getMessage(), 'food_data' => $foodData]);
            $this->sendMessage($chatId, "Не удалось сохранить запись о приеме пищи '{$foodData['name']}'. Попробуйте позже.");
            return false;
        }
    }
    protected function calculateDailyTotals(Customer $customer): array
    {
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();
  
        $totals = LoggedMeal::where('customer_id', $customer->id)
            ->whereBetween('logged_at', [$todayStart, $todayEnd])
            ->selectRaw('SUM(calories) as total_calories, SUM(protein) as total_protein, SUM(fat) as total_fat, SUM(carbs) as total_carbs')
            ->first(); 
  
        return [
            'total_calories' => (int)($totals->total_calories ?? 0),
            'total_protein' => round((float)($totals->total_protein ?? 0.0), 1), 
            'total_fat' => round((float)($totals->total_fat ?? 0.0), 1),
            'total_carbs' => round((float)($totals->total_carbs ?? 0.0), 1),
        ];
    }
    protected function sendDailyIntakeUpdate(int $chatId, array $lastFood, array $dailyTotals, ?array $normData): void
    {
        $lastFoodText = sprintf(
            "✅ Добавлено: %s (~%d ккал, БЖУ: %.1f/%.1f/%.1f)",
            htmlspecialchars($lastFood['name'] ?? 'Неизвестно'),
            $lastFood['calories'] ?? 0,
            $lastFood['protein'] ?? 0,
            $lastFood['fat'] ?? 0,
            $lastFood['carbs'] ?? 0
        );
  
        $summaryText = "📊 *Итого за сегодня:*\n";
        if ($normData && isset($normData['calories'])) { 
            $summaryText .= sprintf(
                "Калории: *%d* / %d ккал\n".
                "Белки: *%.1f* / %d г\n".
                "Жиры: *%.1f* / %d г\n".
                "Углеводы: *%.1f* / %d г",
                $dailyTotals['total_calories'], $normData['calories'],
                $dailyTotals['total_protein'], $normData['protein'] ?? 0,
                $dailyTotals['total_fat'], $normData['fat'] ?? 0,
                $dailyTotals['total_carbs'], $normData['carbs'] ?? 0
            );
        } else {
             $summaryText .= sprintf(
                "Калории: *%d* ккал\n".
                "Белки: *%.1f* г\n".
                "Жиры: *%.1f* г\n".
                "Углеводы: *%.1f* г",
                $dailyTotals['total_calories'],
                $dailyTotals['total_protein'],
                $dailyTotals['total_fat'],
                $dailyTotals['total_carbs']
            );
            $summaryText .= "\n_(Не удалось получить норму для сравнения)_";
        }
  
        $warningText = "";
         if ($normData && isset($normData['calories']) && $dailyTotals['total_calories'] > $normData['calories']) {
             $exceeded = $dailyTotals['total_calories'] - $normData['calories'];
             $warningText = "\n\n⚠️ *Превышение нормы калорий на {$exceeded} ккал!*";
         } elseif ($normData && isset($normData['calories']) && $dailyTotals['total_calories'] > $normData['calories'] * 0.9) {
             $warningText = "\n\n*Норма калорий почти достигнута.*";
         }
  
        $fullMessage = $lastFoodText . "\n\n" . $summaryText . $warningText;
  
        $this->sendMessage($chatId, $fullMessage, null, 'Markdown');
    }
} 