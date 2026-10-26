<?php

namespace Plugin\SubscribeLb\Services;

use Illuminate\Support\Facades\DB;

/**
 * 读插件配置（后台「插件管理 → 配置」里保存的值）。命令行巡检不经过插件 boot，所以直接读 v2_plugins。
 */
final class Settings
{
    public const CODE = 'subscribe_lb';

    private static ?array $values = null;

    public static function bool(string $key, bool $default): bool
    {
        $v = self::all()[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function all(): array
    {
        if (self::$values === null) {
            try {
                $raw = DB::table('v2_plugins')->where('code', self::CODE)->value('config');
                self::$values = json_decode((string) $raw, true) ?: [];
            } catch (\Throwable $e) {
                self::$values = [];
            }
        }
        return self::$values;
    }
}
