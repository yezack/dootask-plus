<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Module\Base;
use App\Services\UniAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Throwable;

class UniAuthController extends Controller
{
    /**
     * @api {get} api/uniauth/login 发起统一身份登录
     */
    public function login(Request $request, UniAuthService $service): RedirectResponse
    {
        if (!$service->isEnabled()) {
            return $this->loginErrorRedirect('disabled');
        }

        try {
            $prompt = (string)$request->query('prompt', '');
            if ($prompt === '' && $request->cookie('dootask_uniauth_prompt') === 'select_account') {
                $prompt = 'select_account';
            }
            $authorizeUrl = $service->buildAuthorizeUrl(
                (string)$request->query('from', ''),
                $request->getSchemeAndHttpHost(),
                $prompt
            );
            return redirect()->away($authorizeUrl)
                ->withCookie(Cookie::forget('dootask_uniauth_prompt'));
        } catch (Throwable $e) {
            Log::warning('UniAuth login initialization failed', [
                'exception' => $e::class,
            ]);
            return $this->loginErrorRedirect('unavailable');
        }
    }

    /**
     * @api {get} api/uniauth/callback 处理统一身份登录回调
     */
    public function callback(Request $request, UniAuthService $service): RedirectResponse
    {
        $state = trim((string)$request->query('state', ''));
        if ($request->query->has('error')) {
            $service->cancelAuthorization($state);
            return $this->loginErrorRedirect('denied');
        }

        try {
            $result = $service->completeLogin(
                trim((string)$request->query('code', '')),
                $state
            );
        } catch (Throwable $e) {
            Log::warning('UniAuth callback failed', [
                'exception' => $e::class,
            ]);
            return $this->loginErrorRedirect('failed');
        }

        $query = http_build_query([
            'ticket' => $result['ticket'],
            'from' => $result['from'],
        ], '', '&', PHP_QUERY_RFC3986);
        return redirect('/token?' . $query);
    }

    /**
     * @api {post} api/uniauth/exchange 交换一次性登录票据
     */
    public function exchange(Request $request, UniAuthService $service): array
    {
        if (!$service->isEnabled()) {
            return Base::retError('统一登录未启用');
        }

        $result = $service->consumeTicket(trim((string)$request->input('ticket', '')));
        if ($result === null) {
            return Base::retError('登录凭据无效或已过期');
        }
        return Base::retSuccess('登录成功', $result);
    }

    private function loginErrorRedirect(string $error): RedirectResponse
    {
        return redirect('/login?' . http_build_query([
            'uniauth_error' => $error,
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
