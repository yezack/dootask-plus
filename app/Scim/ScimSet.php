<?php

namespace App\Scim;

class ScimSet
{
    public static function parse(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new \InvalidArgumentException('SET 格式错误');
        }

        $header = json_decode(self::base64UrlDecode($segments[0]), true);
        $validTyp = ($header['typ'] ?? '') === 'secevent+jwt';
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || !$validTyp) {
            \Illuminate\Support\Facades\Log::warning('SCIM webhook: SET header 格式错误', [
                'header' => $header,
                'alg' => $header['alg'] ?? 'MISSING',
                'typ' => $header['typ'] ?? 'MISSING',
            ]);
            throw new \InvalidArgumentException('SET header 格式错误');
        }
        if (self::base64UrlDecode($segments[2]) === '') {
            throw new \InvalidArgumentException('SET signature 格式错误');
        }

        $payload = json_decode(self::base64UrlDecode($segments[1]), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('SET payload 格式错误');
        }

        $issuer = (string)config('dootask.scim.issuer', '');
        if ($issuer === '') {
            $issuer = (string)config('dootask.scim.server_url', '');
        }
        $issuer = rtrim($issuer, '/');
        if ($issuer === '' || !hash_equals($issuer, rtrim((string)($payload['iss'] ?? ''), '/'))) {
            throw new \InvalidArgumentException('SET issuer 不匹配');
        }

        $clientId = (string)config('dootask.scim.client_id', '');
        $audience = $payload['aud'] ?? '';
        $audienceValid = is_array($audience)
            ? in_array($clientId, $audience, true)
            : hash_equals($clientId, (string)$audience);
        if ($clientId === '' || !$audienceValid) {
            throw new \InvalidArgumentException('SET audience 不匹配');
        }

        $now = time();
        $issuedAt = (int)($payload['iat'] ?? 0);
        $expiresAt = (int)($payload['exp'] ?? 0);
        if ($issuedAt <= 0 || $expiresAt <= $now || $expiresAt <= $issuedAt || $issuedAt > $now + 60) {
            throw new \InvalidArgumentException('SET 已过期或签发时间无效');
        }
        if (!is_string($payload['jti'] ?? null) || $payload['jti'] === ''
            || !is_array($payload['events'] ?? null) || $payload['events'] === []) {
            throw new \InvalidArgumentException('SET 缺少必需字段');
        }

        $subject = $payload['sub_id'] ?? [];
        if (!is_array($subject) || ($subject['format'] ?? '') !== 'scim'
            || !preg_match('#^/(?:Users|Groups|Organizations)/[A-Za-z0-9._~%+-]+$#', (string)($subject['uri'] ?? ''))) {
            \Illuminate\Support\Facades\Log::warning('SCIM webhook: SET subject 无效', [
                'sub_id' => $subject,
                'uri' => $subject['uri'] ?? 'MISSING',
            ]);
            throw new \InvalidArgumentException('SET subject 无效');
        }

        return $payload;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('SET payload 编码错误');
        }
        return $decoded;
    }
}
