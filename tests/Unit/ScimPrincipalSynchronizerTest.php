<?php

namespace Tests\Unit;

use App\Scim\ScimPrincipalSynchronizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScimPrincipalSynchronizerTest extends TestCase
{
    public function test_collects_one_owner_and_deduplicated_admins(): void
    {
        $references = $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['value' => 'user-owner'],
            ],
            'admins' => [
                ['value' => 'user-admin'],
                ['value' => 'user-admin'],
                ['value' => 'user-owner'],
            ],
        ]]);

        $this->assertSame(['Group 数据应用 owner'], $references['user-owner']);
        $this->assertSame(['Group 数据应用 admin'], $references['user-admin']);
        $this->assertCount(2, $references);
    }

    public function test_group_without_owner_uses_no_principal_references(): void
    {
        $references = $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'admins' => [],
        ]]);

        $this->assertSame([], $references);
    }

    public function test_rejects_group_with_multiple_owners(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('实际 2 名');

        $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['value' => 'user-owner-1'],
                ['value' => 'user-owner-2'],
            ],
        ]]);
    }

    public function test_rejects_reference_without_value(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('用户引用缺少 value');

        $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['display' => '050668'],
            ],
        ]]);
    }

    private function collectReferences(array $groups): array
    {
        $method = new ReflectionMethod(ScimPrincipalSynchronizer::class, 'collectReferences');
        $method->setAccessible(true);
        return $method->invoke(new ScimPrincipalSynchronizer(), $groups);
    }
}
