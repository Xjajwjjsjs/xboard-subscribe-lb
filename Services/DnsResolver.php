<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\DB;

/**
 * 域名入口自动解析。
 *
 * 场景：节点入口是域名（尤其是第三方中转商的域名，IP 会被对方轮换），客户端连域名会受 DNS 污染/干扰影响。
 * 组配置一个「解析来源节点」后，巡检（sub-lb:check，每分钟）对到期的组（默认间隔 15 分钟）在**面板服务器**解析来源节点 host 的 A 记录：
 *  - 只取 IPv4；连查 3 次取并集，兼容每次只返回部分记录的轮询 DNS
 *  - 每个 IP 一个「自动管理子节点」：parent_id=来源节点、machine_id=NULL、host=IP，其余字段每次都从来源节点重新同步（不会漂移）
 *  - IP 从 DNS 消失 → 对应成员 status=disabled（用户下次更新订阅立即改分配）；IP 回来 → 恢复 active
 *  - 来源节点本身作为 disabled 成员隐藏（它仍是真实监听与计费的节点，订阅里不再下发域名入口）
 *  - 解析失败 / 0 条 A 记录 → **什么都不改**（保留现有成员），记事件；下一轮（1 分钟后）继续重试
 * 订阅下发路径完全不变：成员就是普通服务器，LoadBalancer 不需要知道它们是解析出来的。
 */
final class DnsResolver
{
    public const DEFAULT_INTERVAL = 900;   // 默认 15 分钟解析一次
    public const MIN_INTERVAL = 300;
    public const MAX_INTERVAL = 86400;
    public const MANAGED = 'dns';
    public const MANAGED_SOURCE = 'dns-source';

