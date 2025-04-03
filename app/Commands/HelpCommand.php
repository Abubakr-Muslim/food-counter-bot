<?php

namespace App\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class HelpCommand extends Command
{
    protected string $name = 'help';
    protected string $description = 'Показать список доступных команд';

    public function handle()
    {
        $commands = Telegram::getCommands();
        $response = 'Список доступных команд:' . PHP_EOL;

        foreach($commands as $name=>$command) {
            $response .= "/{$name} - {$command->getDescription()}" . PHP_EOL;
        }

        $this->replyWithMessage(['text' => $response]);
    }
}

