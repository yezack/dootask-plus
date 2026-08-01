<?php

namespace App\Scim;

use Illuminate\Support\Facades\Cache;

/**
 * SCIM 外部用户 ID 到唯一邮箱的 Redis/Cache 映射。
 *
 * 不修改数据库结构。映射仅用于 delete Webhook 在上游资源已删除时定位本地账号，
 * 正常同步和 OIDC 登录仍以 SCIM 明示唯一邮箱为主匹配键。
 */
class ScimIdentityMap
{
    private const PREFIX = 'scim:user-email:';

    public static function remember(array $scimUser): void
    {
        $externalId = self::externalId($scimUser);
        $email = ScimUserMapper::extractEmail($scimUser);
        if ($externalId === '' || $email === '') {
            return;
        }

        $key = self::key($externalId);
        $existing = Cache::get($key);
        if (is_string($existing) && $existing !== '' && !hash_equals(strtolower($existing), strtolower($email))) {
            throw new \RuntimeException("SCIM 用户 ID 拒绝重新绑定到其他邮箱: {$externalId}");
        }
        Cache::forever($key, $email);
    }

    public static function email(string $externalId): string
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return '';
        }

        $email = Cache::get(self::key($externalId));
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower($email)
            : '';
    }

    public static function forget(string $externalId): void
    {
        $externalId = trim($externalId);
        if ($externalId !== '') {
            Cache::forget(self::key($externalId));
        }
    }

    private static function externalId(array $scimUser): string
    {
        $value = $scimUser['id'] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function key(string $externalId): string
    {
        return self::PREFIX . hash('sha256', $externalId);
    }
}
