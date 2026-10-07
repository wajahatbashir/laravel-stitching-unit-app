<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Sends an event to admins on every channel the admin has enabled (Settings → Notifications). */
class Notifier
{
    public static function send(string $event, string $title, string $body = '', ?string $url = null): void
    {
        try {
            $channels = NotificationSetting::where('event', $event)->where('enabled', true)->pluck('channel')->all();
            if (! $channels) {
                return;
            }
            $users = User::role(['Super Admin', 'Admin'])->where('is_active', true)->get();

            foreach ($users as $u) {
                if (in_array('database', $channels)) {
                    $u->notify(new AppNotification($title, $body, $url, ['database']));
                }
                if (in_array('mail', $channels) && $u->email) {
                    $u->notify(new AppNotification($title, $body, $url, ['mail']));
                }
                if (in_array('whatsapp', $channels) && $u->phone) {
                    self::whatsapp($u->phone, "$title\n$body");
                }
            }
        } catch (\Throwable $e) {
            report($e); // notifications must never break the main action
        }
    }

    /** WhatsApp Cloud API; stays a log-only stub until WHATSAPP_TOKEN / WHATSAPP_PHONE_ID are set. */
    private static function whatsapp(string $to, string $text): void
    {
        $token = config('services.whatsapp.token');
        $id = config('services.whatsapp.phone_id');
        if (! $token || ! $id) {
            Log::info("[whatsapp:stub] to=$to $text");
            return;
        }
        Http::withToken($token)->post("https://graph.facebook.com/v20.0/$id/messages", [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/\D/', '', $to),
            'type' => 'text',
            'text' => ['body' => $text],
        ]);
    }
}
