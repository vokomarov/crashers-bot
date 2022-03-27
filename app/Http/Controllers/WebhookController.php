<?php

namespace App\Http\Controllers;

use App\Telegram\Exceptions\InvalidWebhookTokenException;
use App\Telegram\TelegramClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Exception\TelegramException;

class WebhookController extends Controller
{
    public function __invoke(string $token, TelegramClient $telegram)
    {
        try {
            $telegram->validateWebhookToken($token);
            $telegram->handle();
        } catch (InvalidWebhookTokenException $exception) {
            Log::debug('Invalid token exception', [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => "{$exception->getFile()}:{$exception->getLine()}",
            ]);

            return response()->setStatusCode(Response::HTTP_NOT_FOUND);
        } catch (TelegramException $exception) {
            Log::warning('Telegram webhook exception', [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => "{$exception->getFile()}:{$exception->getLine()}",
            ]);
        }

        return response()->json();
    }
}
