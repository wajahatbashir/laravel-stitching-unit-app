<?php

use App\Support\TimezoneShift;
use Illuminate\Database\Migrations\Migration;

/**
 * The app used to run in UTC while the server (and the audit log, via the database clock) ran in Pakistan time. From now on the app
 * runs in Pakistan Standard Time (Asia/Karachi, UTC+5, no daylight saving): existing date+time values stored in UTC are moved +5 hours
 * so every record shows the same local time. Nothing happens on an empty / new database.
 */
return new class extends Migration
{
    public function up(): void
    {
        TimezoneShift::apply($this->hours());
    }

    public function down(): void
    {
        TimezoneShift::apply(-$this->hours());
    }

    private function hours(): int
    {
        return (int) round(now('Asia/Karachi')->utcOffset() / 60);   // 5 — fixed, Pakistan has no daylight saving
    }
};
