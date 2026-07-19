<?php

namespace App\Console\Commands;

use App\Services\ScimSyncService;
use Illuminate\Console\Command;

/**
 * SCIM 同步命令
 *
 * 从 UniAuthSync 全量拉取用户并同步到 Dootask。
 * 启动时由 LoopTask 触发，也可手动执行。
 *
 *   ./cmd artisan scim:sync
 */
class ScimSync extends Command
{
    protected $signature   = 'scim:sync';
    protected $description = '从 UniAuthSync 全量同步用户';

    public function handle(ScimSyncService $service): int
    {
        try {
            $stats = $service->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($stats === null) {
            $this->warn('SCIM 同步正在运行，本次跳过');
            return self::SUCCESS;
        }

        $this->info("SCIM 同步完成: {$stats['total']} 用户, 新建 {$stats['created']}, 更新 {$stats['updated']}, 跳过 {$stats['skipped']}, 失败 {$stats['failed']}");
        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
