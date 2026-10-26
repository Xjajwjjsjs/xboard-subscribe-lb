<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\DB;

/**
 * 分配状态存储（数据库，跨重启、跨 Octane worker / 多实例共享）。
 *
 * 并发控制不用事务锁，用两条单语句原子操作：
 *  - 首次分配：insertOrIgnore（SQLite INSERT OR IGNORE / MySQL INSERT IGNORE / PG ON CONFLICT DO NOTHING）—— 两个请求同时首抽，只有一个写进去，另一个读回赢家的结果
 *  - 重新分配：UPDATE ... WHERE version = 旧版本 —— 乐观锁（CAS），输的一方读回赢家的结果
 * 都是 autocommit 单语句（SQLite WAL 下也不会触发「读事务升级写」的 BUSY_SNAPSHOT）。
 *
 * 所有方法失败直接抛异常，由 LoadBalancer 决定降级。
 */
final class AssignmentStore
{
    /** @return array<int, array> group_id => 行 */
    public function forUser(int $userId): array
    {
        $out = [];
        foreach (DB::table(Tables::ASSIGNMENTS)->where('user_id', $userId)->get() as $row) {
            $out[(int) $row->group_id] = (array) $row;
        }
        return $out;
    }

    public function find(int $userId, int $groupId): ?array
    {
        $row = DB::table(Tables::ASSIGNMENTS)->where('user_id', $userId)->where('group_id', $groupId)->first();
        return $row ? (array) $row : null;
    }

    /** 首次分配；返回 false 表示别的请求已抢先写入 */
    public function create(int $userId, int $groupId, int $serverId, int $now, string $reason): bool
    {
        return DB::table(Tables::ASSIGNMENTS)->insertOrIgnore([
            'user_id' => $userId,
            'group_id' => $groupId,
            'server_id' => $serverId,
            'assigned_at' => $now,
            'version' => 1,
            'reason' => $reason,
            'previous_server_id' => null,
        ]) === 1;
    }

    /** 重新分配（CAS）；返回 false 表示行已被别的请求改过或删除 */
    public function replace(array $current, int $serverId, int $now, string $reason): bool
    {
        return DB::table(Tables::ASSIGNMENTS)
            ->where('user_id', (int) $current['user_id'])
            ->where('group_id', (int) $current['group_id'])
            ->where('version', (int) $current['version'])
            ->update([
                'server_id' => $serverId,
                'assigned_at' => $now,
                'version' => (int) $current['version'] + 1,
                'reason' => mb_substr($reason, 0, 64),
                'previous_server_id' => (int) $current['server_id'],
            ]) === 1;
    }

    /** 后台「重新分配」：删除组内（或某成员上）的分配，用户下次更新订阅时重新随机 */
    public function reset(int $groupId, ?int $serverId = null): int
    {
        $q = DB::table(Tables::ASSIGNMENTS)->where('group_id', $groupId);
        if ($serverId !== null) {
            $q->where('server_id', $serverId);
        }
        return $q->delete();
    }

    /**
     * 组内每个成员当前「有效期内」的分配人数（到期未刷新的不计）。
     * @return array<int, int> server_id => 人数
     */
    public function activeCounts(int $groupId, int $since): array
    {
        $out = [];
        $rows = DB::table(Tables::ASSIGNMENTS)
            ->select('server_id', DB::raw('COUNT(*) AS n'))
            ->where('group_id', $groupId)
            ->where('assigned_at', '>', $since)
            ->groupBy('server_id')
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->server_id] = (int) $row->n;
        }
        return $out;
    }

    /**
     * 近期重新分配原因统计（后台观察故障转移）。
     * @return array<string, int>
     */
    public function recentReasons(int $groupId, int $since): array
    {
        $out = [];
        $rows = DB::table(Tables::ASSIGNMENTS)
            ->select('reason', DB::raw('COUNT(*) AS n'))
            ->where('group_id', $groupId)
            ->where('assigned_at', '>', $since)
            ->groupBy('reason')
            ->get();
        foreach ($rows as $row) {
            $out[(string) $row->reason] = (int) $row->n;
        }
        return $out;
    }

    /** 清理：长期不活跃用户的分配 + 已删除组的残留 */
    public function prune(int $olderThan): int
    {
        $n = DB::table(Tables::ASSIGNMENTS)->where('assigned_at', '<', $olderThan)->delete();
        $n += DB::table(Tables::ASSIGNMENTS)
            ->whereNotIn('group_id', DB::table(Tables::GROUPS)->select('id'))
            ->delete();
        return $n;
    }
}
