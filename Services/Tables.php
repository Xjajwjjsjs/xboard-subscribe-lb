<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\Schema;

/**
 * 负载均衡表名与安装检测。
 *
 * 表不存在（未执行迁移 / 已回滚）时整个模块自动「不生效」：订阅按原逻辑走，不报错。
 * Octane worker 常驻内存，检测结果在进程内缓存 60 秒，避免每个请求都查一次 schema。
 */
final class Tables
{
    public const GROUPS = 'sub_lb_groups';
    public const MEMBERS = 'sub_lb_members';
    public const ASSIGNMENTS = 'sub_lb_assignments';
    public const EVENTS = 'sub_lb_events';

    private const RECHECK_SECONDS = 60;

    private static ?bool $installed = null;
    private static int $checkedAt = 0;

    public static function installed(): bool
    {
        $now = time();
        if (self::$installed !== null && ($now - self::$checkedAt) < self::RECHECK_SECONDS) {
            return self::$installed;
        }
        try {
            $installed = Schema::hasTable(self::GROUPS)
                && Schema::hasTable(self::MEMBERS)
                && Schema::hasTable(self::ASSIGNMENTS)
                && Schema::hasTable(self::EVENTS);
        } catch (\Throwable $e) {
            $installed = false;
        }
        self::$installed = $installed;
        self::$checkedAt = $now;
        return $installed;
    }
}
