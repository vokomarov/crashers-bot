<?php

namespace App\Telegram\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PidarMonthCommand extends PidarAllCommand
{
    /**
     * @var string
     */
    protected $name = 'pidarmonth';

    /**
     * @var string
     */
    protected $description = 'Statistics of a pidar game by current month';

    /**
     * @var string
     */
    protected $usage = '/pidarmonth';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    protected function getLuckiest(): Collection
    {
        return $this->getLuckiestQuery()
                    ->where('created_at', '>=', Carbon::today()->startOfMonth())
                    ->get();
    }

    protected function getMessageHeader(): string
    {
        return $this->lang('telegram.pidar-month-header');
    }
}
