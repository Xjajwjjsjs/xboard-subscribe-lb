<?php

namespace Plugin\SubscribeLb\Services\Strategies;

use Plugin\SubscribeLb\Services\WeightedChoice;

/**
 * 按成员权重随机，默认全部为 1，允许抽中原节点。
 */
final class RandomStrategy implements AllocationStrategy
{
    public static function key(): string
    {
        return 'random';
    }

    public static function label(): string
    {
        return '随机（按权重，到期重抽）';
    }

    public function choose(array $candidates, AllocationContext $context): int
    {
        return WeightedChoice::choose($candidates);
    }
}
