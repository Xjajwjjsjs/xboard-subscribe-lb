<?php

namespace Plugin\SubscribeLb\Commands;

use Plugin\SubscribeLb\Services\AlertGate;
use Plugin\SubscribeLb\Services\AssignmentStore;
use Plugin\SubscribeLb\Services\DnsResolver;
use Plugin\SubscribeLb\Services\EntryChildSync;
use Plugin\SubscribeLb\Services\EventLog;
use Plugin\SubscribeLb\Services\GroupInspector;
use Plugin\SubscribeLb\Services\HealthEvaluator;
use Plugin\SubscribeLb\Services\LoadBalancer;
use Plugin\SubscribeLb\Services\MemberPool;
use Plugin\SubscribeLb\Services\ServerSnapshot;
use Plugin\SubscribeLb\Services\Tables;
use Plugin\SubscribeLb\Services\Settings;
use Plugin\SubscribeLb\Services\TcpProber;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 负载均衡巡检（Kernel 每分钟调度）：
 *  1. 对 health_mode 含 probe 的组做并发 TCP 探测，按滞回规则更新成员 probe_state
 *  2. 记录心跳状态切换（下发时心跳是实时判断的，这里只为事件/告警）
 *  3. 计算组级结论（ok/degraded/draining_only/failopen/empty），状态切换写事件；TG 告警经 AlertGate 防抖
 *  4. 清理过期事件与长期不活跃用户的分配
 *  5. 写 sub_lb:last_check_at —— 后台页面据此显示「巡检多久没跑了」；探测结果超过 10 分钟没更新会被下发逻辑作废
 *
 * 本命令挂了不会让成员被误判：下发路径不依赖它的心跳判断，探测结果过期即作废（fail-open）。
 */
class LbCheckCommand extends Command
{
    protected $signature = 'sub-lb:check {--no-probe : 跳过 TCP 探测} {--no-alert : 不发 TG 告警}';

    protected $description = '订阅负载均衡巡检：探测成员、更新健康状态、组状态告警、清理';

    private const PROBE_TIMEOUT = 4.0;
    private const EVENT_RETENTION = 14 * 86400;
    private const ASSIGNMENT_RETENTION_MIN = 30 * 86400;

