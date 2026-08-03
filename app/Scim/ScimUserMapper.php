<?php

namespace App\Scim;

use App\Models\User;
use App\Models\UserDepartment;
use App\Models\WebSocketDialog;
use App\Module\Base;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCIM → Dootask 字段映射
 *
 * 负责将 SCIM 用户数据映射为 Dootask 用户字段，执行 create 或 update。
 * 匹配键：email（066182@sjq.sh）
 */
class ScimUserMapper
{
    /**
     * 映射并同步单个用户
     * @param array $scimUser SCIM 接口返回的原始用户数据
     * @return string  'created' | 'updated' | 'skipped' | 'failed'
     */
    public static function sync(
        array $scimUser,
        ?array $groupDepartmentIds = null,
        bool $allowWithoutDepartment = false
    ): string
    {
        $email = self::extractEmail($scimUser);
        if (empty($email)) {
            Log::warning('SCIM: 跳过无邮箱用户', ['userName' => $scimUser['userName'] ?? '?']);
            return 'skipped';
        }

        $attrs = self::map($scimUser);
        $localUsers = User::whereEmail($email)->get();
        if ($localUsers->count() > 1) {
            Log::error('SCIM: 本地邮箱匹配不唯一', ['email' => $email, 'matches' => $localUsers->count()]);
            return 'failed';
        }
        $user = $localUsers->first();
        ScimIdentityMap::remember($scimUser);

        // 停用优先于资料校验，避免异常资料阻断离职/删除事件。
        if ($attrs['disable_at']) {
            if (!$user) {
                Log::info('SCIM: 跳过本地不存在的停用用户', ['email' => $email]);
                return 'skipped';
            }
            try {
                return self::disableUser($user, $email);
            } catch (\Throwable $e) {
                Log::error('SCIM: 停用用户失败', ['email' => $email, 'error' => $e->getMessage()]);
                return 'failed';
            }
        }

        $nicknameLength = mb_strlen($attrs['nickname']);
        if ($nicknameLength < 2 || $nicknameLength > 20) {
            Log::error('SCIM: 昵称长度无效', ['email' => $email, 'nickname' => $attrs['nickname']]);
            return 'failed';
        }
        try {
            User::assertValidProfession($attrs['profession'] ?? '');
        } catch (\Throwable $e) {
            Log::error('SCIM: 职位无效', ['email' => $email, 'error' => $e->getMessage()]);
            return 'failed';
        }
        if (mb_strlen($attrs['tel']) > 50) {
            Log::error('SCIM: 电话长度无效', ['email' => $email]);
            return 'failed';
        }

        $departmentIds = config('dootask.scim.sync_department', true)
            ? self::resolveDepartmentIds($attrs['direct_group_ids'], $groupDepartmentIds)
            : [];
        $hasExistingDepartment = $user && $user->department !== [];
        if (!$allowWithoutDepartment
            && config('dootask.scim.sync_department', true)
            && config('dootask.scim.require_department', true)
            && $departmentIds === []
            && !$hasExistingDepartment) {
            Log::error('SCIM: 用户没有可用的直接分组部门映射', [
                'email' => $email,
                'direct_group_ids' => $attrs['direct_group_ids'],
            ]);
            return 'failed';
        }

        if ($user) {
            try {
                return self::updateUser($user, $attrs, $departmentIds, $email, $allowWithoutDepartment);
            } catch (\Throwable $e) {
                Log::error('SCIM: 更新用户失败', ['email' => $email, 'error' => $e->getMessage()]);
                return 'failed';
            }
        }

        // create — 使用 createByAdmin 复用官方流程
        $passwordPrefix = (string)config('dootask.scim.default_password_prefix', '');
        $userName = is_scalar($scimUser['userName'] ?? null) ? (string)$scimUser['userName'] : 'user';
        $password = $passwordPrefix !== ''
            ? $passwordPrefix . $userName
            : 'Aa1!' . bin2hex(random_bytes(14));
        $options = [
            'changePass'  => false,
            'emailVerity' => false,
            'profession'  => $attrs['profession'] ?? '',
            'department'  => $departmentIds,
        ];
        try {
            $user = User::createByAdmin($email, $password, $attrs['nickname'], $options);
            $changed = false;
            if ($attrs['tel'] !== '' && $attrs['tel'] !== $user->tel) {
                if (User::whereTel($attrs['tel'])->where('userid', '!=', $user->userid)->exists()) {
                    Log::warning('SCIM: 电话已被其他用户使用', ['email' => $email, 'tel' => $attrs['tel']]);
                } else {
                    $user->tel = $attrs['tel'];
                    $changed = true;
                }
            }
            if ($changed) {
                $user->save();
            }
            Log::info('SCIM: 创建用户', ['email' => $email, 'nickname' => $attrs['nickname']]);
            return 'created';
        } catch (\Throwable $e) {
            Log::error('SCIM: 创建用户失败', ['email' => $email, 'error' => $e->getMessage()]);
            return 'failed';
        }
    }

