<?php

namespace App\Scim;

use App\Models\User;
use App\Models\UserDepartment;
use App\Module\Base;
use Illuminate\Support\Facades\Cache;

/**
 * 将 UniAuthSync Organizations / Groups 同步为 DooTask 部门树。
 *
 * 稳定外部 ID 到本地部门 ID 的映射仅保存在 Cache/Redis，不修改数据库结构。
 */
class ScimDepartmentSynchronizer
{
    private const GROUP_ENTERPRISE_SCHEMA = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:Group';
    private const CACHE_PREFIX = 'scim:department:';

    /**
     * @param array<int,array> $organizations
     * @param array<int,array> $groups
     * @param array<string,int> $principalUserIds
     */
    public function sync(array $organizations, array $groups, array $principalUserIds): array
    {
        $orgs = $this->indexResources($organizations, 'Organization');
        $groupMap = $this->indexResources($groups, 'Group');
        $rootOwnerId = $this->resolveRootOwnerId();
        $stats = [
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

        // UniAuthSync 当前 group-only Client 的 Organizations 列表可能过宽。
        // 只同步 Groups 实际引用的组织及其祖先，绝不盲目创建列表中的其他组织。
        $requiredOrgIds = [];
        $orgCollecting = [];
        foreach ($groupMap as $group) {
            $enterprise = $this->groupEnterprise($group);
            $orgId = $this->scalar($enterprise['organization'] ?? '');
            if ($orgId !== '') {
                $this->collectOrgAncestors($orgId, $orgs, $requiredOrgIds, $orgCollecting);
            }
        }

        $this->assertPreflightCapacity($requiredOrgIds, $groupMap);

        $orgDepartmentIds = [];
        $visiting = [];
        foreach (array_keys($requiredOrgIds) as $orgId) {
            $this->syncOrganization(
                $orgId,
                $orgs,
                $orgDepartmentIds,
                $visiting,
                $rootOwnerId,
                $stats
            );
        }

        $groupDepartmentIds = [];
        $visiting = [];
        foreach (array_keys($groupMap) as $groupId) {
            $this->syncGroup(
                $groupId,
                $groupMap,
                $orgDepartmentIds,
                $groupDepartmentIds,
                $visiting,
                $principalUserIds,
                $stats
            );
        }

        $stats['group_department_ids'] = $groupDepartmentIds;
        return $stats;
    }

    public static function cachedGroupDepartmentId(string $groupId): ?int
    {
        return self::cachedDepartmentId('group', $groupId);
    }

    public static function cachedOrganizationDepartmentId(string $orgId): ?int
    {
        return self::cachedDepartmentId('org', $orgId);
    }

    private static function cachedDepartmentId(string $type, string $externalId): ?int
    {
        if ($externalId === '') {
            return null;
        }
        $id = (int)Cache::get(self::cacheKey($type, $externalId), 0);
        if ($id <= 0 || !UserDepartment::whereId($id)->exists()) {
            if ($id > 0) {
                Cache::forget(self::cacheKey($type, $externalId));
            }
            return null;
        }
        return $id;
    }

    private function syncOrganization(
        string $orgId,
        array $orgs,
        array &$departmentIds,
        array &$visiting,
        int $rootOwnerId,
        array &$stats
    ): ?int {
        if (isset($departmentIds[$orgId])) {
            return $departmentIds[$orgId];
        }
        if (isset($visiting[$orgId])) {
            return $this->fail($stats, "Organization 存在循环父链: {$orgId}");
        }
        $org = $orgs[$orgId] ?? null;
        if (!$org) {
            return $this->fail($stats, "Group 引用的 Organization 不在列表中: {$orgId}");
        }

        $visiting[$orgId] = true;
        $parentExternalId = $this->scalar($org['parent']['value'] ?? '');
        $parentId = 0;
        $ownerId = $rootOwnerId;
        if ($parentExternalId !== '' && isset($orgs[$parentExternalId])) {
            // 系统管理员只允许负责同步范围内的顶层 Organization。
            // 非顶层 Organization 必须由 UniAuthSync 提供明确 owner 后才能安全同步。
            unset($visiting[$orgId]);
            return $this->fail($stats, "非顶层 Organization 缺少负责人字段，拒绝继承顶层管理员: {$orgId}");
        }

        $name = $this->scalar($org['displayName'] ?? '');
        $departmentId = $this->syncDepartment('org', $orgId, $name, $parentId, $ownerId, [], $stats);
        unset($visiting[$orgId]);
        if ($departmentId) {
            $departmentIds[$orgId] = $departmentId;
            $stats['organizations']++;
        }
        return $departmentId;
    }

    private function syncGroup(
        string $groupId,
        array $groups,
        array $orgDepartmentIds,
        array &$departmentIds,
        array &$visiting,
        array $principalUserIds,
        array &$stats
    ): ?int {
        if (isset($departmentIds[$groupId])) {
            return $departmentIds[$groupId];
        }
        if (isset($visiting[$groupId])) {
            return $this->fail($stats, "Group 存在循环父链: {$groupId}");
        }
        $group = $groups[$groupId] ?? null;
        if (!$group) {
            return $this->fail($stats, "Group 不存在: {$groupId}");
        }

        $visiting[$groupId] = true;
        $enterprise = $this->groupEnterprise($group);
        $parentGroupId = $this->scalar($enterprise['parent_id'] ?? '');
        $parentId = 0;
        if ($parentGroupId !== '' && isset($groups[$parentGroupId])) {
            $parentId = (int)($this->syncGroup(
                $parentGroupId,
                $groups,
                $orgDepartmentIds,
                $departmentIds,
                $visiting,
                $principalUserIds,
                $stats
            ) ?? 0);
        } else {
            // 父分组不在授权列表时，将当前分组视为可见范围根，挂到所属组织下。
            $orgId = $this->scalar($enterprise['organization'] ?? '');
            $parentId = (int)($orgDepartmentIds[$orgId] ?? 0);
        }

        if ($parentId <= 0) {
            unset($visiting[$groupId]);
            return $this->fail($stats, "Group 无法解析本地父部门: {$groupId}");
        }

        $name = $this->scalar($group['displayName'] ?? '');
        try {
            [$ownerId, $deputyIds] = $this->resolveGroupPrincipals($group, $principalUserIds);
        } catch (\Throwable $e) {
            unset($visiting[$groupId]);
            return $this->fail($stats, $e->getMessage());
        }
        $departmentId = $this->syncDepartment(
            'group',
            $groupId,
            $name,
            $parentId,
            $ownerId,
            $deputyIds,
            $stats
        );
        unset($visiting[$groupId]);
        if ($departmentId) {
            $departmentIds[$groupId] = $departmentId;
            $stats['groups']++;
        }
        return $departmentId;
    }

    private function syncDepartment(
        string $type,
        string $externalId,
        string $name,
        int $parentId,
        int $ownerId,
        array $deputyIds,
        array &$stats
    ): ?int {
        $this->assertDepartmentName($name);
        if ($ownerId <= 0 || !User::whereUserid($ownerId)->exists()) {
            return $this->fail($stats, "SCIM 部门负责人不是有效的本地用户: {$type} {$externalId}");
        }
        $deputyIds = array_values(array_unique(array_filter(
            array_map('intval', $deputyIds),
            fn(int $id) => $id > 0 && $id !== $ownerId
        )));
        sort($deputyIds);

        $cacheKey = self::cacheKey($type, $externalId);
        $cachedId = (int)Cache::get($cacheKey, 0);
        $department = $cachedId > 0 ? UserDepartment::find($cachedId) : null;
        if ($cachedId > 0 && !$department) {
            Cache::forget($cacheKey);
        }

        if (!$department) {
            $matches = UserDepartment::whereParentId($parentId)->whereName($name)->get();
            if ($matches->count() > 1) {
                return $this->fail($stats, "本地部门路径匹配不唯一: parent={$parentId}, name={$name}");
            }
            if ($matches->count() === 1) {
                $department = $matches->first();
                Cache::forever($cacheKey, (int)$department->id);
                $stats['mapped']++;
            }
        }

        if ($department) {
            $currentDeputyIds = array_map('intval', $department->deputy_userids);
            sort($currentDeputyIds);
            $coreChanged = (string)$department->name !== $name
                || (int)$department->parent_id !== $parentId
                || (int)$department->owner_userid !== $ownerId;
            $deputiesChanged = $currentDeputyIds !== $deputyIds;
            if (!$coreChanged && !$deputiesChanged) {
                Cache::forever($cacheKey, (int)$department->id);
                $stats['skipped']++;
                return (int)$department->id;
            }

            if ((string)$department->name !== $name || (int)$department->parent_id !== $parentId) {
                $conflict = UserDepartment::whereParentId($parentId)
                    ->whereName($name)
                    ->where('id', '!=', (int)$department->id)
                    ->exists();
                if ($conflict) {
                    return $this->fail($stats, "部门移动或重命名后的目标路径已存在: parent={$parentId}, name={$name}");
                }
                $this->assertParentCapacity($parentId, (int)$department->id);
            }
            if ((int)$department->owner_userid !== $ownerId) {
                $this->assertOwnerCapacity($ownerId, (int)$department->id);
            }
            if ($coreChanged) {
                $department->saveDepartment([
                    'name' => $name,
                    'parent_id' => $parentId,
                    'owner_userid' => $ownerId,
                ]);
                $department->refresh();
            }
            $this->syncDeputies($department, $deputyIds);
            Cache::forever($cacheKey, (int)$department->id);
            Cache::forever('UserDepartment::rand', Base::generatePassword());
            $stats['updated']++;
            return (int)$department->id;
        }

        $this->assertCreateCapacity($parentId);
        $this->assertOwnerCapacity($ownerId);
        $department = UserDepartment::createInstance();
        $department->saveDepartment([
            'name' => $name,
            'parent_id' => $parentId,
            'owner_userid' => $ownerId,
        ]);
        $department->refresh();
        $this->syncDeputies($department, $deputyIds);
        Cache::forever($cacheKey, (int)$department->id);
        Cache::forever('UserDepartment::rand', Base::generatePassword());
        $stats['created']++;
        return (int)$department->id;
    }

    private function assertPreflightCapacity(array $requiredOrgIds, array $groups): void
    {
        $mapped = 0;
        foreach (array_keys($requiredOrgIds) as $orgId) {
            if (self::cachedOrganizationDepartmentId($orgId)) {
                $mapped++;
            }
        }
        foreach (array_keys($groups) as $groupId) {
            if (self::cachedGroupDepartmentId($groupId)) {
                $mapped++;
            }
        }

        $requiredCreates = max(0, count($requiredOrgIds) + count($groups) - $mapped);
        if (UserDepartment::count() + $requiredCreates > 200) {
            throw new \RuntimeException('SCIM 部门预检失败：预计超过 DooTask 200 个部门限制');
        }
    }

    private function resolveRootOwnerId(): int
    {
        $email = trim((string)config('dootask.scim.root_department_owner_email', ''));
        if ($email === '') {
            throw new \RuntimeException('SCIM 顶层部门同步未配置 SCIM_ROOT_DEPARTMENT_OWNER_EMAIL');
        }
        $users = User::whereEmail($email)->get();
        if ($users->count() !== 1) {
            throw new \RuntimeException("SCIM 顶层部门负责人邮箱无法唯一匹配: {$email}");
        }
        $user = $users->first();
        if (!$user->isAdmin() || $user->isDisable(true)) {
            throw new \RuntimeException("SCIM 顶层部门负责人必须是启用的管理员: {$email}");
        }
        return (int)$user->userid;
    }

    private function resolveGroupPrincipals(array $group, array $principalUserIds): array
    {
        $groupId = $this->scalar($group['id'] ?? '');
        $name = $this->scalar($group['displayName'] ?? $groupId);
        $ownerExternalIds = $this->referenceIds($group['owners'] ?? []);
        if (count($ownerExternalIds) !== 1) {
            throw new \RuntimeException(
                "SCIM Group 必须且只能配置一名负责人: {$name} ({$groupId}), 实际 "
                . count($ownerExternalIds) . ' 名'
            );
        }
        $ownerExternalId = $ownerExternalIds[0];
        $ownerId = (int)($principalUserIds[$ownerExternalId] ?? 0);
        if ($ownerId <= 0) {
            throw new \RuntimeException("SCIM Group 负责人尚未同步为本地用户: {$name} ({$ownerExternalId})");
        }

        $deputyIds = [];
        foreach ($this->referenceIds($group['admins'] ?? []) as $adminExternalId) {
            if ($adminExternalId === $ownerExternalId) {
                continue;
            }
            $deputyId = (int)($principalUserIds[$adminExternalId] ?? 0);
            if ($deputyId <= 0) {
                throw new \RuntimeException("SCIM Group 协管尚未同步为本地用户: {$name} ({$adminExternalId})");
            }
            $deputyIds[] = $deputyId;
        }
        return [$ownerId, array_values(array_unique($deputyIds))];
    }

    private function referenceIds(mixed $references): array
    {
        if ($references === null) {
            return [];
        }
        if (!is_array($references)) {
            throw new \RuntimeException('SCIM Group owners/admins 字段格式错误');
        }
        $ids = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                throw new \RuntimeException('SCIM Group 用户引用格式错误');
            }
            $id = $this->scalar($reference['value'] ?? '');
            if ($id === '') {
                throw new \RuntimeException('SCIM Group 用户引用缺少 value');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    private function syncDeputies(UserDepartment $department, array $targetIds): void
    {
        $currentIds = array_map('intval', $department->deputy_userids);
        foreach (array_diff($currentIds, $targetIds) as $userid) {
            $department->delDeputy((int)$userid);
        }
        foreach (array_diff($targetIds, $currentIds) as $userid) {
            $department->addDeputy((int)$userid);
        }
    }

    private function assertOwnerCapacity(int $ownerId, int $excludeDepartmentId = 0): void
    {
        $query = UserDepartment::whereOwnerUserid($ownerId);
        if ($excludeDepartmentId > 0) {
            $query->where('id', '!=', $excludeDepartmentId);
        }
        if ($query->count() >= 10) {
            throw new \RuntimeException("SCIM 部门负责人 {$ownerId} 已达到最多负责10个部门的限制");
        }
    }

    private function assertCreateCapacity(int $parentId): void
    {
        if (UserDepartment::count() >= 200) {
            throw new \RuntimeException('SCIM 部门同步超过 DooTask 200 个部门限制');
        }
        $this->assertParentCapacity($parentId);
    }

    private function assertParentCapacity(int $parentId, int $excludeId = 0): void
    {
        if ($parentId <= 0) {
            return;
        }
        $parent = UserDepartment::find($parentId);
        if (!$parent) {
            throw new \RuntimeException("SCIM 上级部门不存在: {$parentId}");
        }
        if (count($parent->parents()) > 8) {
            throw new \RuntimeException('SCIM 部门同步超过 DooTask 9 级部门限制');
        }
        $children = UserDepartment::whereParentId($parentId);
        if ($excludeId > 0) {
            $children->where('id', '!=', $excludeId);
        }
        if ($children->count() >= 20) {
            throw new \RuntimeException("SCIM 上级部门 {$parentId} 已达到20个直接子部门限制");
        }
    }

    private function assertDepartmentName(string $name): void
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 20) {
            throw new \RuntimeException("SCIM 部门名称长度限制2-20个字: {$name}");
        }
        if (preg_match('/[~!@#$%^&*()+\-_=.:?<>,]/u', $name) || str_contains($name, '(M)')) {
            throw new \RuntimeException("SCIM 部门名称包含 DooTask 不允许的字符: {$name}");
        }
    }

