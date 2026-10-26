<?php

namespace Plugin\SubscribeLb\Services;

/**
 * 组状态 TG 告警防抖（纯函数，不读写任何存储；sub-lb:check 用，记忆由调用方存缓存）。
 *
 * 防刷屏：成员探测时好时坏时，每次切换都推一对「异常/恢复」会刷屏。现在：
 *  - 异常：组连续不正常 ≥ CONFIRM_BAD 才推；推过之后换成另一种异常，新状态也要持续 ≥ CONFIRM_BAD 才再推
 *  - 恢复：只有推过异常才推，且正常持续 ≥ CONFIRM_OK
 *  - 抖动：近 FLAP_WINDOW 内切换 ≥ FLAP_THRESHOLD 次，单独推一条汇总（调用方按组冷却 FLAP_COOLDOWN）
 *  - 没有记忆（首次上线/缓存丢失）：当作当前状态已推过，只落记忆不推，避免上线瞬间补发一批
 * 状态切换本身仍每次写事件（后台「最近事件」可查），只是不再逐条推 TG。
 */
final class AlertGate
{
    public const CONFIRM_BAD = 600;    // 不正常持续 10 分钟才推
    public const CONFIRM_OK = 600;     // 恢复后正常持续 10 分钟才推恢复
    public const FLAP_WINDOW = 3600;
    public const FLAP_THRESHOLD = 4;   // 1 小时切换 4 次 = 坏/好来回两轮
    public const FLAP_COOLDOWN = 21600;

    /**
     * @param array{alerted: ?string, bad_since: ?int}|null $mem 上一轮记忆（null = 没有记忆）
     * @param int $stateChangedAt 当前状态开始的时间
     * @return array{mem: array{alerted: ?string, bad_since: ?int}, send: ?string, from: ?string}
     *         send = 要推送的状态（null 不推）；from = 消息里「X → Y」的 X
     */
    public static function next(?array $mem, string $state, int $stateChangedAt, int $now): array
    {
        $bad = $state !== 'ok' && $state !== 'disabled';
        if ($mem === null) {
            return self::result($bad ? $state : null, $bad ? $stateChangedAt : null);
        }
        $alerted = $mem['alerted'] ?? null;
        if ($state === 'disabled') {
            return self::result(null, null);
        }
        if ($state === 'ok') {
            if ($alerted !== null && $now - $stateChangedAt >= self::CONFIRM_OK) {
                return self::result(null, null, 'ok', $alerted);
            }
            return self::result($alerted, null);
        }
        $badSince = $mem['bad_since'] ?? $stateChangedAt;
        if ($state === $alerted) {
            return self::result($alerted, $badSince);
        }
        // 首次异常从「离开正常」算起（degraded→failopen 不重新计时）；推过之后换状态从新状态算起
        $since = $alerted === null ? $badSince : $stateChangedAt;
        if ($now - $since < self::CONFIRM_BAD) {
            return self::result($alerted, $badSince);
        }
        return self::result($state, $badSince, $state, $alerted ?? 'ok');
    }

    private static function result(?string $alerted, ?int $badSince, ?string $send = null, ?string $from = null): array
    {
        return ['mem' => ['alerted' => $alerted, 'bad_since' => $badSince], 'send' => $send, 'from' => $from];
    }
}
