<?php

namespace Tests\Unit;

use App\Scim\ScimClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScimClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dootask.scim.server_url' => 'https://auth.example.com',
            'dootask.scim.client_id' => 'dootask',
            'dootask.scim.client_secret' => 'client-secret',
        ]);
    }

    public function test_get_user_refreshes_token_once_after_unauthorized_response(): void
    {
        $tokenRequests = 0;
        $userRequests = 0;
        Http::fake(function (Request $request) use (&$tokenRequests, &$userRequests) {
            if ($request->url() === 'https://auth.example.com/oauth/token') {
                $tokenRequests++;
                return Http::response([
                    'access_token' => 'token-' . $tokenRequests,
                    'expires_in' => 1800,
                ]);
            }

            $userRequests++;
            if ($userRequests === 1) {
                return Http::response([], 401);
            }
            return Http::response(['id' => 'user-1', 'userName' => '066182']);
        });

        $client = new ScimClient();
        $client->clearTokenCache();
        $user = $client->getUserByUri('/Users/user-1');

        $this->assertSame('user-1', $user['id']);
        $this->assertSame(2, $tokenRequests);
        $this->assertSame(2, $userRequests);
    }
}
