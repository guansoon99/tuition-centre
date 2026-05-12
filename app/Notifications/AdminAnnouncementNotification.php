<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AdminAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $audienceLabel = '',
        public ?string $startsAt = null,
        public ?string $endsAt = null,
        public ?string $announcementId = null,
    ) {
        // Each "send" produces one announcement_id shared across every recipient row,
        // so the admin can group/edit/delete all copies as one logical announcement.
        $this->announcementId ??= (string) \Illuminate\Support\Str::uuid();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'announcement_id' => $this->announcementId,
            'title' => $this->title,
            'body' => $this->body,
            'audience_label' => $this->audienceLabel,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }
}
