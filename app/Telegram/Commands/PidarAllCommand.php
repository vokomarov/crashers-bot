<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Telegram\BaseCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Longman\TelegramBot\Entities\ChatMember\ChatMember;
use Longman\TelegramBot\Entities\ChatMember\ChatMemberLeft;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class PidarAllCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'pidarall';

    /**
     * @var string
     */
    protected $description = 'Statistics of a pidar game by all time';

    /**
     * @var string
     */
    protected $usage = '/pidarall';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     */
    public function handle(): ServerResponse
    {
        $luckiest = $this->getLuckiest();

        if ($luckiest->count() === 0) {
            return $this->sendText($this->lang('telegram.pidar-all-no-records'));
        }

        $this->refreshMembers($luckiest);

        return $this->sendText($this->renderMessage($luckiest));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    protected function getLuckiest(): Collection
    {
        return $this->getLuckiestQuery()->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getLuckiestQuery(): Builder
    {
        return User::withCount(['pidarHistoryLogs' => function (Builder $query) {
            $query->where('chat_id', $this->chat->id);
        }])->whereHas('chats', function (Builder $query) {
            $query->where('chat_id', $this->chat->id);
        })->orderByDesc('pidar_history_logs_count');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<\App\Models\User> $luckiest
     * @return string
     */
    protected function renderMessage(Collection $luckiest): string
    {
        $message = $this->getMessageHeader();

        $list = $this->buildList($luckiest);

        foreach ($list as $rating) {
            if ($rating->position === 1 && $rating->times === 0) {
                return $this->lang('telegram.pidar-all-no-records');
            }

            $message .= trans('telegram.pidar-all-line', [
                'emoji' => trans_choice('telegram.pidar-all-line-emoji', $rating->position),
                'position' => $rating->position,
                'username' => $rating->user->username,
                'times' => trans_choice('telegram.pidar-all-times', $rating->times),
            ]);
        }

        return $message;
    }

    /**
     * @return string
     */
    protected function getMessageHeader(): string
    {
        return $this->lang('telegram.pidar-all-header');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection $users
     * @return array<\App\Telegram\Commands\RatingDTO>
     */
    protected function buildList(Collection $users): array
    {
        $previous = null;
        $position = 0;

        $list = [];

        // assuming a list of users come already ordered by amount of wins DESC
        foreach ($users as $user) {
            $rating = new RatingDTO($user);

            // if previous user has different amount of wins then he should have different position
            if ($previous !== $rating->times) {
                $position++;
            }

            $rating->position = $position;

            $list[] = $rating;

            $previous = $rating->times;
        }

        return $list;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<\App\Models\User> $members
     * @return void
     */
    protected function refreshMembers(Collection $members): void
    {
        foreach ($members as $member) {
            $response = Request::getChatMember([
                'chat_id' => $this->chat->tg_id,
                'user_id' => $member->tg_id,
            ]);

            if (! $response->isOk()) {
                continue;
            }

            $result = $response->getResult();

            if (! $result instanceof ChatMember) {
                continue;
            }

            if ($result instanceof ChatMemberLeft) {
                // TODO. Handle the case when user left chat
            }

            $member->update([
                'username' => $result->getUser()->getUsername(),
                'first_name' => $result->getUser()->getFirstName(),
                'last_name' => $result->getUser()->getLastName(),
            ]);
        }
    }
}
