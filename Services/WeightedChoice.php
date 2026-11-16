<?php

namespace Plugin\SubscribeLb\Services;

/** Positive relative weights; missing/invalid legacy values keep weight 1. */
final class WeightedChoice
{
    public const DEFAULT_WEIGHT = 1;
    public const MAX_WEIGHT = 10000;

    public static function weight(array $member): int
    {
        $value = filter_var($member['weight'] ?? self::DEFAULT_WEIGHT, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_WEIGHT]]);
        return $value === false ? self::DEFAULT_WEIGHT : $value;
    }

    /** A supplied hash makes degraded allocation stable across workers/clients. */
    public static function choose(array $candidates, ?int $hash = null): int
    {
        if (!$candidates) {
            throw new \InvalidArgumentException('At least one candidate is required');
        }
        $total = 0;
        foreach ($candidates as $candidate) {
            $total += self::weight($candidate['member'] ?? []);
        }
        $ticket = $hash === null ? random_int(0, $total - 1) : $hash % $total;
        foreach ($candidates as $candidate) {
            $ticket -= self::weight($candidate['member'] ?? []);
            if ($ticket < 0) {
                return (int) $candidate['server_id'];
            }
        }
        throw new \LogicException('Weighted allocation exceeded candidate total');
    }
}
