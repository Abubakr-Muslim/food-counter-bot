<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;

class AboutCommand extends Command
{
    protected string $name = 'about';
    protected string $description = 'Информация о боте';

    public function handle()
    {
        $response = 'Этот бот был создан для анализа фотографий еды и определения их калорийности.';
        $response .= PHP_EOL . 'Разработчик: ';
        $response .= PHP_EOL . 'Связаться: ';
        $response .= PHP_EOL . 'Версия: 0.1';

        $this->replyWithMessage(['text' => $response]);
    }
}