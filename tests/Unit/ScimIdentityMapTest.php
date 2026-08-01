<?php

namespace Tests\Unit;

use App\Scim\ScimIdentityMap;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ScimIdentityMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_remembers_and_forgets_external_user_email(): void
    {
        ScimIdentityMap::remember([
            'id' => 'user-1',
            'emails' => [['value' => 'User@Example.com', 'primary' => true]],
        ]);

        $this->assertSame('user@example.com', ScimIdentityMap::email('user-1'));
        ScimIdentityMap::forget('user-1');
        $this->assertSame('', ScimIdentityMap::email('user-1'));
    }

    public function test_rejects_rebinding_external_user_id_to_another_email(): void
    {
        ScimIdentityMap::remember([
            'id' => 'user-1',
            'emails' => [['value' => 'first@example.com', 'primary' => true]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('拒绝重新绑定');
        ScimIdentityMap::remember([
            'id' => 'user-1',
            'emails' => [['value' => 'second@example.com', 'primary' => true]],
        ]);
    }

    public function test_ignores_resource_without_stable_id_or_unique_email(): void
    {
        ScimIdentityMap::remember(['emails' => [['value' => 'user@example.com']]]);
        ScimIdentityMap::remember([
            'id' => 'user-1',
            'emails' => [
                ['value' => 'first@example.com'],
                ['value' => 'second@example.com'],
            ],
        ]);

        $this->assertSame('', ScimIdentityMap::email('user-1'));
    }
}
