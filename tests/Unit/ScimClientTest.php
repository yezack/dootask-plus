<?php

namespace Tests\Unit;

use App\Scim\ScimClient;
use App\Scim\ScimResourceNotFoundException;
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

    public function test_lists_organizations_and_groups_with_scim_pagination(): void
    {
        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'token-1',
                'expires_in' => 1800,
            ]),
            'https://auth.example.com/scim/v2/Organizations*' => Http::response([
                'totalResults' => 1,
                'Resources' => [['id' => 'org-1', 'displayName' => '松江分局']],
            ]),
            'https://auth.example.com/scim/v2/Groups*' => Http::response([
                'totalResults' => 1,
                'Resources' => [['id' => 'group-1', 'displayName' => '数据应用']],
            ]),
        ]);

        $client = new ScimClient();
        $client->clearTokenCache();

        $this->assertSame('org-1', iterator_to_array($client->listOrganizations(), false)[0]['id']);
        $this->assertSame('group-1', iterator_to_array($client->listGroups(), false)[0]['id']);
    }

    public function test_finds_one_user_by_email(): void
    {
        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'token-1',
                'expires_in' => 1800,
            ]),
            'https://auth.example.com/scim/v2/Users*' => Http::response([
                'totalResults' => 1,
                'Resources' => [['id' => 'user-1', 'userName' => '050846']],
            ]),
        ]);

        $client = new ScimClient();
        $client->clearTokenCache();
        $this->assertSame('user-1', $client->findUserByEmail('050846@sjq.sh')['id']);
    }

    public function test_get_user_throws_typed_not_found_exception(): void
    {
        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'token-1',
                'expires_in' => 1800,
            ]),
            'https://auth.example.com/scim/v2/Users/user-404' => Http::response([], 404),
        ]);

        $client = new ScimClient();
        $client->clearTokenCache();
        $this->expectException(ScimResourceNotFoundException::class);
        $client->getUserById('user-404');
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
