<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;

class TelegramBotController extends Controller
{
    public function handle(Request $request)
    {
        $update = Telegram::getWebhookUpdates();
        Log::debug('Telegram Update:', $update->toArray());
        dump($update);
        return 'ok';
    }
}
