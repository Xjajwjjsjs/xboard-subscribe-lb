<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 运行事件：写 sub_lb_events（后台「最近事件」）+ warning 级别以上同时进 laravel 日志（LOG_LEVEL=warning）。
 *
 * 订阅热路径上的重复事件（组无可用成员、状态存储降级……）必须带 throttleKey，
 * 否则一个组出问题时每个用户每次刷新订阅都会写一行。
 * 本类任何失败都只吞掉 + 记 warning，绝不向调用方抛异常。
 */
final class EventLog
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    /** Redis 不可用时的进程内兜底节流：key => 上次放行时间 */
    private static array $localThrottle = [];

    public static function record(
        string $type,
        string $level,
        ?int $groupId,
        ?int $serverId,
        $detail = null,
        ?string $throttleKey = null,
        int $throttleSeconds = 0
    ): void {
        if ($throttleKey !== null && $throttleSeconds > 0 && !self::acquire($throttleKey, $throttleSeconds)) {
            return;
        }
        $text = $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE));
        if ($level !== self::INFO) {
            Log::warning(sprintf('[SUB_LB] %s group=%s server=%s %s', $type, $groupId ?? '-', $serverId ?? '-', $text ?? ''));
        }
        try {
            DB::table(Tables::EVENTS)->insert([
                'group_id' => $groupId,
                'server_id' => $serverId,
                'type' => $type,
                'level' => $level,
                'detail' => $text === null ? null : mb_substr($text, 0, 2000),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SUB_LB] event write failed: ' . $e->getMessage());
        }
    }

    /** 节流闸：TTL 内同一个 key 只放行一次。 */
    public static function acquire(string $key, int $ttl): bool
    {
        try {
            return (bool) Cache::add('sub_lb:throttle:' . $key, 1, $ttl);
        } catch (\Throwable $e) {
            $now = time();
            if (isset(self::$localThrottle[$key]) && ($now - self::$localThrottle[$key]) < $ttl) {
                return false;
            }
            if (count(self::$localThrottle) > 500) {
                self::$localThrottle = [];
            }
            self::$localThrottle[$key] = $now;
            return true;
        }
    }
}
