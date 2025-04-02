<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Telegram Webhook Received: ', $request->all());
        
        if($request->has('message') && is_array($request->input('message')) 
        && array_key_exists('text', $request->input('message'))) {
            
            $text = $request->input('message.text');
            Log::info('Текст сообщения: ', ['text' => $text]);
        }

        if ($request->has('message.photo')) {
            Log::info('Получена фотография от пользователя');
        }

        return response('OK', 200);
    }
}
