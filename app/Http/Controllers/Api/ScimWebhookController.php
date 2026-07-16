<?php

namespace App\Http\Controllers\Api;

use App\Scim\ScimUserMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SCIM SET Webhook 接收端点
 *
 * 接收 UniAuthSync 推送的 RFC 9967 Security Event Tokens。
 * 验证 HMAC-SHA256 签名，根据事件类型处理用户变更。
 *
 * 路由：POST /api/scim/webhook
 */
class ScimWebhookController extends AbstractController
{
    /**
     * 处理 SET 推送
     */
    public function __invoke(Request $request)
    {
        $secret = env('SCIM_WEBHOOK_SECRET', '');
        if ($secret) {
            $signature = $request->header('X-SET-Signature', '');
            $payload   = $request->getContent();
            $expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            if (!hash_equals($expected, $signature)) {
                Log::warning('SCIM webhook: 签名验证失败');
                return response()->json(['error' => 'invalid signature'], 401);
            }
        }

        $events = $request->input('events', []);
        foreach ($events as $event) {
            $eventUri = $event['event_uri'] ?? '';
            $scimUser = $event['resource'] ?? [];

            if (empty($scimUser['id'])) {
                continue;
            }

            switch ($eventUri) {
                case 'urn:ietf:params:scim:event:prov:create:notice':
                case 'urn:ietf:params:scim:event:prov:patch:notice':
                    ScimUserMapper::sync($scimUser);
                    break;
                case 'urn:ietf:params:scim:event:prov:delete':
                    $email = ScimUserMapper::extractEmailPublic($scimUser);
                    if ($email && $user = \App\Models\User::whereEmail($email)->first()) {
                        $user->disable_at = now();
                        $user->save();
                        Log::info('SCIM webhook: 禁用用户', ['email' => $email]);
                    }
                    break;
                default:
                    Log::debug('SCIM webhook: 未知事件', ['uri' => $eventUri]);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * 公开的 email 提取方法（简化版，供 webhook 使用）
     */
    public static function extractEmailPublic(array $scimUser): string
    {
        $emails = $scimUser['emails'] ?? [];
        foreach ($emails as $e) {
            if (!empty($e['value'])) {
                return $e['value'];
            }
        }
        return '';
    }
}
