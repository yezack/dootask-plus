<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scim\ScimSet;
use App\Scim\ScimWebhookUserSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScimWebhookController extends Controller
{
    private const USER_EVENTS = [
        'urn:ietf:params:scim:event:prov:create:notice',
        'urn:ietf:params:scim:event:prov:put:notice',
        'urn:ietf:params:scim:event:prov:patch:notice',
        'urn:ietf:params:scim:event:prov:delete',
        'urn:ietf:params:scim:event:prov:activate',
        'urn:ietf:params:scim:event:prov:deactivate',
    ];

    public function __invoke(Request $request)
    {
        $secret = (string)config('dootask.scim.webhook_secret', '');
        if ($secret === '') {
            Log::error('SCIM webhook: 未配置签名密钥');
            return response()->json(['error' => 'webhook not configured'], 503);
        }

        $contentType = strtolower(trim(explode(';', (string)$request->header('Content-Type', ''))[0]));
        if ($contentType !== 'application/secevent+jwt') {
            return response()->json(['error' => 'unsupported content type'], 415);
        }

        $token = $request->getContent();
        if (strlen($token) > 65536) {
            return response()->json(['error' => 'payload too large'], 413);
        }
        $signature = strtolower(trim((string)$request->header('X-SCIM-Event-Signature', '')));
        $expected = hash_hmac('sha256', $token, $secret);
        if ($token === '' || !hash_equals($expected, $signature)) {
            Log::warning('SCIM webhook: HMAC 签名验证失败', [
                'token_len' => strlen($token),
                'signature_len' => strlen($signature),
                'secret_len' => strlen($secret),
            ]);
            return response()->json(['error' => 'invalid signature'], 401);
        }

        try {
            $set = ScimSet::parse($token);
        } catch (\InvalidArgumentException $e) {
            Log::warning('SCIM webhook: SET 验证失败', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'invalid security event token'], 400);
        }

        $replayKey = 'scim:set:jti:' . hash('sha256', (string)$set['jti']);
        $ttl = max(60, (int)$set['exp'] - time());
        if (!Cache::add($replayKey, true, $ttl)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $events = array_intersect(array_keys($set['events']), self::USER_EVENTS);
        if ($events === []) {
            Log::debug('SCIM webhook: 无需处理的事件', ['events' => array_keys($set['events'])]);
            return response()->json(['received' => true]);
        }

        try {
            $result = app(ScimWebhookUserSynchronizer::class)->sync($set['sub_id']['uri'], $events);
        } catch (\Throwable $e) {
            Cache::forget($replayKey);
            Log::error('SCIM webhook: 处理失败', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'event processing failed'], 500);
        }

        return response()->json(['received' => true, 'result' => $result]);
    }
}
