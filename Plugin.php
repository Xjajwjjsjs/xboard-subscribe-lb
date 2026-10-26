<?php

namespace Plugin\SubscribeLb;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Plugin\SubscribeLb\Services\LoadBalancer;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 取到用户可用节点之后、各客户端模板渲染之前：把组成员收起，按「用户 + 组」换成分到的那个成员
        $this->filter('client.subscribe.servers', function ($servers, $user = null) {
            if (!is_array($servers) || $user === null) {
                return $servers;
            }
            try {
                return LoadBalancer::make()->forSubscription($servers, $user);
            } catch (\Throwable $e) {
                Log::warning('[SUB_LB] subscribe filter failed: ' . $e->getMessage());
                return $servers;
            }
        }, 20);
    }

    public function schedule(Schedule $schedule): void
    {
        // 巡检：TCP 探测、健康滞回、组状态告警、域名入口解析、入口子节点同步、清理
        $schedule->command('sub-lb:check')->everyMinute()->withoutOverlapping(5)->runInBackground();
    }
}
