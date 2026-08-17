<?php

namespace App\Scim;

use App\Models\User;

/**
 * 预同步 SCIM Group 的负责人和协管人员。
 *
 * Group 中的 value 仅作为稳定 SCIM User ID；用户资料以 /Users/{id} 或本次 Users 列表为准。
 * 用户创建继续复用 ScimUserMapper -> User::createByAdmin，不直接写 users 表。
 */
class ScimPrincipalSynchronizer
{
    /**
     * @param array<int,array> $groups
     * @param array<int,array> $scimUsers
     */
    public function sync(array $groups, array $scimUsers, ScimClient $client): array
    {
        $usersById = [];
        foreach ($scimUsers as $scimUser) {
            if (!is_array($scimUser)) {
                continue;
            }
            $id = $this->scalar($scimUser['id'] ?? '');
            if ($id !== '') {
                $usersById[$id] = $scimUser;
            }
        }

        $references = $this->collectReferences($groups);
        $localUserIds = [];
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        foreach ($references as $scimUserId => $contexts) {
            $scimUser = $usersById[$scimUserId] ?? null;
            if (!is_array($scimUser)
                || !array_key_exists('active', $scimUser)
                || ScimUserMapper::extractPrimaryEmail($scimUser) === '') {
                try {
                    $scimUser = $client->getUserById($scimUserId);
                } catch (ScimResourceNotFoundException $e) {
                    throw new \RuntimeException(
                        "SCIM 负责人或协管用户不存在: {$scimUserId} (" . implode(', ', $contexts) . ')',
                        0,
                        $e
                    );
                }
            }
            if ($this->scalar($scimUser['id'] ?? '') !== $scimUserId) {
                throw new \RuntimeException("SCIM 负责人用户 ID 响应不一致: {$scimUserId}");
            }
            if (($scimUser['active'] ?? null) !== true) {
                throw new \RuntimeException(
                    "SCIM 负责人或协管用户已停用: {$scimUserId} (" . implode(', ', $contexts) . ')'
                );
            }

            $email = ScimUserMapper::extractPrimaryEmail($scimUser);
            if ($email === '') {
                throw new \RuntimeException(
                    "SCIM 负责人或协管用户缺少唯一有效邮箱: {$scimUserId} (" . implode(', ', $contexts) . ')'
                );
            }

            $result = ScimUserMapper::sync($scimUser, [], true);
            if ($result === 'failed') {
                throw new \RuntimeException(
                    "SCIM 负责人或协管用户预同步失败: {$email} (" . implode(', ', $contexts) . ')'
                );
            }
            $stats[$result]++;
            $stats['total']++;

            $matches = User::whereEmail($email)->get();
            if ($matches->count() !== 1) {
                throw new \RuntimeException("SCIM 负责人或协管邮箱无法唯一匹配本地用户: {$email}");
            }
            $user = $matches->first();
            if ($user->isDisable(true)) {
                throw new \RuntimeException("SCIM 负责人或协管对应的本地用户已停用: {$email}");
            }
            $localUserIds[$scimUserId] = (int)$user->userid;
        }

        return [
            'local_user_ids' => $localUserIds,
            'stats' => $stats,
        ];
    }

    /**
     * @param array<int,array> $groups
     * @return array<string,array<int,string>>
     */
    private function collectReferences(array $groups): array
    {
        $references = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new \RuntimeException('SCIM Group 资源格式错误');
            }
            $groupId = $this->scalar($group['id'] ?? '');
            $groupName = $this->scalar($group['displayName'] ?? $groupId);
            if ($groupId === '') {
                throw new \RuntimeException('SCIM Group 缺少 ID');
            }

            // UniAuthSync 将负责人放在 admins 字段；若 owners 存在则优先，否则以 admins[0] 为 owner
            $rawOwners = $group['owners'] ?? [];
            $rawAdmins = $group['admins'] ?? [];
            if (empty($rawOwners) && !empty($rawAdmins)) {
                $rawOwners = [reset($rawAdmins)];
                $rawAdmins = array_slice($rawAdmins, 1);
            }
            $owners = $this->references($rawOwners);
            if (count($owners) > 1) {
                throw new \RuntimeException(
                    "SCIM Group 最多配置一名负责人: {$groupName} ({$groupId}), 实际 " . count($owners) . ' 名'
                );
            }
            foreach ($owners as $userId) {
                $references[$userId][] = "Group {$groupName} owner";
            }
            foreach ($this->references($rawAdmins) as $userId) {
                if (in_array($userId, $owners, true)) {
                    continue;
                }
                $references[$userId][] = "Group {$groupName} admin";
            }
        }
        return $references;
    }

    /**
     * @return array<int,string>
     */
    private function references(mixed $items): array
    {
        if ($items === null) {
            return [];
        }
        if (!is_array($items)) {
            throw new \RuntimeException('SCIM Group 负责人或协管字段格式错误');
        }

        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('SCIM Group 用户引用格式错误');
            }
            $id = $this->scalar($item['value'] ?? '');
            if ($id === '') {
                throw new \RuntimeException('SCIM Group 用户引用缺少 value');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
