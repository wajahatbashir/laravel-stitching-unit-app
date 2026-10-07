<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppNotification extends Notification
{
    public function __construct(
        private string $title,
        private string $body = '',
        private ?string $url = null,
        private array $channels = ['database'],
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function toArray($notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    public function toMail($notifiable): MailMessage
    {
        $m = (new MailMessage)->subject('['.biz('business_name', 'Lumiere').'] '.$this->title)->line($this->body);
        if ($this->url) {
            $m->action('Open', url($this->url));
        }

        return $m;
    }
}
