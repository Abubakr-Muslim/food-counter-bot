<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebHookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;



Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', WebHookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);