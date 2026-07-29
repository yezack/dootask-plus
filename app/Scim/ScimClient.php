<?php

namespace App\Scim;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SCIM HTTP 客户端 — 对接 UniAuthSync
 *
 * 负责 OAuth token 获取、SCIM Users / Groups / Organizations 拉取、分页遍历。
 * Token 缓存到 Laravel Cache，失效前 60 秒自动刷新。
 */
class ScimClient
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl      = rtrim((string)config('dootask.scim.server_url', ''), '/');
        $this->clientId     = (string)config('dootask.scim.client_id', '');
        $this->clientSecret = (string)config('dootask.scim.client_secret', '');

        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('SCIM 配置不完整，请检查 SCIM_SERVER_URL、SCIM_CLIENT_ID 和 SCIM_CLIENT_SECRET');
        }
    }

    // ---------- token ----------

    public function getAccessToken(): string
    {
        $cacheKey = 'scim:access_token:' . hash('sha256', $this->baseUrl . '|' . $this->clientId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resp = Http::asForm()->timeout(30)->post("{$this->baseUrl}/oauth/token", [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        if (!$resp->successful()) {
            throw new \RuntimeException('SCIM token 获取失败: ' . $resp->body());
        }

        $token = (string)$resp->json('access_token', '');
        if ($token === '') {
            throw new \RuntimeException('SCIM token 响应缺少 access_token');
        }
        $ttl = max(1, (int)$resp->json('expires_in', 1800) - 60);
        Cache::put($cacheKey, $token, $ttl);
        return $token;
    }

    public function clearTokenCache(): void
    {
        Cache::forget('scim:access_token:' . hash('sha256', $this->baseUrl . '|' . $this->clientId));
    }

    // ---------- SCIM ----------

    /**
     * 分页遍历所有 SCIM Users
     * @return \Generator<array>
     */
    public function listUsers(): \Generator
    {
        yield from $this->listResources('/scim/v2/Users', 'Users');
    }

    /**
     * 分页遍历授权范围内的 SCIM Groups
     * @return \Generator<array>
     */
    public function listGroups(): \Generator
    {
        yield from $this->listResources('/scim/v2/Groups', 'Groups');
    }

    /**
     * 分页遍历授权范围内的 SCIM Organizations
     * @return \Generator<array>
     */
    public function listOrganizations(): \Generator
    {
        yield from $this->listResources('/scim/v2/Organizations', 'Organizations');
    }

    public function findUserByEmail(string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('SCIM 用户邮箱无效');
        }

        $resp = $this->get('/scim/v2/Users', [
            'filter' => 'email eq "' . $email . '"',
            'startIndex' => 1,
            'count' => 2,
        ]);
        if (!$resp->successful()) {
            throw new \RuntimeException('SCIM 用户查询失败: ' . $resp->body());
        }
        $data = $resp->json();
        $resources = is_array($data) && is_array($data['Resources'] ?? null)
            ? $data['Resources']
            : null;
        if ($resources === null || count($resources) > 1) {
            throw new \RuntimeException('SCIM 用户邮箱查询结果不唯一或格式错误');
        }
        return $resources[0] ?? null;
    }

    public function getUserById(string $id): array
    {
        if ($id === '' || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new \InvalidArgumentException('SCIM 用户 ID 无效');
        }
        return $this->getUserByUri('/Users/' . rawurlencode($id));
    }

    public function getUserByUri(string $uri): array
    {
        if (!preg_match('#^/Users/[A-Za-z0-9._~%+-]+$#', $uri)) {
            throw new \InvalidArgumentException('SCIM 用户 URI 无效');
        }
        return $this->getResource('/scim/v2' . $uri, '用户');
    }

    public function getGroupById(string $id): array
    {
        return $this->getResourceById('/scim/v2/Groups', $id, '分组');
    }

    public function getOrganizationById(string $id): array
    {
        return $this->getResourceById('/scim/v2/Organizations', $id, '组织');
    }

    /**
     * @return \Generator<array>
     */
    private function listResources(string $path, string $label): \Generator
    {
        $index = 1;
        $count = 100;

        do {
            $resp = $this->get($path, [
                'startIndex' => $index,
                'count' => $count,
            ]);
            if (!$resp->successful()) {
                throw new \RuntimeException("SCIM {$label} 拉取失败: " . $resp->body());
            }

            $data = $resp->json();
            if (!is_array($data) || !isset($data['Resources']) || !is_array($data['Resources'])) {
                throw new \RuntimeException("SCIM {$label} 响应格式错误");
            }
            foreach ($data['Resources'] as $resource) {
                if (!is_array($resource) || empty($resource['id'])) {
                    throw new \RuntimeException("SCIM {$label} 资源格式错误");
                }
                yield $resource;
            }

            $total = max(0, (int)($data['totalResults'] ?? 0));
            $returned = count($data['Resources']);
            $index += $returned;
            if ($returned === 0) {
                break;
            }
        } while ($index <= $total);
    }

    private function getResourceById(string $basePath, string $id, string $label): array
    {
        if ($id === '' || !preg_match('/^[A-Za-z0-9._~+-]+$/', $id)) {
            throw new \InvalidArgumentException("SCIM {$label} ID 无效");
        }
        return $this->getResource($basePath . '/' . rawurlencode($id), $label);
    }

    private function getResource(string $path, string $label): array
    {
        $resp = $this->get($path);
        if (!$resp->successful()) {
            throw new \RuntimeException("SCIM {$label}获取失败: " . $resp->body());
        }

        $resource = $resp->json();
        if (!is_array($resource) || empty($resource['id'])) {
            throw new \RuntimeException("SCIM {$label}响应格式错误");
        }
        return $resource;
    }

    private function get(string $path, array $query = []): Response
    {
        $resp = Http::withToken($this->getAccessToken())
            ->timeout(30)
            ->get($this->baseUrl . $path, $query);
        if ($resp->status() === 401) {
            $this->clearTokenCache();
            $resp = Http::withToken($this->getAccessToken())
                ->timeout(30)
                ->get($this->baseUrl . $path, $query);
        }
        return $resp;
    }
}
