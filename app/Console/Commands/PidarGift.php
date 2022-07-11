<?php

namespace App\Console\Commands;

use App\Telegram\GiftService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PidarGift extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pidar:gift';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify fellows for an upcoming gifts';

    /**
     * Execute the console command.
     *
     * @param \App\Telegram\GiftService $service
     * @return int
     */
    public function handle(GiftService $service): int
    {
        try {
            $service->process();
        } catch (\Throwable $exception) {
            Log::error('Unable to process gift: ' . $exception->getMessage());
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }
}
