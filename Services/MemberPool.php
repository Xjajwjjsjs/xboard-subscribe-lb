<?php

namespace Plugin\SubscribeLb\Services;

/**
 * 候选成员筛选：给定一个组、它的成员行、以及「当前视角下可用的服务器数组」，算出每个成员的资格和分层候选。
 *
 * 视角：
^ *  - 订阅：availableById = 该用户 ServerService::getAvailableServers() 的结果（已含权限组、show=1、
 *    节点流量上限过滤，以及按该用户派生的密码），所以「失去用户权限」天然体现为 server_unavailable
 *  - 后台/巡检：availableById = 全部成员服务器（只过滤 show 与流量上限，不看权限组）
 *
 * 硬性排除（任何兜底都不放宽）：
 *   server_unavailable  服务器已删除 / 隐藏(show=0) / 用户无权限 / 节点流量超限
 *   parent_unavailable  入口子节点的承载节点（同组成员）出现上一条任一情况
 *                       —— 隐藏承载节点 = 整组入口下线
 *   parent_disabled     承载节点 enabled=0
 *   server_disabled     v2_server.enabled=0（节点后端不再运行该节点）
 *   member_disabled     成员状态=停用
 *   override_down       人工/外部强制不可用
 *
 * 分层（取第一个非空层做新分配）：
 *   active    活跃 ∧ 健康
 *   draining  维护中 ∧ 健康（组内没有健康的活跃成员时临时承接，避免整组消失）
 *   failopen  活跃 ∪ 维护中，忽略自动健康信号（所有成员同时被判不健康，更可能是心跳/探测侧故障，与 my-monitor「过半失败视为探测侧问题」同理）
 *   none      没有可用成员 → 该组本次不下发
 */
final class MemberPool
{
    public const TIER_ACTIVE = 'active';
    public const TIER_DRAINING = 'draining';
    public const TIER_FAILOPEN = 'failopen';
    public const TIER_NONE = 'none';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAINING = 'draining';
    public const STATUS_DISABLED = 'disabled';
    public const STATUSES = [
        self::STATUS_ACTIVE => '活跃',
        self::STATUS_DRAINING => '维护中（不接新分配）',
        self::STATUS_DISABLED => '停用（立即失效）',
    ];

    /** server_id => entry */
    public array $entries = [];
    public string $tier = self::TIER_NONE;
    /** 当前层的候选 server_id（按服务器排序稳定排列） */
    public array $candidates = [];

