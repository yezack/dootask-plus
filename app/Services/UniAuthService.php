<?php

namespace App\Services;

use App\Models\User;
use App\Module\Base;
use App\Scim\ScimClient;
use Carbon\Carbon;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Throwable;

class UniAuthService
{
    private const ALLOWED_PROMPTS = ['', 'none', 'login', 'consent', 'select_account'];
    private const STATE_PREFIX = 'uniauth:state:';
    private const TICKET_PREFIX = 'uniauth:ticket:';
    private const LOCK_PREFIX = 'uniauth:lock:';
    private const DISCOVERY_PREFIX = 'uniauth:discovery:';
    private const JWKS_PREFIX = 'uniauth:jwks:';

    public function __construct(private ?ScimClient $scimClient = null)
    {
    }

    public static function configuredMode(array $config): string
    {
        $mode = strtolower(trim((string)($config['mode'] ?? '')));
        if (in_array($mode, ['disabled', 'available', 'required'], true)) {
            return $mode;
        }

        // Backward compatibility for deployments that still use the two boolean flags.
        if (!(bool)($config['enabled'] ?? false)) {
            return 'disabled';
        }
        return (bool)($config['allow_local_login'] ?? true) ? 'available' : 'required';
    }

    public function mode(): string
    {
        return self::configuredMode((array)config('dootask.uniauth', []));
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== 'disabled';
    }

    public function isLocalLoginAllowed(): bool
    {
        return $this->mode() !== 'required';
    }

