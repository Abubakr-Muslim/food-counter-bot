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

            $photos = $request->input('message.photo');
            $largestPhoto = end($photos);
            $fileId = $largestPhoto['file_id'];
            Log::info('File ID фотографии: ', ['file_id' => $fileId]);

            try {
                $fileInfo = Telegram::getFile(['file_id' => $fileId]);
                Log::info('Информация о файле от Telegram:', $fileInfo->getRawResponse());

                $filePath = $fileInfo['file_path'];

                $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                $fileName = 'photo_' . time() . '.' . $extension;
                $destinationPath = public_path('photos/' . $fileName);

                Log::info('Путь сохранения (public):', ['path' => $destinationPath]);

                $botToken = config('telegram.bots.mybot.token');
                $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
                Log::info('URL для скачивания:', ['url' => $downloadUrl]);

                $fileContent = @file_get_contents($downloadUrl);

                if ($fileContent === false) {
                    Log::error('Ошибка при скачивании файла по URL:', ['url' => $downloadUrl]);
                } else {
                    // Сохраняем файл
                    if (file_put_contents($destinationPath, $fileContent)) {
                        Log::info('Фотография успешно сохранена (ручной URL) в: ', ['path' => $destinationPath]);
                    } else {
                        Log::error('Ошибка при сохранении файла на сервере:', ['path' => $destinationPath]);
                    }
                }

            } catch (\Telegram\Bot\Exceptions\TelegramSDKException $e) {
                Log::error('Ошибка при получении информации о файле:', ['message' => $e->getMessage()]);
            }
        }

        return response('OK', 200);
    }
}
