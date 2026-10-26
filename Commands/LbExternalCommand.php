<?php

namespace Plugin\SubscribeLb\Commands;

use Plugin\SubscribeLb\Services\EventLog;
use Plugin\SubscribeLb\Services\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 外部监控按「IP 结论」同步成员健康（如你自己的可达性监控）：
 *
 *   php artisan sub-lb:external my-monitor --port=443 --up=203.0.113.10,203.0.113.11 --down=203.0.113.12 --note="监控判定不可达"
 *
 * 只作用于「是负载均衡成员、host 等于该 IP（且端口匹配）」的节点。
 * 所有权规则：外部来源只会把「自动」改成「强制不可用」，也只会撤销**自己**设的强制不可用；
 * 管理员在后台/CLI 手动设的覆盖一律不动。每次调用都是全量结论，可重复执行（幂等）。
 */
class LbExternalCommand extends Command
{
    protected $signature = 'sub-lb:external {source : 来源标识，如 my-monitor}
        {--port= : 只匹配该端口的成员} {--up= : 判定可用的 IP，逗号分隔} {--down= : 判定不可用的 IP，逗号分隔}
        {--note= : 不可用原因}';

    protected $description = '订阅负载均衡：外部监控按 IP 同步成员健康（只改自己设的覆盖）';

    public function handle(): int
    {
        if (!Tables::installed()) {
            $this->line('负载均衡数据表未安装，跳过');
            return self::SUCCESS;
        }
        $source = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $this->argument('source'));
        if ($source === '') {
            $this->error('source 不能为空');
            return self::FAILURE;
        }
        $owner = 'cli:' . $source;
        $up = $this->ips($this->option('up'));
        $down = $this->ips($this->option('down'));
        if (array_intersect($up, $down)) {
            $this->error('同一个 IP 不能同时出现在 --up 和 --down');
            return self::FAILURE;
        }
        $hosts = array_merge($up, $down);
        if (empty($hosts)) {
            $this->line('没有给出任何 IP');
            return self::SUCCESS;
        }

        $q = DB::table(Tables::MEMBERS . ' as m')
            ->join('v2_server as s', 's.id', '=', 'm.server_id')
            ->whereIn('s.host', $hosts)
            ->select('m.id', 'm.group_id', 'm.server_id', 'm.health_override', 'm.health_override_by', 's.host', 's.port');
        if ($this->option('port') !== null) {
            $q->where('s.port', trim((string) $this->option('port'))); // 按字符串精确匹配，兼容 hy2 端口段如 41000-50000
        }
        $now = time();
        $changed = 0;
        foreach ($q->get() as $m) {
            $wantDown = in_array($m->host, $down, true);
            $mine = $m->health_override_by === $owner;
            if ($wantDown && $m->health_override === null) {
                DB::table(Tables::MEMBERS)->where('id', $m->id)->update([
                    'health_override' => 'down',
                    'health_note' => mb_substr((string) ($this->option('note') ?? '外部监控判定不可用'), 0, 255),
                    'health_override_at' => $now,
                    'health_override_by' => $owner,
                    'updated_at' => $now,
                ]);
                EventLog::record('external_down', EventLog::WARNING, (int) $m->group_id, (int) $m->server_id,
                    ['source' => $source, 'host' => $m->host, 'note' => $this->option('note')]);
                $this->line("down  #{$m->server_id} {$m->host}:{$m->port}");
                $changed++;
            } elseif (!$wantDown && $m->health_override === 'down' && $mine) {
                DB::table(Tables::MEMBERS)->where('id', $m->id)->update([
                    'health_override' => null,
                    'health_note' => null,
                    'health_override_at' => $now,
                    'health_override_by' => $owner,
                    'updated_at' => $now,
                ]);
                EventLog::record('external_up', EventLog::INFO, (int) $m->group_id, (int) $m->server_id,
                    ['source' => $source, 'host' => $m->host]);
                $this->line("up    #{$m->server_id} {$m->host}:{$m->port}");
                $changed++;
            }
        }
        $this->info("sub-lb:external {$source}: changed={$changed}");
        return self::SUCCESS;
    }

    private function ips(?string $raw): array
    {
        $out = [];
        foreach (explode(',', (string) $raw) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $out[] = $ip;
            }
        }
        return array_values(array_unique($out));
    }
}