    /**
     * 面板服务器解析，只取 IPv4。
     * @return string[] 排序后的 IPv4 列表；解析失败返回空数组
     */
    public static function lookupIpv4(string $host, int $tries = 3): array
    {
        $ips = [];
        for ($i = 0; $i < $tries; $i++) {
            $got = @gethostbynamel($host);
            if (is_array($got)) {
                foreach ($got as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[$ip] = true;
                    }
                }
            }
        }
        $ips = array_keys($ips);
        sort($ips);
        return $ips;
    }

    /**
     * 解析所有到期的组（巡检调用）。
     * @return array<int, array{group_id: int, name: string, changed: bool, lines: string[], error: ?string}>
     */
    public static function runDue(int $now, bool $force = false, ?int $onlyGroup = null): array
    {
        $q = DB::table(Tables::GROUPS)->whereNotNull('resolve_server_id');
        if ($onlyGroup !== null) {
            $q->where('id', $onlyGroup);
        }
        $reports = [];
        foreach ($q->get() as $row) {
            $group = (array) $row;
            $interval = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, (int) $group['resolve_interval']));
            if (!$force && $group['resolved_at'] !== null && ($now - (int) $group['resolved_at']) < $interval) {
                continue;
            }
            try {
                $reports[] = self::resolveGroup($group, $now);
            } catch (\Throwable $e) {
                $reports[] = self::fail($group, ['group_id' => (int) $group['id'], 'name' => (string) $group['name'],
                    'changed' => false, 'lines' => [], 'error' => null], '解析同步异常：' . $e->getMessage(), $now);
            }
        }
        return $reports;
    }

    public static function resolveGroup(array $group, int $now): array
    {
        $gid = (int) $group['id'];
        $report = ['group_id' => $gid, 'name' => (string) $group['name'], 'changed' => false, 'lines' => [], 'error' => null];
        $source = DB::table('v2_server')->where('id', (int) $group['resolve_server_id'])->first();
        if (!$source) {
            return self::fail($group, $report, '解析来源节点 #' . $group['resolve_server_id'] . ' 不存在', $now);
        }
        $source = (array) $source;
        $host = trim((string) $source['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return self::fail($group, $report, "来源节点 #{$source['id']} 的地址不是域名（{$host}），无需解析", $now);
        }
        $ips = self::lookupIpv4($host);
        if (empty($ips)) {
            return self::fail($group, $report, "面板服务器解析 {$host} 没有拿到 IPv4（A 记录），保留现有成员不变", $now);
        }

        $added = $removed = $restored = [];
        DB::transaction(function () use ($gid, $source, $ips, $now, &$added, &$removed, &$restored) {
            $stamp = date('Y-m-d H:i:s', $now);
            $sid = (int) $source['id'];

            // 1) 来源节点作为隐藏成员（不在组里才加）
            $srcMember = DB::table(Tables::MEMBERS)->where('server_id', $sid)->first();
            if ($srcMember && (int) $srcMember->group_id !== $gid) {
                throw new \RuntimeException("来源节点 #{$sid} 已在别的负载均衡组里");
            }
            if (!$srcMember) {
                DB::table(Tables::MEMBERS)->insert([
                    'group_id' => $gid, 'server_id' => $sid, 'status' => MemberPool::STATUS_DISABLED, 'standalone' => 0,
                    'managed' => self::MANAGED_SOURCE, 'health_note' => '域名解析来源节点：实际监听/计费，组内隐藏',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // 2) 现有自动成员：host => [member, server]
            $existing = [];
            $rows = DB::table(Tables::MEMBERS . ' as m')->join('v2_server as s', 's.id', '=', 'm.server_id')
                ->where('m.group_id', $gid)->where('m.managed', self::MANAGED)
                ->select('m.id as member_id', 'm.status', 's.*')->get();
            foreach ($rows as $r) {
                $existing[(string) $r->host] = (array) $r;
            }


            // 3) 每个解析到的 IP：有则同步字段/恢复启用，无则新建子节点 + 成员
            foreach ($ips as $ip) {
                $base = isset($existing[$ip]) ? $existing[$ip] : ['host' => $ip, 'protocol_settings' => null];
                $want = EntryChildSync::desired($source, $base) + ['machine_id' => null, 'show' => 1, 'enabled' => 1];
                if (isset($existing[$ip])) {
                    $cur = $existing[$ip];
                    EntryChildSync::apply($cur, $want, $stamp);
                    if ($cur['status'] !== MemberPool::STATUS_ACTIVE) {
                        DB::table(Tables::MEMBERS)->where('id', (int) $cur['member_id'])
                            ->update(['status' => MemberPool::STATUS_ACTIVE, 'updated_at' => $now]);
                        $restored[] = $ip;
                    }
                    continue;
                }
                // 复用之前建过、但已不在组里的同 IP 子节点，避免越积越多
                $childId = DB::table('v2_server')->where('parent_id', $sid)->whereNull('machine_id')->where('host', $ip)
                    ->whereNotIn('id', DB::table(Tables::MEMBERS)->select('server_id'))->value('id');
                if ($childId) {
                    DB::table('v2_server')->where('id', $childId)->update($want + ['updated_at' => $stamp]);
                } else {
                    $childId = DB::table('v2_server')->insertGetId($want + [
                        'host' => $ip, 'code' => null, 'custom_outbounds' => null, 'custom_routes' => null,
                        'cert_config' => null, 'transfer_enable' => null, 'u' => 0, 'd' => 0,
                        'created_at' => $stamp, 'updated_at' => $stamp,
                    ]);
                }
                DB::table(Tables::MEMBERS)->insert([
                    'group_id' => $gid, 'server_id' => (int) $childId, 'status' => MemberPool::STATUS_ACTIVE,
                    'standalone' => 0, 'managed' => self::MANAGED, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $added[] = $ip;
            }

            // 4) 已从 DNS 消失的 IP → 停用（用户下次更新订阅改分配）；字段照样同步，回来时直接可用
            foreach ($existing as $ip => $cur) {
                if (in_array($ip, $ips, true)) {
                    continue;
                }
                EntryChildSync::apply($cur, EntryChildSync::desired($source, $cur) + ['machine_id' => null, 'show' => 1, 'enabled' => 1], $stamp);
                if ($cur['status'] !== MemberPool::STATUS_DISABLED) {
                    DB::table(Tables::MEMBERS)->where('id', (int) $cur['member_id'])
                        ->update(['status' => MemberPool::STATUS_DISABLED, 'updated_at' => $now]);
                    $removed[] = $ip;
                }
            }
            if ($added || $removed || $restored) {
                DB::table(Tables::GROUPS)->where('id', $gid)->update(['updated_at' => $now]);
            }
        });

        $detail = ['host' => $host, 'ips' => $ips, 'added' => $added, 'removed' => $removed, 'restored' => $restored, 'error' => null, 'at' => $now];
        DB::table(Tables::GROUPS)->where('id', $gid)->update([
            'resolved_at' => $now,
            'resolve_detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
        if ($added || $removed || $restored) {
            $report['changed'] = true;
            $txt = sprintf('「%s」%s 解析 → %s（新增 %s；停用 %s；恢复 %s）', $group['name'], $host, implode(',', $ips),
                $added ? implode(',', $added) : '无', $removed ? implode(',', $removed) : '无', $restored ? implode(',', $restored) : '无');
            $report['lines'][] = $txt;
            EventLog::record('resolve_changed', $removed ? EventLog::WARNING : EventLog::INFO, $gid, null, $detail);
        }
        return $report;
    }

    private static function fail(array $group, array $report, string $msg, int $now): array
    {
        $report['error'] = $msg;
        $prev = json_decode((string) ($group['resolve_detail'] ?? ''), true) ?: [];
        $prev['error'] = $msg;
        $prev['error_at'] = $now;
        DB::table(Tables::GROUPS)->where('id', (int) $group['id'])->update([
            'resolve_detail' => json_encode($prev, JSON_UNESCAPED_UNICODE),
        ]);
        EventLog::record('resolve_failed', EventLog::CRITICAL, (int) $group['id'], null,
            ['error' => $msg, 'effect' => '保留现有成员，1 分钟后重试'], 'resolve_failed:' . $group['id'], 1800);
        return $report;
    }
}
