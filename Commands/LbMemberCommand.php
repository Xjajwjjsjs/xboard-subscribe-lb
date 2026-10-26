<?php

namespace Plugin\SubscribeLb\Commands;

use Plugin\SubscribeLb\Services\EventLog;
use Plugin\SubscribeLb\Services\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 外部探测 / 运维脚本上报成员健康（按 v2_server.id 定位成员，一台节点只属于一个组）：
 *
 *   php artisan sub-lb:member 12 down --note="机器维护" --by=my-monitor
 *   php artisan sub-lb:member 12 auto
 *
 * down：立即生效——该成员上的用户下次更新订阅时提前改分配，且不再接新分配；
 * up：强制可用（无视心跳/探测，适合没有接节点后端的成员）；auto：恢复跟随自动信号。
 * 也可以用 --status=active|draining|disabled 调整成员状态。
 */
class LbMemberCommand extends Command
{
    protected $signature = 'sub-lb:member {server_id : v2_server.id} {override? : auto|down|up}
        {--status= : active|draining|disabled} {--note= : 备注} {--by=cli : 来源标识}';

    protected $description = '订阅负载均衡：设置成员健康覆盖 / 成员状态';

    public function handle(): int
    {
        if (!Tables::installed()) {
            $this->error('负载均衡数据表未安装');
            return self::FAILURE;
        }
        $sid = (int) $this->argument('server_id');
        $member = DB::table(Tables::MEMBERS)->where('server_id', $sid)->first();
        if (!$member) {
            $this->error("节点 #{$sid} 不在任何负载均衡组里");
            return self::FAILURE;
        }
        $override = $this->argument('override');
        $status = $this->option('status');
        if ($override === null && $status === null) {
            $this->line(json_encode($member, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $now = time();
        $by = 'cli:' . mb_substr((string) $this->option('by'), 0, 40);
        $update = ['updated_at' => $now];
        if ($override !== null) {
            if (!in_array($override, ['auto', 'down', 'up'], true)) {
                $this->error('override 只能是 auto|down|up');
                return self::FAILURE;
            }
            $update += [
                'health_override' => $override === 'auto' ? null : $override,
                'health_note' => $this->option('note'),
                'health_override_at' => $now,
                'health_override_by' => $by,
            ];
        }
        if ($status !== null) {
            if (!in_array($status, ['active', 'draining', 'disabled'], true)) {
                $this->error('status 只能是 active|draining|disabled');
                return self::FAILURE;
            }
            $update['status'] = $status;
        }
        DB::table(Tables::MEMBERS)->where('id', $member->id)->update($update);
        DB::table(Tables::GROUPS)->where('id', $member->group_id)->update(['updated_at' => $now]);
        EventLog::record('member_cli', ($update['health_override'] ?? null) === 'down' || $status === 'disabled'
            ? EventLog::WARNING : EventLog::INFO, (int) $member->group_id, $sid,
            ['override' => $override, 'status' => $status, 'note' => $this->option('note'), 'by' => $by]);
        $this->info("节点 #{$sid} 已更新：" . json_encode(array_diff_key($update, ['updated_at' => 1]), JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
