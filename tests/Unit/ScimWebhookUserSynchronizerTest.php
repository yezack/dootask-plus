<?php

namespace Tests\Unit;

use App\Scim\ScimClient;
use App\Scim\ScimDepartmentSynchronizer;
use App\Scim\ScimResourceNotFoundException;
use App\Scim\ScimWebhookUserSynchronizer;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScimWebhookUserSynchronizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_delete_not_found_is_not_downgraded_to_delete_fallback(): void
    {
        $client = Mockery::mock(ScimClient::class);
        $client->shouldReceive('getUserByUri')
            ->once()
            ->with('/Users/user-1')
            ->andThrow(new ScimResourceNotFoundException('missing'));

        $this->expectException(ScimResourceNotFoundException::class);
        (new ScimWebhookUserSynchronizer($client))->sync('/Users/user-1', [
            'urn:ietf:params:scim:event:prov:patch:notice',
        ]);
    }

    public function test_delete_without_identity_mapping_fails_for_retry(): void
    {
        $client = Mockery::mock(ScimClient::class);
        $client->shouldReceive('getUserByUri')
            ->once()
            ->with('/Users/user-not-cached')
            ->andThrow(new ScimResourceNotFoundException('missing'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('同步失败');
        (new ScimWebhookUserSynchronizer($client))->sync('/Users/user-not-cached', [
            'urn:ietf:params:scim:event:prov:delete',
        ]);
    }

    public function test_group_patch_delegates_to_department_synchronizer(): void
    {
        $client = Mockery::mock(ScimClient::class);
        $departmentSync = Mockery::mock(ScimDepartmentSynchronizer::class);
        $departmentSync->shouldReceive('syncSingleGroup')
            ->once()
            ->with($client, 'group-1')
            ->andReturn(['updated' => true, 'department_id' => 9, 'error' => null]);
        $this->app->instance(ScimDepartmentSynchronizer::class, $departmentSync);

        $result = (new ScimWebhookUserSynchronizer($client))->sync('/Groups/group-1', [
            'urn:ietf:params:scim:event:prov:patch:notice',
        ]);

        $this->assertSame('group-updated', $result);
    }

    public function test_group_delete_is_acknowledged_without_fetching_deleted_resource(): void
    {
        $client = Mockery::mock(ScimClient::class);
        $client->shouldNotReceive('getGroupById');

        $result = (new ScimWebhookUserSynchronizer($client))->sync('/Groups/group-1', [
            'urn:ietf:params:scim:event:prov:delete',
        ]);

        $this->assertSame('group-delete-recorded', $result);
    }
}
