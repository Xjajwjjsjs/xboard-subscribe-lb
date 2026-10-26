<?php

namespace Plugin\SubscribeLb\Commands;

use Plugin\SubscribeLb\Services\DnsResolver;
use Plugin\SubscribeLb\Services\Tables;
use Illuminate\Console\Command;

/**
 * 立即执行域名入口解析（平时由 sub-lb:check 按各组间隔自动跑）：
 *   php artisan sub-lb:resolve            # 所有配置了解析来源的组，忽略间隔
 *   php artisan sub-lb:resolve --group=3  # 只解析某个组
 */
class LbResolveCommand extends Command
{
    protected $signature = 'sub-lb:resolve {--group= : 只处理该组 id}';

    protected $description = '订阅负载均衡：立即在面板服务器解析域名入口（只取 IPv4）并同步自动成员';

    public function handle(): int
    {
        if (!Tables::installed()) {
            $this->error('负载均衡数据表未安装');
            return self::FAILURE;
        }
        $group = $this->option('group') !== null ? (int) $this->option('group') : null;
        $reports = DnsResolver::runDue(time(), true, $group);
        if (empty($reports)) {
            $this->line('没有配置了解析来源的组');
        }
        foreach ($reports as $r) {
            if ($r['error']) {
                $this->error("#{$r['group_id']} {$r['name']}: {$r['error']}");
                continue;
            }
            $this->info("#{$r['group_id']} {$r['name']}: " . ($r['changed'] ? implode('；', $r['lines']) : '无变化'));
        }
        return self::SUCCESS;
    }
}