    /**
     * 上游 delete 事件已无法读取 SCIM User 时，按此前缓存的唯一邮箱停用本地用户。
     */
    public static function disableByExternalId(string $externalId): string
    {
        $email = ScimIdentityMap::email($externalId);
        if ($email === '') {
            Log::warning('SCIM: 删除事件缺少可用的外部身份映射', ['external_id' => $externalId]);
            return 'failed';
        }

        $localUsers = User::whereEmail($email)->get();
        if ($localUsers->count() !== 1) {
            Log::error('SCIM: 删除事件邮箱无法唯一匹配本地用户', [
                'external_id' => $externalId,
                'email' => $email,
                'matches' => $localUsers->count(),
            ]);
            return 'failed';
        }

        try {
            return self::disableUser($localUsers->first(), $email);
        } catch (\Throwable $e) {
            Log::error('SCIM: 删除事件停用用户失败', [
                'external_id' => $externalId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        }
    }

    // ---------- mapping ----------

    /**
     * 将 SCIM 数据映射为 Dootask 字段
     */
    public static function map(array $scimUser): array
    {
        $enterprise = $scimUser['urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'] ?? [];
        $custom     = $scimUser['urn:ietf:params:scim:schemas:extension:custom:2.0:User'] ?? [];
        $enterprise = is_array($enterprise) ? $enterprise : [];
        $custom = is_array($custom) ? $custom : [];

        // identity → profession（警察/辅警/协勤）
        $identityName = self::trimScalar($enterprise['division'] ?? '');
        $positionName = self::trimScalar($enterprise['title'] ?? '');

        // profession = "身份 - 职务"（如 "警察 - 中队长"）
        $profession = implode(' - ', array_filter([$identityName, $positionName])) ?: null;

        $department = self::trimScalar($enterprise['department'] ?? '');
        $directGroupIds = [];
        $groups = is_array($scimUser['groups'] ?? null) ? $scimUser['groups'] : [];
        foreach ($groups as $group) {
            if (!is_array($group) || self::trimScalar($group['type'] ?? '') !== 'direct') {
                continue;
            }
            $groupId = self::trimScalar($group['value'] ?? '');
            if ($groupId !== '') {
                $directGroupIds[] = $groupId;
            }
        }
        $directGroupIds = array_values(array_unique($directGroupIds));

        return [
            'nickname'   => self::trimScalar($scimUser['displayName'] ?? $scimUser['userName'] ?? ''),
            'profession' => $profession,
            'department' => $department,
            'direct_group_ids' => $directGroupIds,
            'tel'        => self::trimScalar($custom['phone'] ?? ''),
            'disable_at' => ($scimUser['active'] ?? true) === false ? now() : null,
            // 保留原始数据用于日志/扩展
            '_scim' => [
                'external_id' => $scimUser['id'] ?? '',
                'user_name'   => $scimUser['userName'] ?? '',
                'identity'    => $identityName,
                'position'    => $positionName,
                'org'         => $department,
                'org_id'      => $enterprise['organization'] ?? '',
                'phone'       => $custom['phone'] ?? '',
                'id_card'     => $custom['id_card'] ?? '',
            ],
        ];
    }

    // ---------- helpers ----------

    /**
     * 负责人等强关系使用：必须存在且只能存在一个 primary email。
     */
    public static function extractPrimaryEmail(array $scimUser): string
    {
        $emails = is_array($scimUser['emails'] ?? null) ? $scimUser['emails'] : [];
        $primary = [];
        foreach ($emails as $email) {
            if (!is_array($email) || ($email['primary'] ?? false) !== true) {
                continue;
            }
            $value = strtolower(self::trimScalar($email['value'] ?? ''));
            if ($value !== '' && Base::isEmail($value)) {
                $primary[$value] = true;
            }
        }
        return count($primary) === 1 ? array_key_first($primary) : '';
    }

    public static function extractEmail(array $scimUser): string
    {
        $emails = is_array($scimUser['emails'] ?? null) ? $scimUser['emails'] : [];
        $valid = [];
        $primary = [];
        foreach ($emails as $email) {
            if (!is_array($email)) {
                continue;
            }
            $value = strtolower(self::trimScalar($email['value'] ?? ''));
            if ($value === '' || !Base::isEmail($value)) {
                continue;
            }
            $valid[$value] = true;
            if (($email['primary'] ?? false) === true) {
                $primary[$value] = true;
            }
        }
        if (count($primary) === 1) {
            return array_key_first($primary);
        }
        if (count($primary) > 1 || count($valid) > 1) {
            return '';
        }
        if (count($valid) === 1) {
            return array_key_first($valid);
        }
        // fallback: userName + 默认域名
        $userName = self::trimScalar($scimUser['userName'] ?? '');
        if ($userName && str_contains($userName, '@') && Base::isEmail($userName)) {
            return $userName;
        }
        $fallback = $userName ? $userName . '@sjq.sh' : '';
        return Base::isEmail($fallback) ? $fallback : '';
    }

    private static function trimScalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function updateUser(
        User $user,
        array $attrs,
        array $departmentIds,
        string $email,
        bool $allowWithoutDepartment
    ): string
    {
        $oldDepartmentIds = $user->department;
        $changed = false;

        if ($attrs['nickname'] !== $user->nickname) {
            $user->nickname = $attrs['nickname'];
            $user->az = Base::getFirstCharter($attrs['nickname']);
            $user->pinyin = Base::cn2pinyin($attrs['nickname']);
            $changed = true;
        }
        if ($attrs['profession'] !== null && $attrs['profession'] !== $user->profession) {
            $user->profession = $attrs['profession'];
            $changed = true;
        }
        if ($attrs['tel'] !== '' && $attrs['tel'] !== $user->tel) {
            $duplicate = User::whereTel($attrs['tel'])->where('userid', '!=', $user->userid)->exists();
            if (!$duplicate) {
                $user->tel = $attrs['tel'];
                $changed = true;
            } else {
                Log::warning('SCIM: 电话已被其他用户使用', ['email' => $email, 'tel' => $attrs['tel']]);
            }
        }

        $identity = $user->identity;
        if (config('dootask.scim.reactivate_users', false)
            && ($user->disable_at || in_array('disable', $identity, true))) {
            $user->disable_at = null;
            $user->identity = Base::arrayImplode(array_diff($identity, ['disable']));
            $changed = true;
        }

        $targetDepartmentIds = $oldDepartmentIds;
        if (!$allowWithoutDepartment && config('dootask.scim.replace_departments', false)) {
            // 主负责人和协管关系由部门模型维护，不能被普通成员关系替换逻辑移除。
            $requiredDepartmentIds = self::requiredManagedDepartmentIds((int)$user->userid);
            $targetDepartmentIds = self::targetDepartmentIds(
                $oldDepartmentIds,
                $departmentIds,
                $requiredDepartmentIds,
                true
            );
        } elseif ($departmentIds !== []) {
            $targetDepartmentIds = self::targetDepartmentIds(
                $oldDepartmentIds,
                $departmentIds,
                [],
                false
            );
        }
        $oldDepartmentIdsSorted = $oldDepartmentIds;
        sort($oldDepartmentIdsSorted);
        if ($targetDepartmentIds !== $oldDepartmentIdsSorted) {
            $user->department = Base::arrayImplode($targetDepartmentIds);
            $changed = true;
        }
        if (!$changed) {
            return 'skipped';
        }

        DB::transaction(function () use ($user, $oldDepartmentIds, $targetDepartmentIds) {
            $user->save();
            if ($targetDepartmentIds !== $oldDepartmentIds) {
                self::syncDepartmentGroups($user->userid, $oldDepartmentIds, $targetDepartmentIds);
            }
        });
        Log::info('SCIM: 更新用户', ['email' => $email]);
        return 'updated';
    }

    private static function disableUser(User $user, string $email): string
    {
        $identity = $user->identity;
        if ($user->disable_at && in_array('disable', $identity, true)) {
            return 'skipped';
        }

        if (!$user->disable_at) {
            $user->disable_at = now();
        }
        if (!in_array('disable', $identity, true)) {
            $identity[] = 'disable';
            $user->identity = Base::arrayImplode(array_unique($identity));
        }
        $user->save();

        Log::info('SCIM: 停用用户', ['email' => $email]);
        return 'updated';
    }

    private static function requiredManagedDepartmentIds(int $userid): array
    {
        if ($userid <= 0) {
            return [];
        }
        $primary = UserDepartment::whereOwnerUserid($userid)->pluck('id')->map(fn($id) => (int)$id)->toArray();
        $deputy = DB::table('user_department_owners')
            ->where('userid', $userid)
            ->pluck('department_id')
            ->map(fn($id) => (int)$id)
            ->toArray();
        return array_values(array_unique(array_merge($primary, $deputy)));
    }

    private static function targetDepartmentIds(
        array $oldDepartmentIds,
        array $syncedDepartmentIds,
        array $requiredManagedDepartmentIds,
        bool $replace
    ): array {
        $ids = $replace
            ? array_merge($syncedDepartmentIds, $requiredManagedDepartmentIds)
            : array_merge($oldDepartmentIds, $syncedDepartmentIds);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    private static function resolveDepartmentIds(array $directGroupIds, ?array $groupDepartmentIds): array
    {
        $departmentIds = [];
        foreach ($directGroupIds as $groupId) {
            $departmentId = (int)($groupDepartmentIds[$groupId]
                ?? ScimDepartmentSynchronizer::cachedGroupDepartmentId($groupId)
                ?? 0);
            if ($departmentId > 0 && UserDepartment::whereId($departmentId)->exists()) {
                $departmentIds[] = $departmentId;
            }
        }
        return array_values(array_unique($departmentIds));
    }

    private static function syncDepartmentGroups(int $userid, array $oldIds, array $newIds): void
    {
        foreach (UserDepartment::whereIn('id', array_diff($oldIds, $newIds))->get() as $department) {
            if ($department->dialog_id > 0 && $dialog = WebSocketDialog::find($department->dialog_id)) {
                $dialog->exitGroup([$userid], 'remove', false);
                $dialog->pushMsg('groupExit', null, [$userid]);
            }
        }
        foreach (UserDepartment::whereIn('id', array_diff($newIds, $oldIds))->get() as $department) {
            if ($department->dialog_id > 0 && $dialog = WebSocketDialog::find($department->dialog_id)) {
                $dialog->joinGroup([$userid], 0, true);
                $dialog->pushMsg('groupJoin', null, [$userid]);
            }
        }
    }
}
