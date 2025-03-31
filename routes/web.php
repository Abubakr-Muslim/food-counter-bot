<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken; // Добавляем use для класса



Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', [TelegramBotController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class]);