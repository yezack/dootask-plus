<?php

namespace App\Console\Commands;

use App\Module\Base;
use App\Scim\ScimClient;
use App\Scim\ScimUserMapper;
use Illuminate\Console\Command;

/**
 * SCIM 同步命令
 *
 * 从 UniAuthSync 全量拉取用户并同步到 Dootask。
 * 启动时由 LoopTask 触发，也可手动执行。
 *
 *   php artisan scim:sync
 */
class ScimSync extends Command
{
    protected $signature   = 'scim:sync';
    protected $description = '从 UniAuthSync 全量同步用户';

    public function handle(): int
    {
        $client = new ScimClient();
        $stats  = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];

        foreach ($client->listUsers() as $scimUser) {
            $result = ScimUserMapper::sync($scimUser);
            $stats[$result]++;
            $stats['total']++;
        }

        // 记录同步时间
        Setting::where('name', 'system')->update([
            'setting' => json_encode(array_merge(
                json_decode(Setting::where('name', 'system')->value('setting') ?: '{}', true) ?: [],
                ['scim_last_sync' => now()->toDateTimeString()]
            ), JSON_UNESCAPED_UNICODE)
        ]);

        $this->info("SCIM 同步完成: {$stats['total']} 用户, 新建 {$stats['created']}, 更新 {$stats['updated']}, 跳过 {$stats['skipped']}");
        return 0;
    }
}
