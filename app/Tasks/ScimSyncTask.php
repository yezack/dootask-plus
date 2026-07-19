<?php

namespace App\Tasks;

use App\Module\Base;
use App\Services\ScimSyncService;

/**
 * SCIM 定时同步任务
 *
 * 每分钟由 crontab 触发一次，检查距上次同步是否超过配置间隔。
 * 若 SCIM_SERVER_URL 为空则跳过。
 */
class ScimSyncTask extends AbstractTask
{
    public function start(): void
    {
        $serverUrl = (string)config('dootask.scim.server_url', '');
        if (empty($serverUrl)) {
            return;
        }

        $interval    = max(1, (int)config('dootask.scim.poll_interval', 60));  // 分钟
        $system      = Base::setting('system');
        $lastAttempt = $system['scim_last_attempt'] ?? $system['scim_last_sync'] ?? '';

        if ($lastAttempt && now()->diffInMinutes($lastAttempt, true) < $interval) {
            return; // 还没到时间
        }

        app(ScimSyncService::class)->run();
    }

    public function end(): void {}
}
