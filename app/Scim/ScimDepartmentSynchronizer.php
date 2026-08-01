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
        $this->assertGroupParentOrganizations($groupMap);
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
        // 仅把 Group 直接引用的 Organization 作为 DooTask 顶层部门；不沿上游 parent
        // 创建授权范围外祖先，避免让系统管理员成为真实业务子组织的负责人。
        $requiredOrgIds = [];
        foreach ($groupMap as $group) {
            $enterprise = $this->groupEnterprise($group);
            $orgId = $this->scalar($enterprise['organization'] ?? '');
            if ($orgId === '') {
                throw new \RuntimeException('SCIM Group 缺少 organization 引用');
            }
            if (!isset($orgs[$orgId])) {
                throw new \RuntimeException("Group 引用的 Organization 不在列表中: {$orgId}");
            }
            $requiredOrgIds[$orgId] = true;
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

    /**
     * 增量同步单个 Group（webhook 事件用）。
     *
     * 不改数据库结构，仅按既有映射更新已存在的本地部门；若缓存映射缺失则回退全量 sync。
     * @return array{updated:bool, department_id:?int, error:?string}
     */
    public function syncSingleGroup(ScimClient $client, string $groupId): array
    {
        $visiting = [];
        return $this->syncSingleGroupResource($client, $groupId, $visiting);
    }

    private function syncSingleGroupResource(ScimClient $client, string $groupId, array &$visiting): array
    {
        $error = fn(string $message): array => ['updated' => false, 'department_id' => null, 'error' => $message];
        if (isset($visiting[$groupId])) {
            return $error("SCIM Group 存在循环父链: {$groupId}");
        }
        $visiting[$groupId] = true;
        try {
            $group = $client->getGroupById($groupId);
        } catch (ScimResourceNotFoundException $e) {
            unset($visiting[$groupId]);
            return $error('SCIM Group 不存在: ' . $groupId);
        }

        try {
            $enterprise = $this->groupEnterprise($group);
            $parentGroupId = $this->scalar($enterprise['parent_id'] ?? '');
            if ($parentGroupId !== '') {
                $parentId = self::cachedGroupDepartmentId($parentGroupId);
                if ($parentId === null) {
                    $parentResult = $this->syncSingleGroupResource($client, $parentGroupId, $visiting);
                    if ($parentResult['error'] !== null) {
                        return $parentResult;
                    }
                    $parentId = $parentResult['department_id'];
                }
            } else {
                $orgId = $this->scalar($enterprise['organization'] ?? '');
                if ($orgId === '') {
                    return $error("SCIM Group 缺少 organization 引用: {$groupId}");
                }
                $parentId = self::cachedOrganizationDepartmentId($orgId);
                if ($parentId === null) {
                    $orgResult = $this->syncSingleOrganization($client, $orgId);
                    if ($orgResult['error'] !== null) {
                        return $orgResult;
                    }
                    $parentId = $orgResult['department_id'];
                }
            }
            if ((int)$parentId <= 0) {
                return $error("SCIM Group 无法解析本地父部门: {$groupId}");
            }

            $principalSync = app(ScimPrincipalSynchronizer::class)->sync([$group], [], $client);
            [$ownerId, $deputyIds] = $this->resolveGroupPrincipals(
                $group,
                $principalSync['local_user_ids']
            );
            $stats = $this->emptyStats();
            $departmentId = $this->syncDepartment(
                'group',
                $groupId,
                $this->scalar($group['displayName'] ?? ''),
                (int)$parentId,
                $ownerId,
                $deputyIds,
                $stats
            );
            if ($departmentId === null) {
                return $error($stats['errors'][0] ?? "SCIM Group 同步失败: {$groupId}");
            }
            return [
                'updated' => ($stats['created'] + $stats['updated'] + $stats['mapped']) > 0,
                'department_id' => $departmentId,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $error($e->getMessage());
        } finally {
            unset($visiting[$groupId]);
        }
    }

    /**
     * 增量同步单个 Organization（webhook 事件用）。
     * @return array{updated:bool, department_id:?int, error:?string}
     */
    public function syncSingleOrganization(ScimClient $client, string $orgId): array
    {
        $error = fn(string $message): array => ['updated' => false, 'department_id' => null, 'error' => $message];
        try {
            $org = $client->getOrganizationById($orgId);
        } catch (ScimResourceNotFoundException $e) {
            return $error('SCIM Organization 不存在: ' . $orgId);
        }
        try {
            $stats = $this->emptyStats();
            $departmentId = $this->syncDepartment(
                'org',
                $orgId,
                $this->scalar($org['displayName'] ?? ''),
                0,
                $this->resolveRootOwnerId(),
                [],
                $stats
            );
            if ($departmentId === null) {
                return $error($stats['errors'][0] ?? "SCIM Organization 同步失败: {$orgId}");
            }
            return [
                'updated' => ($stats['created'] + $stats['updated'] + $stats['mapped']) > 0,
                'department_id' => $departmentId,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $error($e->getMessage());
        }
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
        // Group 直接引用的 Organization 是本次 DooTask 同步边界，统一作为顶层部门。
        // 上游 Organization.parent 仅作展示信息，不在当前 Client 授权树中继续展开。
        $parentId = 0;
        $ownerId = $rootOwnerId;

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
        $validDeputies = User::whereIn('userid', $deputyIds)->get();
        if ($validDeputies->count() !== count($deputyIds)
            || $validDeputies->contains(fn(User $user) => $user->isDisable(true))) {
            return $this->fail($stats, "SCIM 部门协管包含不存在或已停用的本地用户: {$type} {$externalId}");
        }

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
                $this->cacheMapping($type, $externalId, (int)$department->id);
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
                $this->cacheMapping($type, $externalId, (int)$department->id);
                $stats['skipped']++;
                return (int)$department->id;
            }

            if ((string)$department->name !== $name || (int)$department->parent_id !== $parentId) {
                $this->assertNoLocalDepartmentCycle((int)$department->id, $parentId);
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
            $this->cacheMapping($type, $externalId, (int)$department->id);
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
        $this->cacheMapping($type, $externalId, (int)$department->id);
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

    private function assertGroupParentOrganizations(array $groups): void
    {
        foreach ($groups as $groupId => $group) {
            $enterprise = $this->groupEnterprise($group);
            $parentGroupId = $this->scalar($enterprise['parent_id'] ?? '');
            if ($parentGroupId === '' || !isset($groups[$parentGroupId])) {
                continue;
            }
            $organization = $this->scalar($enterprise['organization'] ?? '');
            $parentOrganization = $this->scalar(
                $this->groupEnterprise($groups[$parentGroupId])['organization'] ?? ''
            );
            if ($organization === '' || $organization !== $parentOrganization) {
                throw new \RuntimeException(
                    "SCIM Group 父子节点跨 Organization: {$groupId} -> {$parentGroupId}"
                );
            }
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
        // UniAuthSync 将负责人放在 admins 字段；若 owners 存在则优先，否则以 admins[0] 为 owner
        $rawOwners = $group['owners'] ?? [];
        $rawAdmins = $group['admins'] ?? [];
        if (empty($rawOwners) && !empty($rawAdmins)) {
            $rawOwners = [reset($rawAdmins)];
            $rawAdmins = array_slice($rawAdmins, 1);
        }
        $ownerExternalIds = $this->referenceIds($rawOwners);
        if (count($ownerExternalIds) > 1) {
            throw new \RuntimeException(
                "SCIM Group 最多配置一名负责人: {$name} ({$groupId}), 实际 "
                . count($ownerExternalIds) . ' 名'
            );
        }
        $ownerExternalId = '';
        if (count($ownerExternalIds) === 1) {
            $ownerExternalId = $ownerExternalIds[0];
            $ownerId = (int)($principalUserIds[$ownerExternalId] ?? 0);
            if ($ownerId <= 0) {
                throw new \RuntimeException("SCIM Group 负责人尚未同步为本地用户: {$name} ({$ownerExternalId})");
            }
        } else {
            $ownerId = $this->resolveRootOwnerId();
        }

        $deputyIds = [];
        foreach ($this->referenceIds($rawAdmins) as $adminExternalId) {
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

    private function emptyStats(): array
    {
        return [
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

    private function assertNoLocalDepartmentCycle(int $departmentId, int $parentId): void
    {
        if ($parentId <= 0) {
            return;
        }
        if ($parentId === $departmentId) {
            throw new \RuntimeException("SCIM 部门不能将自身设为上级: {$departmentId}");
        }
        $parent = UserDepartment::find($parentId);
        if ($parent && collect($parent->parents())->contains(fn(UserDepartment $item) => (int)$item->id === $departmentId)) {
            throw new \RuntimeException("SCIM 部门不能移动到自身下级: {$departmentId} -> {$parentId}");
        }
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

    private function cacheMapping(string $type, string $externalId, int $departmentId): void
    {
        $reverseKey = self::CACHE_PREFIX . 'local:' . $departmentId;
        $mapping = $type . ':' . hash('sha256', $externalId);
        $existing = Cache::get($reverseKey);
        if (is_string($existing) && $existing !== '' && $existing !== $mapping) {
            throw new \RuntimeException(
                "本地部门 {$departmentId} 已映射到其他 SCIM 资源，拒绝覆盖"
            );
        }
        Cache::forever(self::cacheKey($type, $externalId), $departmentId);
        Cache::forever($reverseKey, $mapping);
    }

    private static function cacheKey(string $type, string $externalId): string
    {
        return self::CACHE_PREFIX . $type . ':' . hash('sha256', $externalId);
    }
}
