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
                    for ($h = $sH; $h < $eH; $h++) {
                        if ($h < 0 || $h > 23) continue;
                        $perBlock[$color][$h]++;
                        $aggHourTotal[$h]++;
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
                'date'    => $d['date'],
                'dow'     => $d['dow'],
                'mon'     => $d['mon'],
                'weekend' => $d['weekend'],
                'hours'   => $heatmap['perDayBlock'][$d['idx']] ?? null,
            ];
        }
        return $payload;
    }
}