    public function handle(): int
    {
        if (!Tables::installed()) {
            $this->line('负载均衡数据表未安装，跳过');
            return self::SUCCESS;
        }
        // 互斥：调度（onOneServer/withoutOverlapping）之外手动执行时，避免两轮巡检并发导致探测连胜/连败计数丢失更新
        $lock = null;
        try {
            $lock = Cache::lock('sub_lb:check_lock', 120);
            if (!$lock->get()) {
                $this->line('另一轮 sub-lb:check 正在运行，跳过');
                return self::SUCCESS;
            }
        } catch (\Throwable $e) {
            $lock = null; // 缓存不可用时照常执行（宁可偶发计数偏差，也不让巡检停摆）
        }
        try {
            return $this->runCheck();
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable $e) {
            }
        }
    }

    private function runCheck(): int
    {
        $now = time();
        // 0. 域名入口解析（只处理到期的组；失败保留现有成员）—— 先于探测，让新解析出的 IP 本轮就被探测
        $resolveLines = [];
        $resolveAlerts = [];
        try {
            foreach (DnsResolver::runDue($now) as $r) {
                if ($r['error']) {
                    $resolveAlerts[] = ['key' => 'resolve:' . $r['group_id'], 'cooldown' => 1800,
                        'text' => "★ 订阅负载均衡组「{$r['name']}」域名解析失败\r\n{$r['error']}"];
                } elseif ($r['changed']) {
                    $resolveLines = array_merge($resolveLines, $r['lines']);
                    $resolveAlerts[] = ['key' => 'resolve-changed:' . $r['group_id'], 'cooldown' => 600,
                        'text' => "🔁 订阅负载均衡组「{$r['name']}」入口 IP 变化\r\n" . implode("\r\n", $r['lines'])
                            . "\r\n已停用的 IP 上的用户下次更新订阅时改分配"];
                }
            }
        } catch (\Throwable $e) {
            $this->warn('resolve skipped: ' . $e->getMessage()); // 002 迁移未执行等
        }
        // 0b. 入口子节点跟随承载节点同步配置（倍率/端口/协议参数/权限组…）
        try {
            $resolveLines = array_merge($resolveLines, EntryChildSync::syncAll($now));
        } catch (\Throwable $e) {
            $this->warn('entry child sync failed: ' . $e->getMessage());
        }
        try {
            $all = GroupInspector::loadAll();
        } catch (\Throwable $e) {
            EventLog::record('check_failed', EventLog::CRITICAL, null, null, ['error' => $e->getMessage()], 'check_failed', 1800);
            $this->tgAlert('check_failed', "★ 订阅负载均衡巡检失败\r\n错误：{$e->getMessage()}", 1800);
            return self::FAILURE;
        }

        $lines = [];
        if (!$this->option('no-probe')) {
            $lines = array_merge($lines, $this->probeMembers($all, $now));
            $all = GroupInspector::loadAll(); // 用刚写入的探测结果重新评估
        }
        $lines = array_merge($resolveLines, $lines, $this->heartbeatTransitions($all, $now));
        $alerts = array_merge($resolveAlerts, $this->groupStates($all, $now));

        $this->housekeeping($all, $now);
        try {
            Cache::put('sub_lb:last_check_at', $now, 86400);
        } catch (\Throwable $e) {
        }

        // 每条告警只附本组的成员情况（以前附的是本轮所有组的切换明细，抖动时一并刷屏）
        foreach ($alerts as $alert) {
            $body = $alert['text'];
            if (!empty($alert['lines'])) {
                $body .= "\r\n----\r\n" . implode("\r\n", array_slice($alert['lines'], 0, 15));
            }
            $this->tgAlert($alert['key'], $body, $alert['cooldown']);
        }
        $this->info(sprintf('sub-lb:check done: groups=%d members=%d transitions=%d alerts=%d',
            count($all['groups']), count($all['members']), count($lines), count($alerts)));
        foreach ($lines as $line) {
            $this->line('  ' . $line);
        }
        return self::SUCCESS;
    }

    /** @return string[] 状态切换说明 */
    private function probeMembers(array $all, int $now): array
    {
        $targets = [];
        $memberById = [];
        foreach ($all['groups'] as $group) {
            $mode = HealthEvaluator::normalizeMode($group['health_mode']);
            if (!in_array($mode, ['probe', 'heartbeat+probe'], true)) {
                continue;
            }
            foreach ($group['members'] as $m) {
                $s = $all['snapshot'][(int) $m['server_id']] ?? null;
                if ($s === null || !ServerSnapshot::probeable($s) || $m['status'] === 'disabled') {
                    continue;
                }
                $targets[(int) $m['id']] = ['host' => $s['host'], 'port' => (int) $s['port']];
                $memberById[(int) $m['id']] = $m + ['_group' => $group['name']];
            }
        }
        if (empty($targets)) {
            return [];
        }
        $results = TcpProber::probe($targets, self::PROBE_TIMEOUT);
        $lines = [];
        foreach ($results as $mid => $r) {
            $m = $memberById[$mid];
            $next = HealthEvaluator::nextProbeState($m['probe_state'], (int) $m['probe_ok_streak'], (int) $m['probe_fail_streak'], $r['ok']);
            $update = [
                'probe_state' => $next['state'],
                'probe_ok_streak' => $next['ok_streak'],
                'probe_fail_streak' => $next['fail_streak'],
                'probe_checked_at' => $now,
                'probe_detail' => $r['ok'] ? ($r['ms'] . 'ms') : $r['error'],
            ];
            if ($next['changed']) {
                $update['probe_changed_at'] = $now;
            }
            try {
                DB::table(Tables::MEMBERS)->where('id', $mid)->update($update);
            } catch (\Throwable $e) {
                $this->warn("member {$mid} probe update failed: " . $e->getMessage());
                continue;
            }
            // null→up 是首次探测成功，不算切换
            if ($next['changed'] && !($m['probe_state'] === null && $next['state'] === 'up')) {
                $txt = sprintf('「%s」成员 #%d 探测 %s → %s（%s）', $m['_group'], $m['server_id'],
                    $m['probe_state'] ?? '未知', $next['state'], $update['probe_detail']);
                $lines[] = $txt;
                EventLog::record($next['state'] === 'down' ? 'probe_down' : 'probe_up',
                    $next['state'] === 'down' ? EventLog::WARNING : EventLog::INFO,
                    (int) $m['group_id'], (int) $m['server_id'], $txt);
            }
        }
        return $lines;
    }

    /** @return string[] */
    private function heartbeatTransitions(array $all, int $now): array
    {
        $lines = [];
        foreach ($all['groups'] as $group) {
            $mode = HealthEvaluator::normalizeMode($group['health_mode']);
            $uses = in_array($mode, ['heartbeat', 'heartbeat+probe'], true);
            foreach ($group['members'] as $m) {
                $s = $all['snapshot'][(int) $m['server_id']] ?? null;
                $hb = $s === null ? 'down' : HealthEvaluator::heartbeat($s, $now);
                if ($hb === $m['hb_state']) {
                    continue;
                }
                try {
                    DB::table(Tables::MEMBERS)->where('id', (int) $m['id'])->update(['hb_state' => $hb]);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($m['hb_state'] === null || !$uses || $m['status'] === 'disabled') {
                    continue; // 首次记录 / 本组不用心跳 / 已停用：只落状态不报
                }
                $txt = sprintf('「%s」成员 #%d 面板心跳 %s → %s', $group['name'], $m['server_id'], $m['hb_state'], $hb);
                $lines[] = $txt;
                EventLog::record($hb === 'down' ? 'heartbeat_down' : 'heartbeat_up',
                    $hb === 'down' ? EventLog::WARNING : EventLog::INFO,
                    $group['id'], (int) $m['server_id'], $txt);
            }
        }
        return $lines;
    }

    private const STATE_NOTES = [
        'degraded' => '分到不可用成员的用户更新订阅后才会切到其他成员',
        'draining_only' => '新分配暂由维护中成员承接',
        'failopen' => '仍照常下发全部成员（同时判死多半是探测侧/线路问题；单成员组本来也无处可切）',
        'empty' => '该组暂不下发，用户订阅里看不到它',
    ];

    private const MEMBER_REASONS = [
        'heartbeat_lost' => '心跳丢失', 'probe_failed' => '探测失败', 'unhealthy' => '不健康',
        'server_unavailable' => '服务器不可下发（已删/隐藏/超流量上限）', 'parent_unavailable' => '承载节点不可下发',
        'parent_disabled' => '承载节点已禁用', 'server_disabled' => '节点已禁用', 'override_down' => '强制不可用',
    ];

    /**
     * 组级结论：每次切换照旧写事件；TG 经 AlertGate 防抖（持续异常才推、恢复只跟在推过的异常后面、抖动只推汇总）。
     * @return array<int, array{key: string, text: string, cooldown: int, lines: string[]}>
     */
    private function groupStates(array $all, int $now): array
    {
        $alerts = [];
        foreach ($all['groups'] as $group) {
            $insp = GroupInspector::inspect($group, $all['snapshot'], $now);
            $state = $insp['state'];
            $prev = $group['state'];
            $summary = $insp['pool']->summary();
            $detail = sprintf('健康活跃 %d/%d，维护中健康 %d/%d，不可用 %d',
                $summary['active_healthy'], $summary['active'], $summary['draining_healthy'], $summary['draining'], $summary['blocked']);
            $update = ['state' => $state, 'state_detail' => $detail, 'checked_at' => $now];
            $changedAt = (int) ($group['state_changed_at'] ?? 0);
            if ($state !== $prev) {
                $update['state_changed_at'] = $changedAt = $now;
            }
            try {
                DB::table(Tables::GROUPS)->where('id', $group['id'])->update($update);
            } catch (\Throwable $e) {
                continue;
            }
            $label = GroupInspector::STATES[$state] ?? $state;
            if ($state !== $prev && $prev !== null && $state !== 'disabled' && $prev !== 'disabled') {
                $level = in_array($state, ['empty', 'failopen'], true) ? EventLog::CRITICAL
                    : ($state === 'ok' ? EventLog::INFO : EventLog::WARNING);
                EventLog::record('group_state', $level, $group['id'], null,
                    ['from' => $prev, 'to' => $state, 'detail' => $detail]);
                $flaps = $this->recentStateChanges($group['id'], $now);
                if ($flaps >= AlertGate::FLAP_THRESHOLD) {
                    $alerts[] = [
                        'key' => 'flap:' . $group['id'],
                        'text' => sprintf("〰️ 订阅负载均衡组「%s」状态抖动：近 1 小时切换 %d 次\r\n当前：%s，%s\r\n抖动期间不逐条推送，持续异常 ≥%d 分钟才告警；本提示 %d 小时内不重复",
                            $group['name'], $flaps, $label, $detail, AlertGate::CONFIRM_BAD / 60, AlertGate::FLAP_COOLDOWN / 3600),
                        'cooldown' => AlertGate::FLAP_COOLDOWN,
                        'lines' => $this->memberLines($insp['pool']),
                    ];
                }
            }

            $mem = $this->alertMemory($group['id']);
            $gate = AlertGate::next($mem, $state, $changedAt, $now);
            if ($gate['mem'] !== $mem) {
                $this->saveAlertMemory($group['id'], $gate['mem']);
            }
            $send = $gate['send'];
            if ($send === null) {
                continue;
            }
            $from = GroupInspector::STATES[$gate['from']] ?? $gate['from'];
            if ($send === 'ok') {
                $text = sprintf("✅ 订阅负载均衡组「%s」已恢复正常\r\n%s → 正常（已稳定 %d 分钟）\r\n%s",
                    $group['name'], $from, intdiv($now - $changedAt, 60), $detail);
            } else {
                $since = $gate['from'] === 'ok' ? (int) $gate['mem']['bad_since'] : $changedAt;
                $critical = in_array($send, ['empty', 'failopen'], true);
                $text = sprintf("%s 订阅负载均衡组「%s」：%s\r\n%s → %s（已持续 %d 分钟）\r\n%s%s", $critical ? '★' : '⚠️',
                    $group['name'], $label, $from, $label, intdiv($now - $since, 60), $detail,
                    isset(self::STATE_NOTES[$send]) ? "\r\n注意：" . self::STATE_NOTES[$send] : '');
            }
            $alerts[] = [
                'key' => 'group:' . $group['id'] . ':' . $send,
                'text' => $text,
                'cooldown' => 60, // 防抖由 AlertGate 负责，这里只防同一分钟重复执行
                'lines' => $send === 'ok' ? [] : $this->memberLines($insp['pool']),
            ];
        }
        return $alerts;
    }

    private function recentStateChanges(int $groupId, int $now): int
    {
        try {
            return DB::table(Tables::EVENTS)->where('group_id', $groupId)->where('type', 'group_state')
                ->where('created_at', '>', $now - AlertGate::FLAP_WINDOW)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return string[] 不健康成员（停用成员是人为设置的，不列） */
    private function memberLines(MemberPool $pool): array
    {
        $lines = [];
        foreach ($pool->entries as $sid => $e) {
            if ($e['healthy'] || $e['blocked'] === 'member_disabled') {
                continue;
            }
            $why = $e['blocked'] ?? $e['health_reason'] ?? 'unhealthy';
            $probe = $why === 'probe_failed' ? ($e['member']['probe_detail'] ?? '') : '';
            $lines[] = sprintf('成员 #%d：%s%s', $sid, self::MEMBER_REASONS[$why] ?? $why, $probe !== '' ? "（{$probe}）" : '');
        }
        return $lines;
    }

    /** 防抖记忆存缓存：丢了也只是少推一次恢复（AlertGate 把无记忆当作「当前状态已推过」） */
    private function alertMemory(int $groupId): ?array
    {
        try {
            $mem = Cache::get('sub_lb:alert_gate:' . $groupId);
            return is_array($mem) ? $mem : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saveAlertMemory(int $groupId, array $mem): void
    {
        try {
            Cache::put('sub_lb:alert_gate:' . $groupId, $mem, 30 * 86400);
        } catch (\Throwable $e) {
        }
    }

    private function housekeeping(array $all, int $now): void
    {
        $maxInterval = 0;
        foreach ($all['groups'] as $g) {
            $maxInterval = max($maxInterval, LoadBalancer::clampInterval((int) $g['interval_seconds']));
        }
        try {
            DB::table(Tables::EVENTS)->where('created_at', '<', $now - self::EVENT_RETENTION)->delete();
            (new AssignmentStore())->prune($now - max(self::ASSIGNMENT_RETENTION_MIN, 2 * $maxInterval));
        } catch (\Throwable $e) {
            $this->warn('housekeeping failed: ' . $e->getMessage());
        }
    }

    /** TG 告警（同 key 冷却期内只发一次） */
    private function tgAlert(string $key, string $message, int $cooldown): void
    {
        if ($this->option('no-alert') || !Settings::bool('tg_alert', true)) {
            $this->line('[alert suppressed] ' . str_replace("\r\n", ' | ', $message));
            return;
        }
        if (!EventLog::acquire('tg:' . $key, $cooldown)) {
            return;
        }
        try {
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Throwable $e) {
            EventLog::record('tg_failed', EventLog::WARNING, null, null, ['error' => $e->getMessage()], 'tg_failed', 1800);
        }
    }
}
