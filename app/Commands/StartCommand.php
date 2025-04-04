<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Начало работы с ботом';

    public function handle()
    {
        $welcomeMessage = 'Привет! Я бот-нейросеть, которая поможет тебе анализировать прием пищи и считать калории. Просто отправь мне фотографию блюда, и я проанализирую его количество калорий и бжу' . PHP_EOL . PHP_EOL . 'Пожалуйста, прежде чем начать работу, ответь на несколько вопросов для настройки вашего профиля.';
        $welcomeMessage .= PHP_EOL . PHP_EOL . 'Используй команду /help, чтобы увидеть список доступных команд.';
        $this->replyWithMessage(['text' => $welcomeMessage]);

        $goalKeyboard = Keyboard::make()
		    ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->row([
                Keyboard::button('Сбросить вес'),
                Keyboard::button('Удержать вес'),
                Keyboard::button('Нарастить мышцы'),
            ]);

        $this->replyWithMessage([
            'text' => 'Выберите свою цель для ведения подcчета калорий',
            'reply_markup' => $goalKeyboard
        ]);
    }
}