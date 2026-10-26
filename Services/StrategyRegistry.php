<?php

namespace Plugin\SubscribeLb\Services;

use Plugin\SubscribeLb\Services\Strategies\AllocationStrategy;
use Plugin\SubscribeLb\Services\Strategies\RandomStrategy;

/**
 * 策略登记表。新增策略只需在 STRATEGIES 里加一行。
 * 库里存了未知 key（策略被删/拼错）时回落到 random 并记事件，不让订阅失败。
 */
final class StrategyRegistry
{
    public const DEFAULT = 'random';

    /** @var array<string, class-string<AllocationStrategy>> */
    private const STRATEGIES = [
        'random' => RandomStrategy::class,
    ];

    public static function has(?string $key): bool
    {
        return $key !== null && isset(self::STRATEGIES[$key]);
    }

    public static function resolve(?string $key, ?int $groupId = null): AllocationStrategy
    {
        if (!self::has($key)) {
            EventLog::record('unknown_strategy', EventLog::WARNING, $groupId, null,
                ['strategy' => $key, 'fallback' => self::DEFAULT], 'unknown_strategy:' . $groupId, 3600);
            $key = self::DEFAULT;
        }
        $class = self::STRATEGIES[$key];
        return new $class();
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::STRATEGIES as $class) {
            $out[] = ['key' => $class::key(), 'label' => $class::label()];
        }
        return $out;
    }
}
