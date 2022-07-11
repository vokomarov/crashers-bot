<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\PidarGift;
use App\Models\PidarGiftItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Request;

class GiftService
{
    public function __construct(protected readonly TelegramClient $telegram)
    {
        Carbon::setLocale('uk');
        $this->telegram->getClient();
    }

    public function process(): void
    {
        $gifts = $this->getReadyGifts();

        foreach ($gifts as $gift) {
            try {
                $this->processGift($gift);
            } catch (\Throwable $exception) {
                Log::warning('Unable to process gift', [
                    'id' => $gift->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    protected function shouldUnblockNow(PidarGiftItem $item): bool
    {
        if ($item->is_unblocked) {
            return false;
        }

        if (Carbon::now()->lessThan(Carbon::make($item->unblocking_at))) {
            return false;
        }

        return true;
    }

    protected function shouldNotifyNow(PidarGiftItem $item): bool
    {
        if ($item->is_notified) {
            return false;
        }

        if (Carbon::now()->lessThan(Carbon::make($item->notification_at))) {
            return false;
        }

        return true;
    }

    protected function getItemsToProcess(Collection $items): array
    {
        $toUnblock = null;
        $toNotify = null;

        foreach ($items as $item) {
            if ($this->shouldUnblockNow($item)) {
                $item->is_unblocked = true;
                $item->save();
                $toUnblock = $item;
                break;
            }

            if ($this->shouldNotifyNow($item)) {
                $item->is_notified = true;
                $item->save();
                $toNotify = $item;
                break;
            }
        }

        return [$toUnblock, $toNotify];
    }

    public function checkGift(User $user, Chat $chat): bool
    {
        $gift = $this->findReadyGiftForUserAndChat($user, $chat);

        if (! $gift instanceof PidarGift) {
            return false;
        }

        $items = $this->getItems($gift);
        $isDone = $this->isAllItemsDone($items);
        $latest = $items->filter(fn (PidarGiftItem $item) => $item->is_unblocked)->last();

        Request::sendMessage([
            'chat_id' => $gift->chat->tg_id,
            'text' => $this->renderLatest($gift, $latest, $items, $isDone),
            'parse_mode' => 'HTML',
        ]);

        return true;
    }

    /**
     * @param \App\Models\PidarGift $gift
     * @return void
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function processGift(PidarGift $gift): void
    {
        $items = $this->getItems($gift);

        $isDone = $this->isAllItemsDone($items);

        if ($isDone) {
            $gift->is_processed = true;
            $gift->save();
            return;
        }

        [$toUnblock, $toNotify] = $this->getItemsToProcess($items);

        if ($toUnblock instanceof PidarGiftItem) {
            $message = $this->renderUnblock($gift, $toUnblock, $items);
        } else if ($toNotify instanceof PidarGiftItem) {
            $message = $this->renderNotify($gift, $toNotify, $items, $isDone);
        } else {
            return;
        }

        Request::sendMessage([
            'chat_id' => $gift->chat->tg_id,
            'text' => $message,
            'parse_mode' => 'HTML',
        ]);
    }

    protected function renderLatest(PidarGift $gift, PidarGiftItem $latest, Collection $items, bool $done = false): string
    {
        $message = "@{$gift->pidarUser->username} Вітання від твого ♂slave♂!\n";
        $message .= "Деякі ♂fellows of this gym♂ 💪 підготували для тебе подарунок 🔥\n";
        $message .= $done ?
            "Бажаємо міцного ♂dick♂ та здоровля.\n\n" :
            "Але май терпіння, ♂dungeon master♂. На все свій ♂dick♂.\n\n";
        $message .= "Вітаю з відкриттям - {$latest->title} 🎉🎉🎉 \n\n";
        $message .= "Ось що на тебе чекає:\n";
        $message .= $this->renderList($items);
        $message .= $done ?
            "\nВсе дон-дон." :
            "\nТвій ♂slave♂ тебе повідомить, коли все дон-дон.\nЯкщо жме - /pidargift@CrashersBot ";

        return $message;
    }

    protected function renderUnblock(PidarGift $gift, PidarGiftItem $unblock, Collection $items): string
    {
        $message = "@{$gift->pidarUser->username} Вітання від твого ♂slave♂!\n";
        $message .= "Деякі ♂fellows of this gym♂ 💪 підготували для тебе подарунок 🔥\n";
        $message .= "Але май терпіння, ♂dungeon master♂. На все свій ♂dick♂.\n\n";
        $message .= "Вітаю з відкриттям - {$unblock->title} 🎉🎉🎉 \n";

        if (! $unblock->is_notified) {
            $when = Carbon::make($unblock->notification_at)->diffForHumans();
            $message .= "Очікуй доставку 🚚 {$when}\n";
        }

        $message .= "\nОсь що на тебе чекає:\n";
        $message .= $this->renderList($items);
        $message .= "\nТвій ♂slave♂ тебе повідомить, коли все дон-дон.\nЯкщо жме - /pidargift@CrashersBot ";

        return $message;
    }

    protected function renderNotify(PidarGift $gift, PidarGiftItem $notify, Collection $items, bool $done = false): string
    {
        $message = "@{$gift->pidarUser->username} Вітання від твого ♂slave♂!\n";
        $message .= "Деякі ♂fellows of this gym♂ 💪 підготували для тебе подарунок 🔥\n";

        $message .= $done ?
            "Бажаємо міцного ♂dick♂ та здоровля.\n\n" :
            "Але май терпіння, ♂dungeon master♂. На все свій ♂dick♂.\n\n";

        $message .= "Вітаю з відкриттям - {$notify->title} 🎉🎉🎉 \nПеревіряй <s>за щокою</s> свій аккаунт 🌚 \n\n";
        $message .= "Ось що на тебе чекає:\n";
        $message .= $this->renderList($items);

        $message .= $done ?
            "\nВсе дон-дон." :
            "\nТвій ♂slave♂ тебе повідомить, коли все дон-дон.\nЯкщо жме - /pidargift@CrashersBot ";

        return $message;
    }

    protected function renderList(Collection $items): string
    {
        $list = '';

        foreach ($items as $item) {
            /** @var \App\Models\PidarGiftItem $item */

            if ($item->is_unblocked) {
                $when = '';

                if (!$item->is_notified) {
                    $when = Carbon::make($item->notification_at)->diffForHumans();
                    $when = "(🚚 {$when})";
                }

                $list .= "- {$item->title} 🎉 {$when} \n";
                continue;
            }

            $when = Carbon::make($item->unblocking_at)->diffForHumans();

            $list .= "- ???? ({$when})\n";
        }

        return $list;
    }

    protected function isAllItemsDone(Collection $items): bool
    {
        return $items->filter(fn (PidarGiftItem $item) => !$item->is_notified)->count() === 0;
    }

    /**
     * @return \App\Models\PidarGift[]|\Illuminate\Database\Eloquent\Collection
     */
    protected function getReadyGifts()
    {
        return PidarGift::whereHas('items')
                        ->with('pidarUser')
                        ->with('chat')
                        ->where('is_processed', false)
                        ->get();
    }

    /**
     * @return \App\Models\PidarGift|null
     */
    protected function findReadyGiftForUserAndChat(User $user, Chat $chat)
    {
        return PidarGift::whereHas('items')
                        ->with('pidarUser')
                        ->with('chat')
                        ->where('is_processed', false)
                        ->where('chat_id', $chat->id)
                        ->where('pidar_user_id', $user->id)
                        ->first();
    }

    /**
     * @param \App\Models\PidarGift $gift
     * @return \App\Models\PidarGiftItem[]|\Illuminate\Database\Eloquent\Collection
     */
    protected function getItems(PidarGift $gift)
    {
        return $gift->items()->orderBy('unblocking_at')->get();
    }
}
