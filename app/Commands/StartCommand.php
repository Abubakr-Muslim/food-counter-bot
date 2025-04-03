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
        $keyboard = Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton(['text' => 'Узнать больше о боте', 'callback_data' => 'about']),
            ]);

        $response = 'Привет! Я бот, который поможет тебе анализировать еду и считать калории. Просто отправь мне фотографию блюда, и я проанализирую его количество каллорий и бжу';
        $response .= PHP_EOL . PHP_EOL . 'Используй команду /help, чтобы увидеть список доступных команд.';

        $this->replyWithMessage(['text' => $response, 'reply_markup' => $keyboard]);
    }
}