<?php

namespace Plugin\SubscribeLb\Services;

use App\Models\Server;

/**
 * 后台 / 巡检视角的成员服务器快照（不看用户权限组）。
 *
 * 返回的数组字段与 getAvailableServers() 的输出同名（last_check_at / enabled / show / rate ...），
 * 这样 MemberPool / HealthEvaluator 两个视角共用同一套判定代码。
 */
final class ServerSnapshot
{
    /**
     * @param int[] $serverIds
     * @return array<int, array> server_id => 服务器数组（含 available：show=1 且未超节点流量上限）
     */
    public static function load(array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_map('intval', $serverIds)));
        if (empty($serverIds)) {
            return [];
        }
        $out = [];
        $servers = Server::whereIn('id', $serverIds)->get()->append(['last_check_at', 'is_online', 'online']);
        foreach ($servers as $server) {
            $cap = (int) ($server->transfer_enable ?? 0);
            $overCap = $cap > 0 && ((int) $server->u + (int) $server->d) >= $cap;
            $out[(int) $server->id] = [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'type' => (string) $server->type,
                'host' => (string) $server->host,
                'port' => (string) $server->port,
                'server_port' => (int) $server->server_port,
                'machine_id' => $server->machine_id !== null ? (int) $server->machine_id : null,
                'parent_id' => $server->parent_id !== null ? (int) $server->parent_id : null,
                'group_ids' => array_values(array_map('intval', (array) ($server->group_ids ?? []))),
                'show' => (bool) $server->show,
                'enabled' => $server->enabled,
                'sort' => $server->sort,
                'rate' => $server->getCurrentRate(),
                'rate_signature' => self::rateSignature($server),
                'last_check_at' => $server->last_check_at,
                'is_online' => (int) $server->is_online,
                'online' => (int) $server->online,
                'over_cap' => $overCap,
                'transport' => data_get($server->protocol_settings, 'transport'),
                'available' => (bool) $server->show && !$overCap,
            ];
        }
        return $out;
    }

    /** 只保留可下发的（show=1、未超流量上限）—— 作为 MemberPool 的 availableById */
    public static function availableOnly(array $snapshot): array
    {
        return array_filter($snapshot, fn($s) => $s['available']);
    }

    /**
     * 倍率配置签名：基础倍率 + 分时倍率配置。组内成员必须一致，否则组名上的倍率会与实际计费不符。
     */
    public static function rateSignature(Server $server): string
    {
        $ranges = $server->rate_time_enable ? ($server->rate_time_ranges ?? []) : [];
        return json_encode([
            'rate' => round((float) $server->rate, 4),
            'time' => (bool) $server->rate_time_enable,
            'ranges' => $ranges,
        ]);
    }

    /** 是否能做 TCP 探测：UDP 协议 / 端口段跳跃 / mieru-UDP 不探测 */
    public static function probeable(array $server): bool
    {
        if (in_array($server['type'], ['hysteria', 'tuic'], true)) {
            return false;
        }
        if ($server['type'] === 'mieru' && strtoupper((string) ($server['transport'] ?? 'TCP')) !== 'TCP') {
            return false;
        }
        return ctype_digit((string) $server['port']) && (int) $server['port'] > 0 && $server['host'] !== '';
    }
}
