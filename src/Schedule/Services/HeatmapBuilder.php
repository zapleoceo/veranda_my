<?php

declare(strict_types=1);

namespace App\Schedule\Services;

use App\Schedule\Domain\BlockColor;
use App\Schedule\Domain\TimeRange;

/**
 * Computes hourly coverage stats from a schedule state. Pure logic.
 *
 * Output:
 *   [
 *     'hourStart'    => 8,
 *     'hourEnd'      => 24,
 *     'dayCount'     => int,
 *     'perDayBlock'  => [dayIdx => ['senior'=>[24], 'main'=>[24], 'banya'=>[24], 'custom'=>[24]]],
 *     'aggHourTotal' => [24] sum across all days,
 *     'aggHourAvg'   => [24] avg per day,
 *   ]
 */
final class HeatmapBuilder
{
    public function __construct(
        private readonly int $hourStart = 8,
        private readonly int $hourEnd   = 24,
    ) {}

    /**
     * @param array $blocks  state.blocks
     * @param array $shifts  state.shifts (date-keyed)
     * @param array $days    output of PeriodBuilder::build()
     */
    public function build(array $blocks, array $shifts, array $days): array
    {
        $perDayBlock  = [];
        $aggHourTotal = array_fill(0, 24, 0);

        foreach ($days as $d) {
            $perBlock = [
                BlockColor::SENIOR => array_fill(0, 24, 0),
                BlockColor::MAIN   => array_fill(0, 24, 0),
                BlockColor::BANYA  => array_fill(0, 24, 0),
                BlockColor::CUSTOM => array_fill(0, 24, 0),
            ];
            foreach ($blocks as $block) {
                $color = BlockColor::of($block);
                foreach ($block['slots'] as $slotIdx => $_) {
                    $key   = $block['id'] . ':' . $slotIdx;
                    $shift = $shifts[$d['iso']][$key] ?? null;
                    if (!$shift) continue;
                    $sH = (int) floor(TimeRange::toHours((string) ($shift['start'] ?? '')));
                    $eH = (int) ceil (TimeRange::toHours((string) ($shift['end']   ?? '')));
                    // Overnight (end <= start) — wrap past midnight; hours
                    // after 24:00 wrap modulo 24 and still count under the
                    // start-day's coverage column. Mirror of JS impl.
                    if ($eH <= $sH) $eH += 24;
                    for ($h = $sH; $h < $eH; $h++) {
                        $hr = $h % 24;
                        if ($hr < 0 || $hr > 23) continue;
                        $perBlock[$color][$hr]++;
                        $aggHourTotal[$hr]++;
                    }
                }
            }
            $perDayBlock[$d['idx']] = $perBlock;
        }

        $dayCount   = max(1, count($days));
        $aggHourAvg = array_map(static fn($v) => round($v / $dayCount, 1), $aggHourTotal);

        return [
            'hourStart'    => $this->hourStart,
            'hourEnd'      => $this->hourEnd,
            'dayCount'     => count($days),
            'perDayBlock'  => $perDayBlock,
            'aggHourTotal' => $aggHourTotal,
            'aggHourAvg'   => $aggHourAvg,
        ];
    }

    /** Payload for #schStatsData (JS heatmap rebucketization). */
    public function buildStatsPayload(array $heatmap, array $days): array
    {
        $payload = [
            'hourStart' => $heatmap['hourStart'],
            'hourEnd'   => $heatmap['hourEnd'],
            'dayCount'  => $heatmap['dayCount'],
            'days'      => [],
        ];
        foreach ($days as $d) {
            $payload['days'][] = [
                // `iso` is required by JS recomputeFromState() to walk
                // App.state.shifts[iso] when rebuilding the heatmap from
                // live state on every shift edit.
                'iso'     => $d['iso'],
                'date'    => $d['date'],
                'dow'     => $d['dow'],
                'mon'     => $d['mon'],
                'weekend' => $d['weekend'],
                'hours'   => $heatmap['perDayBlock'][$d['idx']] ?? null,
            ];
        }
        return $payload;
    }

    // ─── Static helpers shared by content.php / public.php (mirror of
    // the parallel JS in schedule.js initHeatmap). Keeps the rendering
    // model in lockstep on both sides. ────────────────────────────────

    /** @return list<array{from:int, to:int, value:float}> max per bucket. */
    public static function bucketize(array $hours24, int $start, int $end, int $size): array
    {
        $out = [];
        for ($h = $start; $h < $end; $h += $size) {
            $max = 0.0;
            for ($k = 0; $k < $size && $h + $k < $end; $k++) {
                $max = max($max, (float) ($hours24[$h + $k] ?? 0));
            }
            $out[] = ['from' => $h, 'to' => min($h + $size, $end), 'value' => $max];
        }
        return $out;
    }

    /** @return array{alpha:float, text:string} */
    public static function cellAttrs(float $value, float $maxValue): array
    {
        $intensity = $maxValue > 0 ? $value / $maxValue : 0.0;
        return [
            'alpha' => $value > 0 ? 0.05 + $intensity * 0.90 : 0,
            'text'  => $intensity > 0.55 ? '#0f1117' : 'var(--text)',
        ];
    }

    /** "1|3|2" for integer breakdown, "1.5|3" for avg row, "·" when all empty. */
    public static function formatCellLabel(array $values, bool $asAvg): string
    {
        $nonZero = false;
        foreach ($values as $v) if ($v > 0.05) { $nonZero = true; break; }
        if (!$nonZero) return '·';
        return implode('|', array_map(static function ($v) use ($asAvg) {
            if ($asAvg) {
                $r = round($v, 1);
                return rtrim(rtrim(sprintf('%.1f', $r), '0'), '.');
            }
            return (string) (int) round($v);
        }, $values));
    }
}
