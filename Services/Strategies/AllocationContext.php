<?php

namespace Plugin\SubscribeLb\Services\Strategies;

/**
 * 交给策略的只读上下文。
 */
final class AllocationContext
{
    public function __construct(
        public readonly int $userId,
        public readonly array $group,
        public readonly ?int $previousServerId,
        public readonly string $reason,   // initial | expired | failover:<原因>
        public readonly string $tier,     // MemberPool::TIER_*
        public readonly int $now,
    ) {
    }
}
