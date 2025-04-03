<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;

class InlineKeyboardCommand extends Command
{
    protected string $name = 'inline_keyboard';
    protected string $description = 'Встроенная клавиатура';

    public function handle()
    {
        $keyboard = Keyboard::make()
        ->inline()
        ->row([
            Keyboard::inlineButton(['text' => 'Открыть сайт', 'url' => 'https://www.google.com']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => 'Отправить данные', 'callback_data' => 'button_pressed']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => 'Поиск в текущем чате', 'switch_inline_query_current_chat' => 'поиск...']),
        ]);

        $this->replyWithMessage([
            'text' => 'Пример встроенной клавиатуры',
            'reply_markup' => $keyboard
        ]);
    }
}