<?php

namespace Plugin\SubscribeLb\Services;

use App\Models\User;
use Plugin\SubscribeLb\Services\Strategies\AllocationContext;
use Illuminate\Support\Facades\DB;

/**
 * 订阅级负载均衡。
 *
 * 位置：在「用户可用服务器列表」与「各客户端模板」之间做一次列表变换，模板本身完全不知道负载均衡的存在：
 *
 *   ServerService::getAvailableServers($user)        // 权限组 / show / 流量上限 / 按用户派生密码
 *     → 钩子 client.subscribe.servers：
 *         collapse()     收起所有组成员，按组插入一个占位条目（id = 800000 + 组 id）
 *         materialize()  逐组：读「用户+组」分配 → 有效则复用，否则选新成员并持久化
 *                        → 输出 = 选中成员的完整服务器数组，只把 name/id/sort 换成组的
 *     → 各协议模板（ClashMeta / SingBox / General / Surge / ...）照常渲染
 *
 * 因此：连接参数（host/port/协议/认证/传输/密码）、倍率 rate 全部来自实际成员；节点后端按成员自己的
 * server_id 上报流量、按成员倍率计费，与组名无关。没有任何跨用户缓存，输出只由本用户本次请求的数据组成。
 *
 * 失败处理（绝不让整份订阅失败）：
 *  - 表未建 / 组配置读失败          → 不生效，列表原样返回（成员按各自 show 独立出现）
 *  - 读写分配状态失败              → 降级为「用户+组+时间窗」稳定哈希选择：同一窗口内各客户端/实例结果一致，不落库
 *  - 某个组解析异常               → 只丢这个组，其余照常
 *  - 组内无可用成员               → 该组本次不下发 + 事件/日志（节流）
 */
final class LoadBalancer
{
    public const ENTRY_ID_BASE = 800000;
    public const META_KEY = 'sub_lb';
    public const MODE_ASSIGN = 'assign';   // 订阅：会创建/刷新分配
    public const MODE_PEEK = 'peek';       // 后台「按用户查分配」预览：只读

    public const MIN_INTERVAL = 60;
    public const MAX_INTERVAL = 2592000;   // 30 天

    private bool $active = false;
    /** gid => 组行（含 members 列表） */
    private array $groups = [];
    /** server_id => ['group_id' => gid, 'member' => 成员行] */
    private array $memberIndex = [];
    /** collapse() 记下的：gid => [server_id => 服务器数组]（本用户视角的可用成员） */
    private array $pending = [];
    private AssignmentStore $store;

    private function __construct()
    {
        $this->store = new AssignmentStore();
    }

