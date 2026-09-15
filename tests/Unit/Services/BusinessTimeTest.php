<?php

namespace Tests\Unit\Services;

use App\Services\BusinessTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** Lot 1 — minutes ouvrées entre deux instants, selon les horaires scolaires (jours ouvrés lundi-vendredi). */
class BusinessTimeTest extends TestCase
{
    public function test_same_day_inside_hours(): void
    {
        $from = Carbon::parse('2026-09-15 10:00'); // mardi
        $to   = Carbon::parse('2026-09-15 11:30');
        $this->assertSame(90, BusinessTime::minutesBetween($from, $to, '08:00', '17:00'));
    }

    public function test_outside_hours_does_not_count_and_spans_days(): void
    {
        $from = Carbon::parse('2026-09-15 16:30'); // mardi 16h30 → 30 min ce jour
        $to   = Carbon::parse('2026-09-16 08:45'); // mercredi 08h45 → 45 min
        $this->assertSame(75, BusinessTime::minutesBetween($from, $to, '08:00', '17:00'));
    }

    public function test_weekend_is_skipped(): void
    {
        $from = Carbon::parse('2026-09-18 16:00'); // vendredi → 60 min
        $to   = Carbon::parse('2026-09-21 09:00'); // lundi → 60 min
        $this->assertSame(120, BusinessTime::minutesBetween($from, $to, '08:00', '17:00'));
    }

    public function test_night_alert_starts_counting_at_opening(): void
    {
        $from = Carbon::parse('2026-09-15 22:00');
        $to   = Carbon::parse('2026-09-16 09:00');
        $this->assertSame(60, BusinessTime::minutesBetween($from, $to, '08:00', '17:00'));
        $this->assertSame(0, BusinessTime::minutesBetween($to, $from, '08:00', '17:00'));
    }
}
