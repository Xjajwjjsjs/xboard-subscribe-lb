<?php

namespace Plugin\SubscribeLb\Services\Strategies;

/**
 * 首期策略：在候选里均匀随机，允许抽中原节点。
 */
final class RandomStrategy implements AllocationStrategy
{
    public static function key(): string
    {
        return 'random';
    }

    public static function label(): string
    {
        return '随机（到期重抽，允许抽中原节点）';
    }

    public function choose(array $candidates, AllocationContext $context): int
    {
        $values = array_values($candidates);
        return (int) $values[random_int(0, count($values) - 1)]['server_id'];
    }
}