    /**
     * @param array $group 组行
     * @param array $members 该组成员行（数组）
     * @param array $availableById server_id => 服务器数组（视角见类注释）
     */
    public static function build(array $group, array $members, array $availableById, int $now): self
    {
        $pool = new self();
        $tiers = [self::TIER_ACTIVE => [], self::TIER_DRAINING => [], self::TIER_FAILOPEN => []];
        $inGroup = [];
        foreach ($members as $m) {
            $inGroup[(int) $m['server_id']] = true;
        }

        foreach ($members as $member) {
            $sid = (int) $member['server_id'];
            $server = $availableById[$sid] ?? null;
            $status = $member['status'] ?? self::STATUS_ACTIVE;
            $override = $member['health_override'] ?? null;
            $entry = [
                'server_id' => $sid,
                'member' => $member,
                'server' => $server,
                'status' => $status,
                'override' => $override,
                'blocked' => null,
                'healthy' => false,
                'auto_ok' => false,
                'health_reason' => null,
                'signals' => null,
            ];

            // 入口子节点（parent 是同组的承载节点）跟随承载节点：承载节点隐藏/超流量上限/用户无权限/已删/禁用 → 子节点一起下线
            $parentId = $server !== null ? (int) ($server['parent_id'] ?? 0) : 0;
            $parentTracked = $parentId > 0 && isset($inGroup[$parentId]);
            $parentGone = $parentTracked && !isset($availableById[$parentId]);
            $parentOff = $parentTracked && isset($availableById[$parentId])
                && array_key_exists('enabled', $availableById[$parentId]) && $availableById[$parentId]['enabled'] === false;
            if ($server === null) {
                $entry['blocked'] = 'server_unavailable';
            } elseif ($parentGone) {
                $entry['blocked'] = 'parent_unavailable';
            } elseif ($parentOff) {
                $entry['blocked'] = 'parent_disabled';
            } elseif (array_key_exists('enabled', $server) && $server['enabled'] === false) {
                $entry['blocked'] = 'server_disabled';
            } elseif ($status === self::STATUS_DISABLED) {
                $entry['blocked'] = 'member_disabled';
            } elseif ($override === 'down') {
                $entry['blocked'] = 'override_down';
            } else {
                $eval = HealthEvaluator::evaluate($group, $member, $server, $now);
                $entry['signals'] = $eval['signals'];
                $entry['auto_ok'] = $eval['auto_ok'];
                $entry['health_reason'] = $eval['reason'];
                $entry['healthy'] = $override === 'up' || $eval['auto_ok'];
                $isDraining = $status === self::STATUS_DRAINING;
                if ($entry['healthy']) {
                    $tiers[$isDraining ? self::TIER_DRAINING : self::TIER_ACTIVE][] = $sid;
                }
                $tiers[self::TIER_FAILOPEN][] = $sid;
            }
            $pool->entries[$sid] = $entry;
        }

        // 候选顺序与服务器排序一致（sort, id），保证只读预览/降级哈希在各实例上结果稳定
        $order = function (int $a, int $b) use ($pool): int {
            $sa = (float) ($pool->entries[$a]['server']['sort'] ?? 0);
            $sb = (float) ($pool->entries[$b]['server']['sort'] ?? 0);
            return $sa <=> $sb ?: $a <=> $b;
        };
        foreach ($tiers as $name => $ids) {
            if (!empty($ids)) {
                usort($ids, $order);
                $pool->tier = $name;
                $pool->candidates = $ids;
                break;
            }
        }
        return $pool;
    }

    public function isCandidate(int $serverId): bool
    {
        return in_array($serverId, $this->candidates, true);
    }

    /**
     * 已有分配是否还能继续用（不看是否到期，到期由调用方判断）。
     * failopen 层下只要不是硬性排除就保留，避免监控侧故障时把所有人来回打乱。
     */
    public function keepable(int $serverId): bool
    {
        return $this->whyNotKeepable($serverId) === null;
    }

    public function whyNotKeepable(int $serverId): ?string
    {
        $entry = $this->entries[$serverId] ?? null;
        if ($entry === null) {
            return 'member_removed';
        }
        if ($entry['blocked'] !== null) {
            return $entry['blocked'];
        }
        if (!$entry['healthy'] && $this->tier !== self::TIER_FAILOPEN) {
            return $entry['health_reason'] ?? 'unhealthy';
        }
        return null;
    }

    public function server(int $serverId): ?array
    {
        return $this->entries[$serverId]['server'] ?? null;
    }

    /** 各状态计数（后台展示用） */
    public function summary(): array
    {
        $s = ['total' => 0, 'active_healthy' => 0, 'active' => 0, 'draining_healthy' => 0, 'draining' => 0, 'blocked' => 0];
        foreach ($this->entries as $e) {
            $s['total']++;
            if ($e['blocked'] !== null) {
                $s['blocked']++;
                continue;
            }
            if ($e['status'] === self::STATUS_DRAINING) {
                $s['draining']++;
                $s['draining_healthy'] += $e['healthy'] ? 1 : 0;
            } else {
                $s['active']++;
                $s['active_healthy'] += $e['healthy'] ? 1 : 0;
            }
        }
        return $s;
    }

    /**
     * 组级巡检结论：ok | degraded | draining_only | failopen | empty
     */
    public function state(): string
    {
        $s = $this->summary();
        switch ($this->tier) {
            case self::TIER_NONE:
                return 'empty';
            case self::TIER_FAILOPEN:
                return 'failopen';
            case self::TIER_DRAINING:
                return 'draining_only';
        }
        return $s['active_healthy'] < $s['active'] ? 'degraded' : 'ok';
    }
}