    public function buildAuthorizeUrl(string $from, string $origin, string $prompt = ''): string
    {
        $prompt = self::normalizePrompt($prompt);
        $config = $this->validatedConfig();
        $metadata = $this->discovery($config);
        $state = self::randomToken(32);
        $nonce = self::randomToken(32);
        $verifier = self::randomToken(64);
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));

        if (!$this->cacheStore()->put($this->stateKey($state), [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'from' => self::normalizeReturnPath($from, $origin),
        ], (int)$config['state_ttl_seconds'])) {
            throw new RuntimeException('OIDC state could not be stored');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope' => implode(' ', self::normalizeScopes($config['scopes'])),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            ...($prompt !== '' ? ['prompt' => $prompt] : []),
        ], '', '&', PHP_QUERY_RFC3986);

        return $metadata['authorization_endpoint'] . '?' . $query;
    }

    public static function normalizePrompt(string $prompt): string
    {
        $prompt = strtolower(trim($prompt));
        return in_array($prompt, self::ALLOWED_PROMPTS, true) ? $prompt : '';
    }

    public function completeLogin(string $code, string $state): array
    {
        if ($code === '' || $state === '') {
            throw new RuntimeException('OIDC callback parameters are incomplete');
        }

        $config = $this->validatedConfig();
        $stateData = $this->atomicPull($this->stateKey($state));
        if (!is_array($stateData) || !is_string($stateData['nonce'] ?? null) || !is_string($stateData['verifier'] ?? null)) {
            throw new RuntimeException('OIDC state is invalid or expired');
        }

        $metadata = $this->discovery($config);
        $tokens = $this->exchangeCode($metadata['token_endpoint'], $code, $stateData['verifier'], $config);
        $claims = $this->validateIdToken($tokens['id_token'], $stateData['nonce'], $metadata, $config);
        $this->confirmUserInfo($metadata['userinfo_endpoint'], $tokens['access_token'], $claims['sub'], $config);
        $this->revokeRefreshToken($metadata, $tokens['refresh_token'] ?? '', $config);

        $scimUser = $this->scimClient()->getUserById($claims['sub']);
        if (!is_string($scimUser['id'] ?? null) || !hash_equals($claims['sub'], $scimUser['id'])) {
            throw new RuntimeException('SCIM subject does not match');
        }
        if (($scimUser['active'] ?? null) !== true) {
            throw new RuntimeException('SCIM user is inactive');
        }

        $email = self::extractExplicitScimEmail($scimUser);
        if ($email === '') {
            throw new RuntimeException('SCIM user has no valid explicit email');
        }

        $users = User::whereEmail($email)->limit(2)->get();
        if ($users->count() !== 1) {
            throw new RuntimeException('Local user email is not uniquely matched');
        }
        $user = $users->first();
        if (!$user || $user->isDisable(true)) {
            throw new RuntimeException('Local user is unavailable');
        }

        $now = Carbon::now();
        $user->updateInstance([
            'login_num' => $user->login_num + 1,
            'last_ip' => Base::getIp(),
            'last_at' => $now,
            'line_ip' => Base::getIp(),
            'line_at' => $now,
        ]);
        $user->save();
        User::generateToken($user, true);

        $ticket = self::randomToken(32);
        if (!$this->cacheStore()->put($this->ticketKey($ticket), $user->toArray(), (int)$config['ticket_ttl_seconds'])) {
            throw new RuntimeException('Login ticket could not be stored');
        }

        return [
            'ticket' => $ticket,
            'from' => is_string($stateData['from'] ?? null) ? $stateData['from'] : '',
        ];
    }

    public function cancelAuthorization(string $state): void
    {
        if ($state !== '') {
            $this->atomicPull($this->stateKey($state));
        }
    }

    public function consumeTicket(string $ticket): ?array
    {
        if ($ticket === '') {
            return null;
        }

        $payload = $this->atomicPull($this->ticketKey($ticket));
        if (!is_array($payload)
            || filter_var($payload['userid'] ?? null, FILTER_VALIDATE_INT) === false
            || (int)$payload['userid'] < 1
            || !is_string($payload['token'] ?? null)
            || $payload['token'] === '') {
            return null;
        }
        return $payload;
    }

    public static function normalizeReturnPath(?string $from, string $origin): string
    {
        $from = trim((string)$from);
        if ($from === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $from)) {
            return '';
        }

        if (str_starts_with($from, '/')) {
            if (str_starts_with($from, '//') || str_starts_with(rawurldecode($from), '//')) {
                return '';
            }
            $parts = parse_url($from);
            return is_array($parts) && !isset($parts['scheme'], $parts['host'])
                ? self::pathFromUrlParts($parts)
                : '';
        }

        $url = parse_url($from);
        $base = parse_url($origin);
        if (!is_array($url) || !is_array($base) || !self::sameOrigin($url, $base)) {
            return '';
        }
        if (isset($url['user']) || isset($url['pass'])) {
            return '';
        }

        return self::pathFromUrlParts($url);
    }

    public static function extractExplicitScimEmail(array $scimUser): string
    {
        $primary = [];
        $valid = [];
        foreach (($scimUser['emails'] ?? []) as $email) {
            if (!is_array($email) || !is_scalar($email['value'] ?? null)) {
                continue;
            }
            $value = strtolower(trim((string)$email['value']));
            if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $valid[$value] = $value;
            if (($email['primary'] ?? false) === true) {
                $primary[$value] = $value;
            }
        }
        if (count($primary) === 1) {
            return array_values($primary)[0];
        }
        if ($primary !== []) {
            return '';
        }
        return count($valid) === 1 ? array_values($valid)[0] : '';
    }

    public static function configurationErrors(array $config): array
    {
        $errors = [];
        foreach (['scim_server_url', 'scim_client_id', 'scim_client_secret', 'issuer', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                $errors[] = $key;
            }
        }
        foreach (['scim_server_url', 'issuer', 'redirect_uri'] as $key) {
            if (!in_array($key, $errors, true) && !self::isHttpUrl($config[$key])) {
                $errors[] = $key;
            }
        }
        if (($config['allow_insecure_http'] ?? false) !== true) {
            foreach (['scim_server_url', 'issuer', 'redirect_uri'] as $key) {
                if (!in_array($key, $errors, true) && !self::isHttpsUrl($config[$key])) {
                    $errors[] = $key;
                }
            }
        }
        if (!is_string($config['cache_store'] ?? null) || trim($config['cache_store']) === '') {
            $errors[] = 'cache_store';
        }
        if (!in_array('issuer', $errors, true)) {
            $issuer = parse_url($config['issuer']);
            if (!is_array($issuer) || isset($issuer['query']) || isset($issuer['fragment']) || isset($issuer['user']) || isset($issuer['pass'])) {
                $errors[] = 'issuer';
            }
        }
        if (self::normalizeScopes($config['scopes'] ?? []) === [] || !in_array('openid', self::normalizeScopes($config['scopes'] ?? []), true)) {
            $errors[] = 'scopes';
        }
        foreach (['http_timeout', 'jwks_cache_seconds', 'state_ttl_seconds', 'ticket_ttl_seconds'] as $key) {
            if (filter_var($config[$key] ?? null, FILTER_VALIDATE_INT) === false || (int)$config[$key] < 1) {
                $errors[] = $key;
            }
        }
        return array_values(array_unique($errors));
    }

    private function validatedConfig(): array
    {
        $config = (array)config('dootask.uniauth', []);
        if (!$this->isEnabled()) {
            throw new RuntimeException('UniAuth is disabled');
        }
        if (self::configurationErrors($config) !== []) {
            throw new RuntimeException('UniAuth configuration is incomplete');
        }
        try {
            Cache::store($config['cache_store'])->getStore();
        } catch (Throwable) {
            throw new RuntimeException('UniAuth cache store is unavailable');
        }
        return $config;
    }

    private function discovery(array $config): array
    {
        $cacheKey = self::DISCOVERY_PREFIX . hash('sha256', $config['issuer']);
        $metadata = $this->cacheStore()->get($cacheKey);
        if (!is_array($metadata)) {
            $response = $this->http($config)->acceptJson()->timeout((int)$config['http_timeout'])
                ->get(rtrim($config['issuer'], '/') . '/.well-known/openid-configuration');
            if (!$response->successful() || !is_array($response->json())) {
                throw new RuntimeException('OIDC discovery failed');
            }
            $metadata = $response->json();
            $this->cacheStore()->put($cacheKey, $metadata, (int)$config['jwks_cache_seconds']);
        }

        if (!is_string($metadata['issuer'] ?? null) || !hash_equals($config['issuer'], $metadata['issuer'])) {
            throw new RuntimeException('OIDC discovery issuer does not match');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $key) {
            if (!is_string($metadata[$key] ?? null)
                || !self::isHttpUrl($metadata[$key])
                || (($config['allow_insecure_http'] ?? false) !== true && !self::isHttpsUrl($metadata[$key]))) {
                throw new RuntimeException('OIDC discovery metadata is incomplete');
            }
        }
        return $metadata;
    }

    private function exchangeCode(string $endpoint, string $code, string $verifier, array $config): array
    {
        $response = $this->http($config)->asForm()->acceptJson()->timeout((int)$config['http_timeout'])->post($endpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['redirect_uri'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code_verifier' => $verifier,
        ]);
        if (!$response->successful()) {
            throw new RuntimeException('OIDC token exchange failed');
        }

        $tokens = $response->json();
        if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === ''
            || !is_string($tokens['id_token'] ?? null) || $tokens['id_token'] === '') {
            throw new RuntimeException('OIDC token response is incomplete');
        }
        return $tokens;
    }

    private function validateIdToken(string $idToken, string $nonce, array $metadata, array $config): array
    {
        $header = self::decodeJwtPart(explode('.', $idToken)[0] ?? '');
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new RuntimeException('OIDC ID token header is invalid');
        }

        $jwk = $this->findJwk($metadata['jwks_uri'], $header['kid'], $config, false);
        if ($jwk === null) {
            $jwk = $this->findJwk($metadata['jwks_uri'], $header['kid'], $config, true);
        }
        if ($jwk === null || ($jwk['kty'] ?? null) !== 'RSA' || (isset($jwk['alg']) && $jwk['alg'] !== 'RS256')
            || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
            throw new RuntimeException('OIDC signing key is invalid');
        }

        try {
            $keys = JWK::parseKeySet(['keys' => [$jwk]], 'RS256');
            $key = $keys[$header['kid']] ?? reset($keys);
            $claims = (array)JWT::decode($idToken, $key);
        } catch (Throwable) {
            throw new RuntimeException('OIDC ID token signature is invalid');
        }

        $now = time();
        if (!is_string($claims['iss'] ?? null) || !hash_equals($config['issuer'], $claims['iss'])) {
            throw new RuntimeException('OIDC issuer claim is invalid');
        }
        if (!self::audienceMatches($claims['aud'] ?? null, $config['client_id'], $claims['azp'] ?? null)) {
            throw new RuntimeException('OIDC audience claim is invalid');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException('OIDC subject claim is invalid');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new RuntimeException('OIDC expiration claim is invalid');
        }
        if (!is_int($claims['iat'] ?? null) || $claims['iat'] <= 0 || $claims['iat'] > $now || $claims['iat'] > $claims['exp']) {
            throw new RuntimeException('OIDC issued-at claim is invalid');
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new RuntimeException('OIDC nonce claim is invalid');
        }

        return $claims;
    }

    private function findJwk(string $uri, string $kid, array $config, bool $refresh): ?array
    {
        $cacheKey = self::JWKS_PREFIX . hash('sha256', $config['issuer'] . '|' . $uri);
        $jwks = $refresh ? null : $this->cacheStore()->get($cacheKey);
        if (!is_array($jwks)) {
            $response = $this->http($config)->acceptJson()->timeout((int)$config['http_timeout'])->get($uri);
            if (!$response->successful() || !is_array($response->json('keys'))) {
                throw new RuntimeException('OIDC JWKS request failed');
            }
            $jwks = $response->json();
            $this->cacheStore()->put($cacheKey, $jwks, (int)$config['jwks_cache_seconds']);
        }

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (is_array($jwk) && is_string($jwk['kid'] ?? null) && hash_equals($kid, $jwk['kid'])) {
                return $jwk;
            }
        }
        return null;
    }

    private function confirmUserInfo(string $endpoint, string $accessToken, string $sub, array $config): void
    {
        $response = $this->http($config)->withToken($accessToken)->acceptJson()->timeout((int)$config['http_timeout'])->get($endpoint);
        $userInfoSub = $response->json('sub');
        if (!$response->successful() || !is_string($userInfoSub) || !hash_equals($sub, $userInfoSub)) {
            throw new RuntimeException('OIDC userinfo subject does not match');
        }
    }

    private function revokeRefreshToken(array $metadata, mixed $refreshToken, array $config): void
    {
        if (!is_string($refreshToken) || $refreshToken === '' || !is_string($metadata['revocation_endpoint'] ?? null)
            || !self::isHttpUrl($metadata['revocation_endpoint'])
            || (($config['allow_insecure_http'] ?? false) !== true && !self::isHttpsUrl($metadata['revocation_endpoint']))) {
            return;
        }
        try {
            $this->http($config)->asForm()->acceptJson()->timeout((int)$config['http_timeout'])->post($metadata['revocation_endpoint'], [
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);
        } catch (Throwable) {
        }
    }

    private function scimClient(): ScimClient
    {
        return $this->scimClient ??= new ScimClient();
    }

    private function http(array $config): PendingRequest
    {
        $request = Http::acceptJson();
        return ($config['verify_tls'] ?? true) ? $request : $request->withoutVerifying();
    }

    private function stateKey(string $state): string
    {
        return self::STATE_PREFIX . hash('sha256', $state);
    }

    private function ticketKey(string $ticket): string
    {
        return self::TICKET_PREFIX . hash('sha256', $ticket);
    }

    private function atomicPull(string $key): mixed
    {
        $store = $this->cacheStore();
        $lock = $store->lock(self::LOCK_PREFIX . hash('sha256', $key), 5);
        if (!$lock->get()) {
            return null;
        }
        try {
            return $store->pull($key);
        } finally {
            $lock->release();
        }
    }

    private function cacheStore(): \Illuminate\Cache\Repository
    {
        return Cache::store((string)config('dootask.uniauth.cache_store', 'redis'));
    }

    private static function normalizeScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', trim($scopes)) ?: [];
        }
        if (!is_array($scopes)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn ($scope) => is_scalar($scope) ? trim((string)$scope) : '',
            $scopes
        ))));
    }

    private static function audienceMatches(mixed $audience, string $clientId, mixed $authorizedParty): bool
    {
        if (is_string($audience)) {
            return hash_equals($clientId, $audience)
                && (!is_string($authorizedParty) || hash_equals($clientId, $authorizedParty));
        }
        if (!is_array($audience) || !in_array($clientId, $audience, true)) {
            return false;
        }
        return count($audience) === 1 || (is_string($authorizedParty) && hash_equals($clientId, $authorizedParty));
    }

    private static function decodeJwtPart(string $part): array
    {
        if ($part === '') {
            return [];
        }
        $decoded = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        if ($decoded === false) {
            return [];
        }
        $value = json_decode($decoded, true);
        return is_array($value) ? $value : [];
    }

    private static function randomToken(int $bytes): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function isHttpUrl(mixed $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && is_string($parts['host'] ?? null) && $parts['host'] !== '';
    }

    private static function isHttpsUrl(mixed $url): bool
    {
        if (!self::isHttpUrl($url)) {
            return false;
        }
        return strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? '')) === 'https';
    }

    private static function sameOrigin(array $url, array $base): bool
    {
        $scheme = strtolower((string)($url['scheme'] ?? ''));
        $baseScheme = strtolower((string)($base['scheme'] ?? ''));
        $host = strtolower((string)($url['host'] ?? ''));
        $baseHost = strtolower((string)($base['host'] ?? ''));
        $port = (int)($url['port'] ?? ($scheme === 'https' ? 443 : 80));
        $basePort = (int)($base['port'] ?? ($baseScheme === 'https' ? 443 : 80));
        return $scheme !== '' && $scheme === $baseScheme && $host !== '' && $host === $baseHost && $port === $basePort;
    }

    private static function pathFromUrlParts(array $parts): string
    {
        $path = (string)($parts['path'] ?? '/');
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#' . $parts['fragment'];
        }
        return $path;
    }
}
