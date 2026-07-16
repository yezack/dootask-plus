<?php

namespace App\Tasks;

use App\Module\Base;
use App\Scim\ScimClient;
use App\Scim\ScimUserMapper;

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
        $serverUrl = env('SCIM_SERVER_URL', '');
        if (empty($serverUrl)) {
            return;
        }

        $interval    = (int)env('SCIM_POLL_INTERVAL', 60);  // 分钟
        $system      = Base::setting('system');
        $lastSync    = $system['scim_last_sync'] ?? '';

        if ($lastSync && now()->diffInMinutes($lastSync) < $interval) {
            return; // 还没到时间
        }

        $client = new ScimClient();
        $stats  = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];

        foreach ($client->listUsers() as $scimUser) {
            $result = ScimUserMapper::sync($scimUser);
            $stats[$result]++;
            $stats['total']++;
        }

        // 更新最后同步时间
        $system['scim_last_sync'] = now()->toDateTimeString();
        Base::setting('system', $system);
    }

    public function end(): void {}
}
