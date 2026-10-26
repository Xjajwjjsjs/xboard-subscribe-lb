<?php

namespace Plugin\SubscribeLb\Services\Strategies;

/**
 * 分配策略接口。
 *
 * 只负责「从已筛好的候选里挑一个」——候选筛选（权限/健康/成员状态）、到期判断、持久化、并发控制、
 * 模板输出都不归策略管。新增策略：实现本接口 → 在 StrategyRegistry::STRATEGIES 登记 → 后台下拉自动出现。
 *
 * 预留方向（本期未实现）：
 *  - weighted：按成员权重抽取（需给 sub_lb_members 加 weight 列）
 *  - stable：一致性哈希，扩容时只迁移最少用户
 *  - least_load / health_score：用 $candidates[*]['server']['online']、metrics 等负载字段挑最空闲的
 */
interface AllocationStrategy
{
    /** 存进 sub_lb_groups.strategy 的标识 */
    public static function key(): string;

    /** 后台展示名 */
    public static function label(): string;

    /**
     * @param array<int, array> $candidates 非空；元素为 MemberPool entry（含 server_id / member / server / signals）
     * @return int 选中的 server_id（必须来自 $candidates，否则调用方回落到第一个候选）
     */
    public function choose(array $candidates, AllocationContext $context): int;
}
