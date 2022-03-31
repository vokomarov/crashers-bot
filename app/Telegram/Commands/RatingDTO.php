<?php

namespace App\Telegram\Commands;

use App\Models\User;

class RatingDTO
{
    public User $user;

    public int $position = 0;

    public int $times = 0;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->times = $user->pidar_history_logs_count ?? 0;
    }
}
