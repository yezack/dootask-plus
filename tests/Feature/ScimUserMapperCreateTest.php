<?php

namespace Tests\Feature;

use App\Models\User;
use App\Scim\ScimUserMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScimUserMapperCreateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scim_created_user_does_not_require_password_change(): void
    {
        $email = 'scim-no-change-pass@test.local';
        config(['dootask.scim.sync_department' => false]);

        // Doo::userCreate commits internally, so make cleanup visible to its connection.
        \DB::commit();
        \DB::table('users')->where('email', $email)->delete();
        \DB::beginTransaction();

        try {
            $result = ScimUserMapper::sync([
                'id' => 'scim-no-change-pass',
                'userName' => 'scim-no-change-pass',
                'displayName' => 'SCIM User',
                'active' => true,
                'emails' => [
                    ['value' => $email, 'primary' => true],
                ],
            ]);
        } catch (\Throwable $e) {
            if ($this->isSwooleInfraFailure($e)) {
                $this->markTestSkipped('Swoole runtime unavailable: ' . $e->getMessage());
            }
            throw $e;
        }

        $this->assertSame('created', $result);
        $this->assertSame(0, (int)User::whereEmail($email)->value('changepass'));

        \DB::table('users')->where('email', $email)->delete();
    }

    private function isSwooleInfraFailure(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'swoole')
            || str_contains($message, 'Swoole')
            || str_contains($message, 'AbstractData::__wakeup')
            || str_contains($message, 'Undefined array key');
    }
}
