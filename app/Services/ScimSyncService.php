<?php

namespace App\Services;

use App\Module\Base;
use App\Scim\ScimClient;
use App\Scim\ScimDepartmentSynchronizer;
use App\Scim\ScimPrincipalSynchronizer;
use App\Scim\ScimSyncValidator;
use App\Scim\ScimUserMapper;
use Illuminate\Support\Facades\Cache;

class ScimSyncService
{
    public function run(?string $email = null, bool $dryRun = false): ?array
    {
        $lock = Cache::lock('scim:sync', max(1, (int)config('dootask.scim.lock_seconds', 3600)));
        if (!$lock->get()) {
            return null;
        }

        try {
            if ($email === null && !$dryRun) {
                Base::setting('system', [
                    'scim_last_attempt' => now()->toDateTimeString(),
                ], true);
            }

            $client = new ScimClient();
            if ($dryRun) {
                $organizations = config('dootask.scim.sync_department', true)
                    ? iterator_to_array($client->listOrganizations(), false)
                    : [];
                $groups = config('dootask.scim.sync_department', true)
                    ? iterator_to_array($client->listGroups(), false)
                    : [];
                $users = iterator_to_array($client->listUsers(), false);
                return [
                    'dry_run' => true,
                    'validation' => app(ScimSyncValidator::class)->validate(
                        $organizations,
                        $groups,
                        $users,
                        $email
                    ),
                ];
            }
            $stats = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'departments' => [],
            ];

            $departmentStats = [
                'created' => 0,
                'updated' => 0,
                'mapped' => 0,
                'skipped' => 0,
                'failed' => 0,
                'organizations' => 0,
                'groups' => 0,
                'group_department_ids' => [],
                'errors' => [],
            ];
            // 定向同步必须先确认目标存在，避免无效邮箱触发全局负责人和部门写入。
            $targetScimUser = $email !== null ? $client->findUserByEmail($email) : null;
            if ($email !== null && $targetScimUser === null) {
                throw new \RuntimeException("SCIM 中未找到邮箱用户: {$email}");
            }

            $allScimUsers = null;
            if (config('dootask.scim.sync_department', true)) {
                $organizations = iterator_to_array($client->listOrganizations(), false);
                $groups = iterator_to_array($client->listGroups(), false);
                // Group 负责人可能尚未存在于 DooTask。先用 SCIM User ID 获取完整资料，
                // 经正常用户同步流程创建 owner/admin，再创建依赖 owner_userid 的部门。
                $allScimUsers = iterator_to_array($client->listUsers(), false);
                $principalSync = app(ScimPrincipalSynchronizer::class)->sync($groups, $allScimUsers, $client);
                $departmentStats = app(ScimDepartmentSynchronizer::class)->sync(
                    $organizations,
                    $groups,
                    $principalSync['local_user_ids']
                );
                $departmentStats['principals'] = $principalSync['stats'];
                if ($departmentStats['failed'] > 0) {
                    throw new \RuntimeException(
                        'SCIM 部门树同步失败，已停止用户同步: '
                        . implode('; ', array_slice($departmentStats['errors'], 0, 5))
                    );
                }
            }
            $stats['departments'] = $departmentStats;

            $users = $email !== null
                ? [$targetScimUser]
                : ($allScimUsers ?? $client->listUsers());
            foreach ($users as $scimUser) {
                $result = ScimUserMapper::sync($scimUser, $departmentStats['group_department_ids']);
                $stats[$result]++;
                $stats['total']++;
            }

            if ($email !== null && $stats['total'] === 0) {
                throw new \RuntimeException("SCIM 中未找到邮箱用户: {$email}");
            }

            if ($email === null && $stats['failed'] === 0) {
                Base::setting('system', [
                    'scim_last_sync' => now()->toDateTimeString(),
                ], true);
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }
}
