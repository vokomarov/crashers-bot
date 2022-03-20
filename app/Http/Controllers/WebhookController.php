<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __invoke(string $token, Request $request)
    {


        Log::info('Incoming webhook', [
            'body' => $request->toArray(),
        ]);


    }
}
