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
 *   ./cmd artisan scim:sync --user=050846@sjq.sh
 */
class ScimSync extends Command
{
    protected $signature   = 'scim:sync
        {--user= : 仅同步指定邮箱用户（仍先同步其授权范围内部门树）}
        {--dry-run : 只拉取并校验上游资源，不修改本地用户、部门或同步时间}';
    protected $description = '从 UniAuthSync 同步组织、分组和用户';

    public function handle(ScimSyncService $service): int
    {
        $email = trim((string)$this->option('user'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('指定的用户邮箱格式无效');
            return self::FAILURE;
        }

        $dryRun = (bool)$this->option('dry-run');
        try {
            $stats = $service->run($email !== '' ? $email : null, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($stats === null) {
            $this->warn('SCIM 同步正在运行，本次跳过');
            return self::SUCCESS;
        }
        if ($dryRun) {
            $validation = $stats['validation'];
            $this->info("SCIM 只读预检: 组织 {$validation['organizations']}, 分组 {$validation['groups']}, 用户 {$validation['users']}");
            foreach (array_slice($validation['errors'], 0, 20) as $error) {
                $this->error($error);
            }
            return $validation['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $departments = $stats['departments'];
        $principals = $departments['principals'] ?? ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->info("SCIM 负责人预同步: {$principals['total']} 用户, 新建 {$principals['created']}, 更新 {$principals['updated']}, 跳过 {$principals['skipped']}");
        $this->info("SCIM 部门同步: 组织 {$departments['organizations']}, 分组 {$departments['groups']}, 新建 {$departments['created']}, 更新 {$departments['updated']}, 映射 {$departments['mapped']}, 跳过 {$departments['skipped']}");
        $this->info("SCIM 用户同步: {$stats['total']} 用户, 新建 {$stats['created']}, 更新 {$stats['updated']}, 跳过 {$stats['skipped']}, 失败 {$stats['failed']}");
        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
