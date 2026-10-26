<?php

namespace Plugin\SubscribeLb\Commands;

use Plugin\SubscribeLb\Services\AssignmentStore;
use Plugin\SubscribeLb\Services\GroupInspector;
use Plugin\SubscribeLb\Services\LoadBalancer;
use Plugin\SubscribeLb\Services\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 命令行查看负载均衡组状态（不改任何数据）：php artisan sub-lb:status
 */
class LbStatusCommand extends Command
{
    protected $signature = 'sub-lb:status';

    protected $description = '订阅负载均衡：打印各组成员健康与分配人数（只读）';

    public function handle(): int
    {
        if (!Tables::installed()) {
            $this->warn('负载均衡数据表未安装（模块不生效）');
            return self::SUCCESS;
        }
        $now = time();
        $all = GroupInspector::loadAll();
        $store = new AssignmentStore();
        $last = null;
        try {
            $last = Cache::get('sub_lb:last_check_at');
        } catch (\Throwable $e) {
        }
        $this->line('last check: ' . ($last ? ($now - (int) $last) . 's ago' : 'never'));
        foreach ($all['groups'] as $group) {
            $insp = GroupInspector::inspect($group, $all['snapshot'], $now);
            $interval = LoadBalancer::clampInterval((int) $group['interval_seconds']);
            $counts = $store->activeCounts($group['id'], $now - $interval);
            $this->line(sprintf('#%d 「%s」 enabled=%d state=%s tier=%s interval=%ds strategy=%s health=%s',
                $group['id'], $group['name'], $group['enabled'], $insp['state'], $insp['pool']->tier,
                $interval, $group['strategy'], $group['health_mode']));
            $rows = [];
            foreach ($group['members'] as $m) {
                $sid = (int) $m['server_id'];
                $e = $insp['pool']->entries[$sid] ?? null;
                $s = $all['snapshot'][$sid] ?? null;
                $rows[] = [
                    $sid,
                    $s ? mb_substr($s['name'], 0, 24) : '(已删除)',
                    $s ? "{$s['type']} {$s['host']}:{$s['port']}" : '-',
                    $s ? $s['rate'] : '-',
                    $m['status'],
                    $m['health_override'] ?? 'auto',
                    $e && $e['signals'] ? "hb={$e['signals']['heartbeat']} probe={$e['signals']['probe']}" : '-',
                    $e ? ($e['blocked'] ?? ($e['healthy'] ? 'ok' : $e['health_reason'])) : '-',
                    $counts[$sid] ?? 0,
                ];
            }
            $this->table(['server', 'name', 'endpoint', 'rate', 'status', 'override', 'signals', 'eval', 'assigned'], $rows);
            foreach ($insp['warnings'] as $w) {
                $this->warn('  ! ' . $w);
            }
        }
        return self::SUCCESS;
    }
}
