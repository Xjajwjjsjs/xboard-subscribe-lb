<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\DB;

/**
 * 「入口子节点」= 为负载均衡建的 IP 入口：machine_id=NULL、parent 是同组成员（承载节点：真实监听+计费）。
 * 它们只是承载节点的另一个连接地址，所以配置必须始终跟承载节点一致（倍率不一致 = 订阅展示与计费不符）。
 *
 * sub-lb:check 每分钟调用 syncAll()：把承载节点的 CLONE_COLUMNS 同步到子节点，名称保持「承载名 · IP」。
 * 例外：承载节点没写 SNI、子节点显式写了 SNI（承载节点原来靠 host 域名当 SNI，子节点改用 IP 后必须显式写）→ 保留子节点的 SNI。
 * 只在语义上确实不同才写库（避免每分钟刷新 updated_at → 节点 cache_key / ETag 无谓变化）。
 */
final class EntryChildSync
{
    public const CLONE_COLUMNS = [
        'type', 'group_ids', 'route_ids', 'rate', 'tags', 'port', 'server_port', 'protocol_settings',
        'rate_time_enable', 'rate_time_ranges', 'sort',
    ];

    /** protocol_settings 里「承载节点为空时保留子节点值」的 SNI 路径 */
    private const KEEP_CHILD_SNI = ['tls.server_name', 'tls_settings.server_name'];

    /**
     * 计算子节点应有的克隆字段（不含 host / show / enabled）。
     * @return array 期望值（列 => 值）
     */
    public static function desired(array $parent, array $child): array
    {
        $want = [];
        foreach (self::CLONE_COLUMNS as $col) {
            $want[$col] = $parent[$col] ?? null;
        }
        $want['protocol_settings'] = self::mergeSettings($parent['protocol_settings'] ?? null, $child['protocol_settings'] ?? null);
        $want['name'] = (string) $parent['name'] . ' · ' . (string) $child['host'];
        $want['parent_id'] = (int) $parent['id'];
        return $want;
    }

    /** 只返回确实不同的列；protocol_settings / JSON 列按解码后比较 */
    public static function diff(array $child, array $want): array
    {
        $diff = [];
        foreach ($want as $col => $val) {
            $cur = $child[$col] ?? null;
            if (in_array($col, ['protocol_settings', 'group_ids', 'route_ids', 'tags', 'rate_time_ranges'], true)) {
                if (self::decode($cur) != self::decode($val)) {
                    $diff[$col] = $val;
                }
            } elseif ((string) ($cur ?? '') !== (string) ($val ?? '')) {
                $diff[$col] = $val;
            }
        }
        return $diff;
    }

    public static function apply(array $child, array $want, string $stamp): bool
    {
        $diff = self::diff($child, $want);
        if (!$diff) {
            return false;
        }
        DB::table('v2_server')->where('id', (int) $child['id'])->update($diff + ['updated_at' => $stamp]);
        return true;
    }

    /**
     * 同步全部负载均衡组里的入口子节点。
     * @return string[] 有改动的说明
     */
    public static function syncAll(int $now): array
    {
        $members = DB::table(Tables::MEMBERS)->get(['group_id', 'server_id']);
        $byGroup = [];
        foreach ($members as $m) {
            $byGroup[(int) $m->group_id][] = (int) $m->server_id;
        }
        $ids = array_merge([], ...array_values($byGroup ?: [[]]));
        if (empty($ids)) {
            return [];
        }
        $servers = [];
        foreach (DB::table('v2_server')->whereIn('id', $ids)->get() as $row) {
            $servers[(int) $row->id] = (array) $row;
        }
        $stamp = date('Y-m-d H:i:s', $now);
        $lines = [];
        foreach ($byGroup as $gid => $sids) {
            $inGroup = array_flip($sids);
            foreach ($sids as $sid) {
                $child = $servers[$sid] ?? null;
                if ($child === null || $child['machine_id'] !== null) {
                    continue;
                }
                $pid = (int) ($child['parent_id'] ?? 0);
                if ($pid <= 0 || !isset($inGroup[$pid]) || !isset($servers[$pid])) {
                    continue;
                }
                $want = self::desired($servers[$pid], $child);
                $diff = self::diff($child, $want);
                if ($diff) {
                    DB::table('v2_server')->where('id', $sid)->update($diff + ['updated_at' => $stamp]);
                    $lines[] = "组#{$gid} 入口子节点 #{$sid}（{$child['host']}）已跟随承载节点 #{$pid} 同步：" . implode(',', array_keys($diff));
                }
            }
        }
        if ($lines) {
            EventLog::record('entry_children_synced', EventLog::INFO, null, null, $lines);
        }
        return $lines;
    }

    private static function mergeSettings($parentRaw, $childRaw)
    {
        $parent = self::decode($parentRaw);
        $child = self::decode($childRaw);
        if (!is_array($parent) || !is_array($child)) {
            return $parentRaw;
        }
        $merged = $parent;
        $changed = false;
        foreach (self::KEEP_CHILD_SNI as $path) {
            $pv = data_get($parent, $path);
            $cv = data_get($child, $path);
            if (($pv === null || $pv === '') && is_string($cv) && $cv !== '') {
                data_set($merged, $path, $cv);
                $changed = true;
            }
        }
        return $changed ? json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $parentRaw;
    }

    private static function decode($v)
    {
        if (is_array($v) || $v === null) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : $v;
    }
}