    private function collectOrgAncestors(string $orgId, array $orgs, array &$required, array &$collecting): void
    {
        if (isset($required[$orgId])) {
            return;
        }
        if (isset($collecting[$orgId])) {
            throw new \RuntimeException("Organization 存在循环父链: {$orgId}");
        }
        $org = $orgs[$orgId] ?? null;
        if (!$org) {
            throw new \RuntimeException("Group 引用的 Organization 不在列表中: {$orgId}");
        }
        $collecting[$orgId] = true;
        $required[$orgId] = true;
        $parentId = $this->scalar($org['parent']['value'] ?? '');
        if ($parentId !== '' && isset($orgs[$parentId])) {
            $this->collectOrgAncestors($parentId, $orgs, $required, $collecting);
        }
        unset($collecting[$orgId]);
    }

    private function indexResources(array $resources, string $label): array
    {
        $indexed = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                throw new \RuntimeException("SCIM {$label} 资源格式错误");
            }
            $id = $this->scalar($resource['id'] ?? '');
            if ($id === '' || isset($indexed[$id])) {
                throw new \RuntimeException("SCIM {$label} ID 缺失或重复: {$id}");
            }
            $indexed[$id] = $resource;
        }
        return $indexed;
    }

    private function groupEnterprise(array $group): array
    {
        $enterprise = $group[self::GROUP_ENTERPRISE_SCHEMA] ?? [];
        return is_array($enterprise) ? $enterprise : [];
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function fail(array &$stats, string $message): ?int
    {
        $stats['failed']++;
        $stats['errors'][] = $message;
        return null;
    }

    private static function cacheKey(string $type, string $externalId): string
    {
        return self::CACHE_PREFIX . $type . ':' . hash('sha256', $externalId);
    }
}
