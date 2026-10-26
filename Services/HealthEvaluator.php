<?php

namespace Plugin\SubscribeLb\Services;

/**
 * 成员健康判定（纯函数，不读写任何存储）。
 *
 * 三类信号：
 *  1. 人工/外部覆盖 health_override：down=强制不可用（最高优先级，任何兜底都不放宽）；up=强制可用（跳过自动信号）
 *  2. 面板心跳（自动）：节点后端（xboard-node）最近一次心跳 ≤300 秒视为在线，与面板 is_online 同口径；
 *     恢复规则：心跳一回来，下一次订阅请求就重新纳入新分配
 *  3. TCP 探测（自动，sub-lb:check 每分钟写入）：连续失败 2 轮判不可用；不可用后连续成功 3 轮才恢复（滞回）；
 *     巡检停摆超过 10 分钟，探测结果作废（视为无信号，fail-open），防止监控自己挂了把成员永久判死
 *
 * 组的 health_mode 决定用哪些自动信号：heartbeat | probe | heartbeat+probe | none。
 */
final class HealthEvaluator
{
    public const MODES = [
        'heartbeat' => '面板心跳',
        'probe' => 'TCP 探测',
        'heartbeat+probe' => '心跳 + 探测',
        'none' => '不做自动判定',
    ];

    public const HEARTBEAT_WINDOW = 300;   // 与 Server::CHECK_INTERVAL / is_online 一致
    public const PROBE_STALE_AFTER = 600;  // 巡检超过 10 分钟未更新 → 探测信号作废
    public const PROBE_DOWN_AFTER = 2;     // 连续失败 2 轮判不可用
    public const PROBE_UP_AFTER = 3;       // 不可用后连续成功 3 轮恢复

    /**
     * @return array{auto_ok: bool, reason: ?string, signals: array{heartbeat: string, probe: string}}
     */
    public static function evaluate(array $group, array $member, array $server, int $now): array
    {
        $mode = self::normalizeMode($group['health_mode'] ?? null);
        $signals = [
            'heartbeat' => self::heartbeat($server, $now),
            'probe' => self::probe($member, $now),
        ];
        $autoOk = true;
        $reason = null;
        if (($mode === 'heartbeat' || $mode === 'heartbeat+probe') && $signals['heartbeat'] === 'down') {
            $autoOk = false;
            $reason = 'heartbeat_lost';
        }
        if ($autoOk && ($mode === 'probe' || $mode === 'heartbeat+probe') && $signals['probe'] === 'down') {
            $autoOk = false;
            $reason = 'probe_failed';
        }
        return ['auto_ok' => $autoOk, 'reason' => $reason, 'signals' => $signals];
    }

    public static function normalizeMode(?string $mode): string
    {
        return isset(self::MODES[$mode]) ? $mode : 'heartbeat';
    }

    /** up | down（与 Server::isOnline 同口径：最近心跳距今 ≤300 秒） */
    public static function heartbeat(array $server, int $now): string
    {
        $last = (int) ($server['last_check_at'] ?? 0);
        return ($last > 0 && ($now - $last) <= self::HEARTBEAT_WINDOW) ? 'up' : 'down';
    }

    /** up | down | unknown（从未探测） | stale（巡检停摆，结果作废） */
    public static function probe(array $member, int $now): string
    {
        $state = $member['probe_state'] ?? null;
        if ($state !== 'up' && $state !== 'down') {
            return 'unknown';
        }
        $at = (int) ($member['probe_checked_at'] ?? 0);
        if ($at <= 0 || ($now - $at) > self::PROBE_STALE_AFTER) {
            return 'stale';
        }
        return $state;
    }

    /**
     * 探测结果的滞回状态机（sub-lb:check 用）。
     * @return array{state: ?string, ok_streak: int, fail_streak: int, changed: bool}
     */
    public static function nextProbeState(?string $state, int $okStreak, int $failStreak, bool $ok): array
    {
        if ($ok) {
            $okStreak++;
            $failStreak = 0;
        } else {
            $failStreak++;
            $okStreak = 0;
        }
        $next = $state;
        if ($state === 'down') {
            if ($okStreak >= self::PROBE_UP_AFTER) {
                $next = 'up';
            }
        } elseif ($failStreak >= self::PROBE_DOWN_AFTER) {
            $next = 'down';
        } elseif ($ok) {
            $next = 'up';
        }
        return ['state' => $next, 'ok_streak' => $okStreak, 'fail_streak' => $failStreak, 'changed' => $next !== $state];
    }
}
