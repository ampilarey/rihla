<?php

namespace App\Services\Stays;

use App\Exceptions\DeskRefusal;
use App\Models\Stay;
use App\Models\StayMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The conversation about a stay — §16.11.
 *
 * Three sides — the guest, the host and Rihla — and one rule each way: a
 * message is plain text of at most two thousand characters, and it is read
 * once somebody on another side opens the conversation. Mail is not
 * configured on this host, so the unread count is the ping.
 */
class StayMessages
{
    /** @throws DeskRefusal */
    public function post(Stay $stay, string $sender, string $body, ?User $by = null): StayMessage
    {
        if (! in_array($sender, StayMessage::SENDERS, true)) {
            throw new \InvalidArgumentException("Unknown sender [{$sender}].");
        }

        $body = trim(strip_tags($body));

        if ($body === '') {
            throw new DeskRefusal(__('messages.Write a message first.'));
        }

        if (mb_strlen($body) > StayMessage::MAX_LENGTH) {
            throw new DeskRefusal(__('messages.Keep a message under :count characters.', ['count' => StayMessage::MAX_LENGTH]));
        }

        $message = new StayMessage;
        $message->forceFill([
            'stay_id' => $stay->getKey(),
            'sender' => $sender,
            'sender_user_id' => $by?->getKey(),
            'body' => $body,
            'sent_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * The conversation, oldest first.
     *
     * @return Collection<int, StayMessage>
     */
    public function thread(Stay $stay): Collection
    {
        return StayMessage::query()
            ->where('stay_id', $stay->getKey())
            ->with('stay.property')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }

    /** `$side` has opened the conversation: everything the others sent is read. */
    public function markRead(Stay $stay, string $side): int
    {
        return StayMessage::query()
            ->where('stay_id', $stay->getKey())
            ->unreadBy($side)
            ->update(['read_at' => now()]);
    }

    public function unread(Stay $stay, string $side): int
    {
        return StayMessage::query()->where('stay_id', $stay->getKey())->unreadBy($side)->count();
    }
}
