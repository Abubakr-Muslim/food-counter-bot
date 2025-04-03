<?php

namespace App\Http\Controllers;

use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\BotsManager;

class WebHookController extends Controller
{
        protected BotsManager $botsManager;

        public function __construct(BotsManager $botsManager)
        {
            $this->botsManager = $botsManager;
        }

        public function __invoke(Request $request) 
        {
            $this->botsManager->bot()->commandsHandler(true);
            return response(null, 200);
        }
}
