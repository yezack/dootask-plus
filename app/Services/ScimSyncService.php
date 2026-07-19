<?php

namespace App\Services;

use App\Module\Base;
use App\Scim\ScimClient;
use App\Scim\ScimUserMapper;
use Illuminate\Support\Facades\Cache;

class ScimSyncService
{
    public function run(): ?array
    {
        $lock = Cache::lock('scim:sync', max(1, (int)config('dootask.scim.lock_seconds', 3600)));
        if (!$lock->get()) {
            return null;
        }

        try {
            Base::setting('system', [
                'scim_last_attempt' => now()->toDateTimeString(),
            ], true);

            $client = new ScimClient();
            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0];

            foreach ($client->listUsers() as $scimUser) {
                $result = ScimUserMapper::sync($scimUser);
                $stats[$result]++;
                $stats['total']++;
            }

            if ($stats['failed'] === 0) {
                Base::setting('system', [
                    'scim_last_sync' => now()->toDateTimeString(),
                ], true);
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }
}
