<?php

namespace App\Scim;

/**
 * SCIM 上游资源的纯只读预检，不访问或修改本地数据库。
 */
class ScimSyncValidator
{
    private const GROUP_ENTERPRISE_SCHEMA = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:Group';

    public function validate(array $organizations, array $groups, array $users, ?string $targetEmail = null): array
    {
        $orgs = $this->index($organizations, 'Organization');
        $groupMap = $this->index($groups, 'Group');
        $userMap = $this->index($users, 'User');
        $errors = [];

        foreach ($groupMap as $groupId => $group) {
            $enterprise = $this->enterprise($group);
            $orgId = $this->scalar($enterprise['organization'] ?? '');
            if ($orgId === '' || !isset($orgs[$orgId])) {
                $errors[] = "Group {$groupId} 引用的 Organization 不存在: {$orgId}";
            }

            $parentId = $this->scalar($enterprise['parent_id'] ?? '');
            if ($parentId !== '' && isset($groupMap[$parentId])) {
                $parentOrgId = $this->scalar($this->enterprise($groupMap[$parentId])['organization'] ?? '');
                if ($parentOrgId !== $orgId) {
                    $errors[] = "Group 父子节点跨 Organization: {$groupId} -> {$parentId}";
                }
            }

            // UniAuthSync 将负责人放在 admins 字段；若 owners 存在则优先，否则以 admins[0] 为 owner
            $rawOwners = $group['owners'] ?? [];
            $rawAdmins = $group['admins'] ?? [];
            if (empty($rawOwners) && !empty($rawAdmins)) {
                $rawOwners = [reset($rawAdmins)];
                $rawAdmins = array_slice($rawAdmins, 1);
            }
            $owners = $this->references($rawOwners, "Group {$groupId} owners", $errors);
            if (count($owners) > 1) {
                $errors[] = "Group {$groupId} 最多配置一名负责人，实际 " . count($owners) . ' 名';
            }
            $admins = $this->references($rawAdmins, "Group {$groupId} admins", $errors);
            foreach (array_unique(array_merge($owners, $admins)) as $userId) {
                if (!isset($userMap[$userId])) {
                    $errors[] = "Group {$groupId} 引用的用户不存在: {$userId}";
                    continue;
                }
                if (($userMap[$userId]['active'] ?? null) !== true) {
                    $errors[] = "Group {$groupId} 引用的用户未启用: {$userId}";
                }
                if (ScimUserMapper::extractPrimaryEmail($userMap[$userId]) === '') {
                    $errors[] = "Group {$groupId} 引用的用户缺少唯一 Primary Email: {$userId}";
                }
            }
        }

        $matchedTarget = $targetEmail === null;
        foreach ($userMap as $userId => $user) {
            $email = ScimUserMapper::extractEmail($user);
            if ($email === '') {
                $errors[] = "User {$userId} 缺少唯一有效邮箱";
            }
            if ($targetEmail !== null && hash_equals(strtolower($targetEmail), strtolower($email))) {
                $matchedTarget = true;
            }
            foreach ($this->directGroupIds($user) as $groupId) {
                if (!isset($groupMap[$groupId])) {
                    $errors[] = "User {$userId} 引用的 direct Group 不存在: {$groupId}";
                }
            }
        }

        if (!$matchedTarget) {
            $errors[] = "SCIM 中未找到邮箱用户: {$targetEmail}";
        }
        $errors = array_values(array_unique($errors));

        return [
            'organizations' => count($orgs),
            'groups' => count($groupMap),
            'users' => count($userMap),
            'errors' => $errors,
            'valid' => $errors === [],
        ];
    }

    private function index(array $resources, string $label): array
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

    private function references(mixed $items, string $label, array &$errors): array
    {
        if ($items === null) {
            return [];
        }
        if (!is_array($items)) {
            $errors[] = "{$label} 格式错误";
            return [];
        }
        $ids = [];
        foreach ($items as $item) {
            $id = is_array($item) ? $this->scalar($item['value'] ?? '') : '';
            if ($id === '') {
                $errors[] = "{$label} 引用缺少 value";
                continue;
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    private function directGroupIds(array $user): array
    {
        $ids = [];
        foreach (is_array($user['groups'] ?? null) ? $user['groups'] : [] as $group) {
            if (!is_array($group) || $this->scalar($group['type'] ?? '') !== 'direct') {
                continue;
            }
            $id = $this->scalar($group['value'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function enterprise(array $group): array
    {
        $value = $group[self::GROUP_ENTERPRISE_SCHEMA] ?? [];
        return is_array($value) ? $value : [];
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
