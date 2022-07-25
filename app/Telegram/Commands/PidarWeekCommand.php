<?php

namespace App\Telegram\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PidarWeekCommand extends PidarAllCommand
{
    /**
     * @var string
     */
    protected $name = 'pidarweek';

    /**
     * @var string
     */
    protected $description = 'Statistics of a pidar game by current week';

    /**
     * @var string
     */
    protected $usage = '/pidarweek';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    protected function getLuckiest(): Collection
    {
        return $this->getLuckiestQuery(Carbon::today()->startOf('week', Carbon::MONDAY))->get();
    }

    protected function getMessageHeader(): string
    {
        return $this->lang('telegram.pidar-week-header');
    }
}
