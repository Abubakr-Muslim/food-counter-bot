<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;

class ReplyKeyboardCommand extends Command
{
    protected string $name = 'reply_keyboard';
    protected string $description = 'Демонстрация клавиатуры ответов';

    public function handle()
    {
        $reply_markup = Keyboard::make()
            ->setResizeKeyboard(true)
            ->setOneTimeKeyboard(true)
            ->row([
                Keyboard::button('1'),
                Keyboard::button('2'),
                Keyboard::button('3'),
            ])
            ->row([
                Keyboard::button('4'),
                Keyboard::button('5'),
                Keyboard::button('6'),
            ]);

        $this->replyWithMessage([
            'text' => 'Hello',
            'reply_markup' => $reply_markup        
            ]);
    }
}