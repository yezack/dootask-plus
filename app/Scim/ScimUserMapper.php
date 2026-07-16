<?php

namespace App\Scim;

use App\Models\User;
use App\Module\Base;
use Illuminate\Support\Facades\Log;

/**
 * SCIM → Dootask 字段映射
 *
 * 负责将 SCIM 用户数据映射为 Dootask 用户字段，执行 create 或 update。
 * 匹配键：email（066182@sjq.sh）
 */
class ScimUserMapper
{
    /** @var array 身份/职位/组织字典缓存 */
    private static array $dictCache = [];

    /**
     * 映射并同步单个用户
     * @param array $scimUser SCIM 接口返回的原始用户数据
     * @param array $dict     可选字典缓存 { identities: {}, positions: {}, orgs: {} }
     * @return string  'created' | 'updated' | 'skipped'
     */
    public static function sync(array $scimUser, array $dict = []): string
    {
        self::$dictCache = $dict ?: self::$dictCache;

        $email = self::extractEmail($scimUser);
        if (empty($email)) {
            Log::warning('SCIM: 跳过无邮箱用户', ['userName' => $scimUser['userName'] ?? '?']);
            return 'skipped';
        }

        $attrs = self::map($scimUser);

        $user = User::whereEmail($email)->first();
        if ($user) {
            // update
            $changed = false;
            if ($attrs['nickname'] !== $user->nickname) {
                $user->nickname = $attrs['nickname'];
                $changed = true;
            }
            if ($attrs['profession'] !== null && $attrs['profession'] !== $user->profession) {
                $user->profession = $attrs['profession'];
                $changed = true;
            }
            if (($attrs['disable_at'] ? 1 : 0) !== ($user->disable_at ? 1 : 0)) {
                $user->disable_at = $attrs['disable_at'];
                $changed = true;
            }
            if ($changed) {
                $user->save();
                Log::info('SCIM: 更新用户', ['email' => $email, 'fields' => array_keys(array_filter($attrs))]);
                return 'updated';
            }
            return 'skipped';
        }

        // create — 使用 createByAdmin 复用官方流程
        $password = env('SCIM_DEFAULT_PASSWORD_PREFIX', 'sjq@') . ($scimUser['userName'] ?? 'user');
        $options = [
            'changePass'  => true,
            'emailVerity' => false,
            'profession'  => $attrs['profession'] ?? '',
        ];
        try {
            User::createByAdmin($email, $password, $attrs['nickname'], $options);
            Log::info('SCIM: 创建用户', ['email' => $email, 'nickname' => $attrs['nickname']]);
            return 'created';
        } catch (\Throwable $e) {
            Log::error('SCIM: 创建用户失败', ['email' => $email, 'error' => $e->getMessage()]);
            return 'skipped';
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

        // identity → profession（警察/辅警/协勤）
        $identityName = $enterprise['division'] ?? '';
        $positionName = $enterprise['title'] ?? '';

        // profession = "身份 - 职务"（如 "警察 - 中队长"）
        $profession = implode(' - ', array_filter([$identityName, $positionName])) ?: null;

        // department = org name（松江分局）
        $department = $enterprise['department'] ?? '';

        return [
            'nickname'   => $scimUser['displayName'] ?? $scimUser['userName'],
            'profession' => $profession,
            'department' => $department,
            'disable_at' => ($scimUser['active'] ?? true) ? null : now(),
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

    private static function extractEmail(array $scimUser): string
    {
        $emails = $scimUser['emails'] ?? [];
        foreach ($emails as $e) {
            if (!empty($e['value']) && Base::isEmail($e['value'])) {
                return $e['value'];
            }
        }
        // fallback: userName + 默认域名
        $userName = $scimUser['userName'] ?? '';
        if ($userName && str_contains($userName, '@')) {
            return $userName;
        }
        return $userName ? $userName . '@sjq.sh' : '';
    }
}
