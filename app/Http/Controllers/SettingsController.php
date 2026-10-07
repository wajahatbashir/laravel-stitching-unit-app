<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use App\Models\Setting;
use App\Support\Brand;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const KEYS = ['business_name', 'business_phone', 'business_address', 'order_prefix', 'invoice_prefix', 'ocr_languages'];

    public function edit()
    {
        abort_unless(auth()->user()->can('settings.view'), 403);

        return view('settings.edit', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => biz($k, '')])->all(),
            'lowStock' => (bool) biz('low_stock_alerts'),
            'events' => DatabaseSeeder::EVENTS,
            'matrix' => NotificationSetting::all()->groupBy('event')->map(fn ($g) => $g->pluck('enabled', 'channel')),
        ]);
    }

    public function update(Request $r)
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);
        $r->validate([
            'business_name' => 'required|string|max:100', 'order_prefix' => 'required|max:10', 'invoice_prefix' => 'required|max:10',
            'login_logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:3072',
            'dashboard_logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:3072',
            'favicon' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:3072',
        ]);

        foreach (array_keys(Brand::SLOTS) as $slot) {
            if ($r->hasFile($slot)) {
                Brand::store($slot, $r->file($slot));
            } elseif ($r->boolean("reset_$slot")) {
                Brand::remove($slot);
            }
        }

        foreach (self::KEYS as $k) {
            Setting::put($k, (string) $r->input($k, ''));
        }
        Setting::put('low_stock_alerts', $r->boolean('low_stock_alerts') ? '1' : '0');
        Setting::put('require_2fa_admins', $r->boolean('require_2fa_admins') ? '1' : '0');

        foreach (array_keys(DatabaseSeeder::EVENTS) as $e) {
            foreach (['database', 'mail', 'whatsapp'] as $ch) {
                NotificationSetting::updateOrCreate(['event' => $e, 'channel' => $ch], ['enabled' => (bool) data_get($r->input('notify'), "$e.$ch")]);
            }
        }

        return back()->with('success', __('Settings saved.'));
    }
}
