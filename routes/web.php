<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;



Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class]);