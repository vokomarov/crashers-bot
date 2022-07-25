<?php

namespace App\Telegram\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PidarYearCommand extends PidarAllCommand
{
    /**
     * @var string
     */
    protected $name = 'pidaryear';

    /**
     * @var string
     */
    protected $description = 'Statistics of a pidar game by current year';

    /**
     * @var string
     */
    protected $usage = '/pidaryear';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    protected function getLuckiest(): Collection
    {
        return $this->getLuckiestQuery(Carbon::today()->startOfYear())->get();
    }

    protected function getMessageHeader(): string
    {
        return $this->lang('telegram.pidar-year-header');
    }
}
