<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use App\Telegram\GiftService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class PidarGiftCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'pidargift';

    /**
     * @var string
     */
    protected $description = 'Check for upcoming gift from fellows';

    /**
     * @var string
     */
    protected $usage = '/pidargift';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function handle(): ServerResponse
    {
        $service = app()->make(GiftService::class);

        if (! $service->checkGift($this->sender, $this->chat)) {
            return $this->sendError();
        }

        return Request::emptyResponse();
    }
}
