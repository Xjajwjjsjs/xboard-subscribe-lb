<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\DB;

/**
 * 组级视角（后台展示 + sub-lb:check 巡检共用）：不看用户权限，评估每个组的成员可用情况与配置风险。
 */
final class GroupInspector
{
    public const STATES = [
        'ok' => '正常',
        'degraded' => '部分成员不可用（已自动绕开）',
        'draining_only' => '仅剩维护中成员承接',
        'failopen' => '全部成员自动判定不健康，已忽略自动信号（fail-open）',
        'empty' => '无可用成员，组不下发',
        'disabled' => '已停用',
    ];

    /**
     * @return array{groups: array<int, array>, members: array<int, array>, snapshot: array<int, array>}
     */
    public static function loadAll(): array
    {
        $groups = [];
        foreach (DB::table(Tables::GROUPS)->orderBy('sort')->orderBy('id')->get() as $row) {
            $g = (array) $row;
            $g['id'] = (int) $g['id'];
            $g['members'] = [];
            $groups[$g['id']] = $g;
        }
        $members = [];
        foreach (DB::table(Tables::MEMBERS)->orderBy('id')->get() as $row) {
            $m = (array) $row;
            $members[(int) $m['id']] = $m;
            if (isset($groups[(int) $m['group_id']])) {
                $groups[(int) $m['group_id']]['members'][] = $m;
            }
        }
        $snapshot = ServerSnapshot::load(array_column($members, 'server_id'));
        return ['groups' => $groups, 'members' => $members, 'snapshot' => $snapshot];
    }

    /** 组级评估：返回 pool + 结论 + 配置风险提示 */
    public static function inspect(array $group, array $snapshot, int $now): array
    {
        $pool = MemberPool::build($group, $group['members'], ServerSnapshot::availableOnly($snapshot), $now);
        $state = (int) $group['enabled'] ? $pool->state() : 'disabled';
        return [
            'pool' => $pool,
            'state' => $state,
            'warnings' => self::warnings($group, $snapshot),
        ];
    }

    /** 配置风险：倍率不一致、协议混用、无权限组、无心跳却用心跳模式、名字撞车 */
    public static function warnings(array $group, array $snapshot): array
    {
        $warnings = [];
        $signatures = [];
        $types = [];
        $mode = HealthEvaluator::normalizeMode($group['health_mode'] ?? null);
        foreach ($group['members'] as $m) {
            $s = $snapshot[(int) $m['server_id']] ?? null;
            if ($s === null) {
                $warnings[] = "成员服务器 #{$m['server_id']} 已不存在，可移出该成员";
                continue;
            }
            if (($m['status'] ?? 'active') !== MemberPool::STATUS_DISABLED) {
                $signatures[$s['rate_signature']][] = "#{$s['id']}({$s['rate']}x)";
                $types[$s['type']] = true;
            }
            if (empty($s['group_ids'])) {
                $warnings[] = "成员 #{$s['id']} 没有配置权限组，任何用户都分不到它";
            }
            if (!$s['show']) {
                $warnings[] = "成员 #{$s['id']} 的「显示」已关闭（show=0），不会参与分配";
            }
            if (in_array($mode, ['heartbeat', 'heartbeat+probe'], true) && empty($s['last_check_at'])
                && ($m['health_override'] ?? null) !== 'up' && ($m['status'] ?? 'active') !== MemberPool::STATUS_DISABLED) {
                $warnings[] = "成员 #{$s['id']} 没有面板心跳记录（未接节点后端？），心跳模式下会被判不健康；可改用 probe/none 或对它强制可用";
            }
        }
        if (count($signatures) > 1) {
            $parts = [];
            foreach ($signatures as $list) {
                $parts[] = implode('/', $list);
            }
            $warnings[] = '成员倍率配置不一致：' . implode(' ≠ ', $parts) . '。组名展示的倍率可能与实际计费不符（计费始终按实际成员倍率）';
        }
        if (!empty($group['resolve_server_id'])) {
            $detail = json_decode((string) ($group['resolve_detail'] ?? ''), true) ?: [];
            $interval = max(DnsResolver::MIN_INTERVAL, (int) ($group['resolve_interval'] ?? DnsResolver::DEFAULT_INTERVAL));
            if (!empty($detail['error']) && (int) ($detail['error_at'] ?? 0) >= (int) ($group['resolved_at'] ?? 0)) {
                $warnings[] = '域名解析失败：' . $detail['error'];
            } elseif (empty($group['resolved_at'])) {
                $warnings[] = '域名入口尚未解析（巡检下一轮会处理）';
            } elseif (time() - (int) $group['resolved_at'] > 3 * $interval) {
                $warnings[] = '域名入口超过 3 个解析周期没有成功更新，检查巡检是否在跑';
            }
        }
        if (count($types) > 1) {
            $warnings[] = '成员协议混用（' . implode(' / ', array_keys($types)) . '）：不支持某协议的客户端（如 Surge 不支持 VLESS）在分到该成员期间看不到这个组';
        }
        $hidden = [];
        foreach ($group['members'] as $m) {
            if ((int) ($m['standalone'] ?? 0) !== 1) {
                $hidden[] = (int) $m['server_id'];
            }
        }
        $dup = DB::table('v2_server')->where('name', $group['name'])->where('show', 1)
            ->when(!empty($hidden), fn($q) => $q->whereNotIn('id', $hidden))
            ->value('id');
        if ($dup) {
            $warnings[] = "组名与节点 #{$dup} 重名，下发时组条目会自动加后缀";
        }
        return $warnings;
    }
}
