<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Keyboard\Keyboard;

class WebHookController extends Controller
{
    protected BotsManager $botsManager;

    public function __construct(BotsManager $botsManager)
    {
        $this->botsManager = $botsManager;
    }

    public function __invoke(Request $request)
    {
        $this->botsManager->bot()->commandsHandler(true);

        $updates = Telegram::getWebhookUpdates();

        Log::info("Состояние сессии в начале обработки:", session()->all());

        if ($updates->has('message')) {
            $message = $updates->getMessage();
            $chat = $message->getChat(); 

            if ($chat !== null) {
                $chatId = $chat->getId();
                $userId = $message->getFrom()->getId();
                $messageText = $message->getText();

                Log::info('Webhook Update Received (Message):', ['update' => $updates->toArray()]);

                if (!session()->has("user_{$userId}_goal")) {
                    if (in_array($messageText, ['Сбросить вес', 'Удержать вес', 'Нарастить мышцы'])) {
                        session()->put("user_{$userId}_goal", $messageText);

                        Log::info("User {$userId} выбрал цель: " . $messageText);
                        Log::info("Состояние сессии после записи цели:", session()->all());

                        $genderKeyboard = Keyboard::make()
                            ->setResizeKeyboard(true)
                            ->setOneTimeKeyboard(true)
                            ->row([
                                'Мужской',
                                'Женский'
                            ]);

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Выберите свой пол:',
                            'reply_markup' => $genderKeyboard,
                        ]);
                    }
                    return response(null, 200);
                }

                Log::info("Состояние сессии перед проверкой пола:", session()->all());

                if (!session()->has("user_{$userId}_gender")) {

                    Log::info("Message Text после выбора пола: " . $messageText); 

                    if (in_array($messageText, ['Мужской', 'Женский'])) {
                        session()->put("user_{$userId}_gender", $messageText);

                        Log::info("User {$userId} выбрал пол: " . $messageText);
                        Log::info("Состояние сессии после выбора пола:", session()->all());

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите свою дату рождения в формате ГГГГ-ММ-ДД (например, 1999-01-15):',
                            'reply_markup' => ['remove_keyboard' => true]
                        ]);
                    }
                    return response(null, 200);
                }

                if (!session()->has("user_{$userId}_birthdate")) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $messageText)) {
                        session()->put("user_{$userId}_birthdate", $messageText);

                        Log::info("User {$userId} ввел дату рождения: " . $messageText);

                        $activityKeyboard = Keyboard::make()
                            ->setResizeKeyboard(true)
                            ->setOneTimeKeyboard(true)
                            ->row([
                                'Высокая активность',
                                'Средняя активность',
                                'Минимум активности',
                                'Сидячий образ жизни'
                            ]);

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Выберите свой уровень активности',
                            'reply_markup' => $activityKeyboard
                        ]);
                    } elseif ($messageText !== '/start') {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите дату рождения в формате ГГГГ-ММ-ДД.',
                        ]);
                    }
                    return response(null, 200);
                }

                if (!session()->has("user_{$userId}_activity")) {
                    if (in_array($messageText, ['Высокая активность', 'Средняя активность', 'Минимум активности', 'Сидячий образ жизни'])) {
                        session()->put("user_{$userId}_activity", $messageText);

                        Log::info("User {$userId} выбрал уровень активности: " . $messageText);

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите свой рост:',
                            'reply_markup' => ['remove_keyboard', true]
                        ]);
                    } elseif ($messageText !== '/start') {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, выберите уровень активности из предложенных вариантов.',
                        ]);
                    }
                    return response(null, 200);
                }

                if (!session()->has("user_{$userId}_height")) {
                    if (is_numeric($messageText) && $messageText > 0) {
                        session()->put("user_{$userId}_height", $messageText);

                        Log::info("User {$userId} ввел рост: " . $messageText);

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите свой вес:',
                            'reply_markup' => ['remove_keyboard', true],
                        ]);
                    } else if ($messageText !== '/start') {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите свой рост:',
                        ]);
                    }
                    return response(null, 200);
                }

                if (!session()->has("user_{$userId}_weight")) {
                    if (is_numeric($messageText) && $messageText > 0) {
                        session()->put("user_{$userId}_weight", $messageText);

                        Log::info("User {$userId} ввел вес: " . $messageText);

                        $goal = session()->get("user_{$userId}_goal");
                        $gender = session()->get("user_{$userId}_gender");
                        $birthdate = session()->get("user_{$userId}_birthdate");
                        $activity = session()->get("user_{$userId}_activity");
                        $height = session()->get("user_{$userId}_height");
                        $weight = session()->get("user_{$userId}_weight");

                        $finalMessage = "Спасибо за предоставленную информацию!\n\n" .
                                        "Ваша цель: {$goal}\n" .
                                        "Пол: {$gender}\n" .
                                        "Дата рождения: {$birthdate}\n" .
                                        "Уровень активности: {$activity}\n" .
                                        "Рост: {$height} см\n" .
                                        "Вес: {$weight} кг";

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => $finalMessage,
                        ]);

                        session()->forget("user_{$userId}_goal");
                        session()->forget("user_{$userId}_gender");
                        session()->forget("user_{$userId}_birthdate");
                        session()->forget("user_{$userId}_activity");
                        session()->forget("user_{$userId}_height");
                        session()->forget("user_{$userId}_weight");
                    } else if ($messageText !== '/start') {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'Пожалуйста, введите свой вес:',
                        ]);
                    }
                    return response(null, 200);
                }
            } else {
                Log::info('Webhook Update Received (Message without chat):', ['update' => $updates->toArray()]);
            }
        } else {
            Log::info('Webhook Update Received (Non-Message):', ['update' => $updates->toArray()]);
        }

        Log::info("Состояние сессии пользователя (конец обработки):", session()->all());
        Log::info("Состояние сессии пользователя (самый конец invoke):", session()->all());
        return response(null, 200);
    }
}