    public static function make(): self
    {
        $lb = new self();
        $lb->load();
        return $lb;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** 订阅一步到位：收起 + 分配（client.subscribe.servers 钩子用） */
    public function forSubscription(array $servers, User $user): array
    {
        return $this->materialize($this->collapse($servers, $user), $user, self::MODE_ASSIGN);
    }

    /** 只读预览（后台按用户查分配）：不创建也不刷新分配 */
    public function present(array $servers, User $user): array
    {
        return $this->materialize($this->collapse($servers, $user), $user, self::MODE_PEEK);
    }

    public static function entryId(int $groupId): int
    {
        return self::ENTRY_ID_BASE + $groupId;
    }

    // ------------------------------------------------------------------ 配置加载

    private function load(): void
    {
        if (!Tables::installed()) {
            return;
        }
        try {
            $groups = [];
            foreach (DB::table(Tables::GROUPS)->orderBy('sort')->orderBy('id')->get() as $row) {
                $g = (array) $row;
                $g['id'] = (int) $g['id'];
                $g['members'] = [];
                $groups[$g['id']] = $g;
            }
            $index = [];
            foreach (DB::table(Tables::MEMBERS)->orderBy('id')->get() as $row) {
                $m = (array) $row;
                $gid = (int) $m['group_id'];
                if (!isset($groups[$gid])) {
                    continue; // 组已删、成员残留：不认领，服务器按普通节点处理
                }
                $groups[$gid]['members'][] = $m;
                $index[(int) $m['server_id']] = ['group_id' => $gid, 'member' => $m];
            }
            $this->groups = $groups;
            $this->memberIndex = $index;
            $this->active = !empty($index);
        } catch (\Throwable $e) {
            $this->active = false;
            EventLog::record('config_load_failed', EventLog::CRITICAL, null, null,
                ['error' => $e->getMessage(), 'effect' => '本次请求负载均衡不生效，成员按各自 show 独立下发'],
                'config_load_failed', 300);
        }
    }

    // ------------------------------------------------------------------ 第一步：收起成员

    /**
     * 成员（standalone=0）从列表里移除；对「启用 且 用户至少有一个有权限成员」的组插入占位条目。
     * 停用的组：成员仍保持隐藏，组也不下发（启停不会意外把成员全部放出来）。删除组 / 移出成员才会释放。
     */
    public function collapse(array $servers, User $user): array
    {
        $this->pending = [];
        if (!$this->active) {
            return $servers;
        }
        try {
            $available = [];
            $kept = [];
            foreach ($servers as $server) {
                $sid = (int) ($server['id'] ?? 0);
                $hit = isset($server[self::META_KEY]) ? null : ($this->memberIndex[$sid] ?? null);
                if ($hit !== null) {
                    $available[$hit['group_id']][$sid] = $server;
                    if ((int) ($hit['member']['standalone'] ?? 0) !== 1) {
                        continue;
                    }
                }
                $kept[] = $server;
            }

            $placeholders = [];
            foreach ($this->groups as $gid => $group) {
                if (!(int) $group['enabled'] || empty($available[$gid])) {
                    continue;
                }
                $this->pending[$gid] = $available[$gid];
                $placeholders[] = $this->placeholder($group, $available[$gid]);
            }
            return $this->insertBySort($kept, $placeholders);
        } catch (\Throwable $e) {
            $this->pending = [];
            EventLog::record('collapse_failed', EventLog::CRITICAL, null, null,
                ['error' => $e->getMessage()], 'collapse_failed', 300);
            return $servers;
        }
    }

    /**
     * 占位条目：以一个可用成员为底（让后续按 id / rate 的处理正常工作），rate 取用户可用成员里的最大值，
     * 保证「高倍率默认不下发」口径偏保守。materialize() 会把它换成真正选中的成员。
     */
    private function placeholder(array $group, array $availableMembers): array
    {
        $base = reset($availableMembers);
        $maxRate = 0.0;
        foreach ($availableMembers as $s) {
            $maxRate = max($maxRate, (float) ($s['rate'] ?? 0));
        }
        $entry = $this->decorate($group, $base, 'pending', null);
        $entry['rate'] = $maxRate;
        $entry[self::META_KEY]['pending'] = true;
        return $entry;
    }

    /** 按 sort 插入：插到第一个 sort 更大的条目之前，可用小数插空 */
    private function insertBySort(array $list, array $entries): array
    {
        foreach ($entries as $entry) {
            $pos = count($list);
            foreach ($list as $i => $s) {
                if (isset($s['sort']) && $s['sort'] !== null && (float) $s['sort'] > (float) $entry['sort']) {
                    $pos = $i;
                    break;
                }
            }
            array_splice($list, $pos, 0, [$entry]);
        }
        return $list;
    }

    // ------------------------------------------------------------------ 第二步：解析分配

    public function materialize(array $servers, User $user, string $mode): array
    {
        if (!$this->active || empty($this->pending)) {
            return $servers;
        }
        $now = time();
        $assignments = $this->loadAssignments((int) $user->id);

        $out = [];
        foreach ($servers as $server) {
            $gid = $this->pendingGroupId($server);
            if ($gid === null) {
                $out[] = $server;
                continue;
            }
            try {
                $entry = $this->resolve($gid, $user, $mode, $assignments, $now);
            } catch (\Throwable $e) {
                $entry = null;
                EventLog::record('resolve_failed', EventLog::CRITICAL, $gid, null,
                    ['error' => $e->getMessage(), 'effect' => '本次请求该组不下发'], 'resolve_failed:' . $gid, 300);
            }
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        $this->pending = [];
        return $this->dedupeNames($out);
    }

    private function pendingGroupId(array $server): ?int
    {
        $meta = $server[self::META_KEY] ?? null;
        if (!is_array($meta) || empty($meta['pending'])) {
            return null;
        }
        $gid = (int) ($meta['group_id'] ?? 0);
        return isset($this->pending[$gid]) ? $gid : null;
    }

    /** @return array<int, array>|null null = 状态存储不可用（走降级） */
    private function loadAssignments(int $userId): ?array
    {
        try {
            return $this->store->forUser($userId);
        } catch (\Throwable $e) {
            $this->noteStoreFailure($e, 'read');
            return null;
        }
    }

    private function resolve(int $gid, User $user, string $mode, ?array &$assignments, int $now): ?array
    {
        $group = $this->groups[$gid];
        $pool = MemberPool::build($group, $group['members'], $this->pending[$gid], $now);
        $interval = self::clampInterval((int) $group['interval_seconds']);

        if ($pool->tier === MemberPool::TIER_NONE) {
            EventLog::record('no_candidates', EventLog::CRITICAL, $gid, null,
                ['group' => $group['name'], 'members' => $this->blockedSummary($pool), 'effect' => '该组本次不下发'],
                'no_candidates:' . $gid, 600);
            return null;
        }
        if ($pool->tier === MemberPool::TIER_FAILOPEN) {
            EventLog::record('failopen', EventLog::CRITICAL, $gid, null,
                ['group' => $group['name'], 'effect' => '所有成员自动健康信号均为不可用，暂时忽略自动信号（只尊重人工/外部强制下线）'],
                'failopen:' . $gid, 600);
        } elseif ($pool->tier === MemberPool::TIER_DRAINING) {
            EventLog::record('draining_only', EventLog::WARNING, $gid, null,
                ['group' => $group['name'], 'effect' => '没有健康的活跃成员，临时由维护中成员承接'],
                'draining_only:' . $gid, 1800);
        }

        $current = $assignments === null ? null : ($assignments[$gid] ?? null);
        $reason = 'initial';
        if ($current !== null) {
            $sid = (int) $current['server_id'];
            $expired = $now >= (int) $current['assigned_at'] + $interval;
            if (!$expired && $pool->keepable($sid)) {
                return $this->decorate($group, $pool->server($sid), (string) $current['reason'], (int) $current['assigned_at']);
            }
            $reason = $expired ? 'expired' : 'failover:' . $pool->whyNotKeepable($sid);
        }

        if ($mode === self::MODE_PEEK) {
            // 只读视角：没有有效分配时展示一个代表成员（候选首位），不写库
            return $this->decorate($group, $pool->server($pool->candidates[0]), 'preview', null);
        }

        if ($assignments === null) {
            $sid = $this->stableChoice($pool->candidates, (int) $user->id, $gid, $interval, $now);
            return $this->decorate($group, $pool->server($sid), 'degraded', null);
        }

        $strategy = StrategyRegistry::resolve($group['strategy'] ?? null, $gid);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidates = [];
            foreach ($pool->candidates as $cid) {
                $candidates[] = $pool->entries[$cid];
            }
            $sid = $strategy->choose($candidates, new AllocationContext(
                (int) $user->id,
                $group,
                $current !== null ? (int) $current['server_id'] : null,
                $reason,
                $pool->tier,
                $now
            ));
            if (!$pool->isCandidate($sid)) {
                $sid = $pool->candidates[0];
            }

            try {
                $written = $current === null
                    ? $this->store->create((int) $user->id, $gid, $sid, $now, $reason)
                    : $this->store->replace($current, $sid, $now, $reason);
            } catch (\Throwable $e) {
                $this->noteStoreFailure($e, 'write', $gid);
                $sid = $this->stableChoice($pool->candidates, (int) $user->id, $gid, $interval, $now);
                return $this->decorate($group, $pool->server($sid), 'degraded', null);
            }

            if ($written) {
                $assignments[$gid] = [
                    'user_id' => (int) $user->id,
                    'group_id' => $gid,
                    'server_id' => $sid,
                    'assigned_at' => $now,
                    'version' => $current === null ? 1 : (int) $current['version'] + 1,
                    'reason' => $reason,
                ];
                return $this->decorate($group, $pool->server($sid), $reason, $now);
            }

            // 并发：另一个请求（另一个客户端 / worker / 实例）刚写入 → 读回赢家，以它为准
            try {
                $current = $this->store->find((int) $user->id, $gid);
            } catch (\Throwable $e) {
                $this->noteStoreFailure($e, 'read', $gid);
                $sid = $this->stableChoice($pool->candidates, (int) $user->id, $gid, $interval, $now);
                return $this->decorate($group, $pool->server($sid), 'degraded', null);
            }
            if ($current === null) {
                $reason = 'initial';
                continue; // 行被后台「重新分配」删掉了，重来
            }
            $won = (int) $current['server_id'];
            $expired = $now >= (int) $current['assigned_at'] + $interval;
            if (!$expired && $pool->keepable($won)) {
                $assignments[$gid] = $current;
                return $this->decorate($group, $pool->server($won), (string) $current['reason'], (int) $current['assigned_at']);
            }
            $reason = $expired ? 'expired' : 'failover:' . $pool->whyNotKeepable($won);
        }

        // 极端竞争下 3 次都没写进去：本次用稳定哈希结果，不落库
        $sid = $this->stableChoice($pool->candidates, (int) $user->id, $gid, $interval, $now);
        return $this->decorate($group, $pool->server($sid), 'unpersisted', null);
    }

    /**
     * 降级选择：同一「用户+组+时间窗」在任何客户端、worker、实例上都得到同一个成员；只用本用户的数据。
     */
    private function stableChoice(array $candidateIds, int $userId, int $gid, int $interval, int $now): int
    {
        $ids = array_map('intval', $candidateIds);
        sort($ids);
        $bucket = intdiv($now, max(1, $interval));
        $hash = crc32("sub-lb:{$userId}:{$gid}:{$bucket}");
        return $ids[$hash % count($ids)];
    }

    private function noteStoreFailure(\Throwable $e, string $op, ?int $gid = null): void
    {
        EventLog::record('store_degraded', EventLog::CRITICAL, $gid, null,
            ['op' => $op, 'error' => $e->getMessage(), 'effect' => '按用户+组+时间窗稳定哈希选择，不落库'],
            'store_degraded:' . $op, 300);
    }

    // ------------------------------------------------------------------ 输出

    /**
     * 组条目 = 选中成员的完整服务器数组；只替换展示相关字段。
     * 不改 type/host/port/password/protocol_settings/rate/tags —— 模板据此生成实际连接配置。
     */
    private function decorate(array $group, array $server, string $reason, ?int $assignedAt): array
    {
        $gid = (int) $group['id'];
        $entry = $server;
        $entry['id'] = self::entryId($gid);
        $entry['name'] = (string) $group['name'];
        $entry['sort'] = (float) $group['sort'];
        $entry['cache_key'] = sprintf('lb-%d-%s-%s', $gid, $group['updated_at'] ?? '0', $server['cache_key'] ?? ($server['id'] ?? '0'));
        $entry[self::META_KEY] = [
            'group_id' => $gid,
            'server_id' => (int) ($server['id'] ?? 0),
            'reason' => $reason,
            'assigned_at' => $assignedAt,
        ];
        return $entry;
    }

    /** 组名与其他节点重名时（Clash 等客户端遇到重名代理会整份配置报错）给组条目加后缀 */
    private function dedupeNames(array $servers): array
    {
        $taken = [];
        foreach ($servers as $s) {
            if (!isset($s[self::META_KEY])) {
                $taken[(string) ($s['name'] ?? '')] = true;
            }
        }
        foreach ($servers as $i => $s) {
            if (!isset($s[self::META_KEY])) {
                continue;
            }
            $name = (string) $s['name'];
            if (isset($taken[$name])) {
                $gid = (int) $s[self::META_KEY]['group_id'];
                $servers[$i]['name'] = $name . ' ·' . $gid;
                EventLog::record('name_conflict', EventLog::WARNING, $gid, null,
                    ['name' => $name, 'renamed_to' => $servers[$i]['name']], 'name_conflict:' . $gid, 3600);
            }
            $taken[$servers[$i]['name']] = true;
        }
        return $servers;
    }

    private function blockedSummary(MemberPool $pool): array
    {
        $out = [];
        foreach ($pool->entries as $sid => $e) {
            $out[$sid] = $e['blocked'] ?? ($e['healthy'] ? 'ok' : ($e['health_reason'] ?? 'unhealthy'));
        }
        return $out;
    }

    public static function clampInterval(int $seconds): int
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $seconds));
    }
}
