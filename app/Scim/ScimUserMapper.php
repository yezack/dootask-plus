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
    public static function sync(array $scimUser): string
    {
        $email = self::extractEmail($scimUser);
        if (empty($email)) {
            Log::warning('SCIM: 跳过无邮箱用户', ['userName' => $scimUser['userName'] ?? '?']);
            return 'skipped';
        }

        $attrs = self::map($scimUser);
        $user = User::whereEmail($email)->first();

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

        if ($user) {
            try {
                return self::updateUser($user, $attrs, $email);
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
            'changePass'  => true,
            'emailVerity' => false,
            'profession'  => $attrs['profession'] ?? '',
            'department'  => config('dootask.scim.sync_department', true)
                ? self::resolveDepartmentIds($attrs['department'])
                : [],
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

        // department = org name（松江分局）
        $department = self::trimScalar($enterprise['department'] ?? '');

        return [
            'nickname'   => self::trimScalar($scimUser['displayName'] ?? $scimUser['userName'] ?? ''),
            'profession' => $profession,
            'department' => $department,
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

    public static function extractEmail(array $scimUser): string
    {
        $emails = $scimUser['emails'] ?? [];
        $emails = is_array($emails) ? $emails : [];
        foreach ($emails as $e) {
            if (!is_array($e)) {
                continue;
            }
            $value = self::trimScalar($e['value'] ?? '');
            if ($value !== '' && Base::isEmail($value)) {
                return $value;
            }
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

    private static function updateUser(User $user, array $attrs, string $email): string
    {
        $departmentIds = config('dootask.scim.sync_department', true)
            ? self::resolveDepartmentIds($attrs['department'])
            : [];
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

        $canUpdateDepartment = $departmentIds !== []
            && ($oldDepartmentIds === [] || config('dootask.scim.replace_departments', false));
        if ($canUpdateDepartment && $departmentIds !== $oldDepartmentIds) {
            $user->department = Base::arrayImplode($departmentIds);
            $changed = true;
        }
        if (!$changed) {
            return 'skipped';
        }

        DB::transaction(function () use ($user, $oldDepartmentIds, $departmentIds) {
            $user->save();
            if ($departmentIds !== [] && ($oldDepartmentIds === [] || config('dootask.scim.replace_departments', false))
                && $departmentIds !== $oldDepartmentIds) {
                self::syncDepartmentGroups($user->userid, $oldDepartmentIds, $departmentIds);
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

    private static function resolveDepartmentIds(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $departments = UserDepartment::whereName($name)->get(['id']);
        if ($departments->count() !== 1) {
            Log::warning('SCIM: 部门名无法唯一匹配', ['name' => $name, 'matches' => $departments->count()]);
            return [];
        }
        return [(int)$departments->first()->id];
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
