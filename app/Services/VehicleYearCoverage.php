<?php

namespace App\Services;

/** Pure interval policy, shared by purchase validation and isolated tests. */
final class VehicleYearCoverage
{
    public static function requiresYear(?int $generationFrom, ?int $generationTo, ?int $from, ?int $to, array $technical): bool
    {
        if ($generationFrom === null) {
            return $from !== null || $to !== null || $technical !== [];
        }
        $end = $generationTo ?? INF;
        if (($from ?? -INF) > $generationFrom || ($to ?? INF) < $end) {
            return true;
        }
        if ($technical === []) {
            return false;
        }
        usort($technical, fn ($a, $b) => ($a['year_from'] ?? -INF) <=> ($b['year_from'] ?? -INF));
        $covered = $generationFrom - 1;
        foreach ($technical as $range) {
            $start = $range['year_from'] ?? -INF;
            $until = $range['year_to'] ?? INF;
            if ($until < $generationFrom) {
                continue;
            }
            if ($start > $covered + 1) {
                return true;
            }
            $covered = max($covered, $until);
            if ($covered >= $end) {
                return false;
            }
        }

        return true;
    }
}
