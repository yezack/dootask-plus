<?php

namespace App\Scim;

use App\Module\Base;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SCIM HTTP 客户端 — 对接 UniAuthSync
 *
 * 负责 OAuth token 获取、SCIM Users 拉取、分页遍历。
 * Token 缓存到 Laravel Cache，失效前 60 秒自动刷新。
 */
class ScimClient
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl      = rtrim(env('SCIM_SERVER_URL', 'http://127.0.0.1:8000'), '/');
        $this->clientId     = env('SCIM_CLIENT_ID', '');
        $this->clientSecret = env('SCIM_CLIENT_SECRET', '');
    }

    // ---------- token ----------

    public function getAccessToken(): string
    {
        return Cache::remember('scim:access_token', 1740, function () {
            $resp = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
            if (!$resp->successful()) {
                throw new \RuntimeException('SCIM token 获取失败: ' . $resp->body());
            }
            return $resp->json()['access_token'];
        });
    }

    public function clearTokenCache(): void
    {
        Cache::forget('scim:access_token');
    }

    // ---------- SCIM ----------

    /**
     * 分页遍历所有 SCIM Users
     * @return \Generator<array>
     */
    public function listUsers(): \Generator
    {
        $token  = $this->getAccessToken();
        $index  = 1;
        $count  = 100;
        $client = Http::withToken($token)->timeout(30);

        do {
            $resp = $client->get("{$this->baseUrl}/scim/v2/Users", [
                'startIndex' => $index,
                'count'      => $count,
            ]);
            if (!$resp->successful()) {
                throw new \RuntimeException('SCIM Users 拉取失败: ' . $resp->body());
            }
            $data = $resp->json();
            foreach ($data['Resources'] ?? [] as $user) {
                yield $user;
            }
            $total = (int)($data['totalResults'] ?? 0);
            $index += $count;
        } while ($index <= $total);
    }
}
