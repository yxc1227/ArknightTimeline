<?php

namespace App\Services\Identity;

use App\Enums\IdentityProvider;
use App\Exceptions\IdentityException;
use Illuminate\Support\Facades\Http;

/**
 * 通用 OAuth2 授权码流程。
 *
 * 这是一个**参数化**的驱动：端点、scope、字段映射全部来自 config/identity.php。
 * 刻意不针对某个渠道写死实现 —— 否则每接一个渠道就要复制一遍
 * token 交换与资料解析，而这类代码的复制品最容易漏掉「校验授权码是否为空」这种细节。
 */
class OAuthDriver implements IdentityDriver
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly IdentityProvider $provider,
    ) {}

    public function provider(): IdentityProvider
    {
        return $this->provider;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        $config = $this->config();

        // 用 http_build_query 而不是手工拼串：scope 里通常含空格，
        // 手工拼接时忘了 rawurlencode 就会让对方解析出错误的 scope。
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', (array) ($config['scopes'] ?? [])),
            'state' => $state,
        ]);

        return $config['authorize_url'].(str_contains($config['authorize_url'], '?') ? '&' : '?').$query;
    }

    public function fetchProfile(string $code, string $redirectUri): ExternalProfile
    {
        $config = $this->config();

        $token = $this->exchangeCode($config, $code, $redirectUri);

        $payload = $this->fetchUserInfo($config, $token);

        $mapping = (array) ($config['mapping'] ?? []);

        $id = data_get($payload, $mapping['id'] ?? 'id');

        if (! is_scalar($id) || (string) $id === '') {
            throw new IdentityException(
                '对方返回的资料里没有可用的账号标识，绑定已中止。',
                'profile_incomplete',
            );
        }

        return new ExternalProfile(
            provider: $this->provider,
            providerUserId: (string) $id,
            nickname: $this->stringOrNull(data_get($payload, $mapping['nickname'] ?? 'nickname')),
            avatarUrl: $this->safeUrl(data_get($payload, $mapping['avatar'] ?? 'avatar')),
            email: $this->stringOrNull(data_get($payload, $mapping['email'] ?? 'email')),
        );
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->provider->config();

        if (! $this->provider->isConfigured()) {
            throw new IdentityException(
                sprintf('%s尚未完成接入配置。', $this->provider->label()),
                'provider_not_configured',
            );
        }

        return $config;
    }

    private function exchangeCode(array $config, string $code, string $redirectUri): string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($config['token_url'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                ]);
        } catch (\Throwable $e) {
            // 网络层失败必须与「对方明确拒绝」区分开：前者要重试，后者要改配置
            report($e);

            throw new IdentityException('无法连接授权服务器，请稍后重试。', 'provider_unreachable');
        }

        if ($response->failed()) {
            throw new IdentityException(
                '授权码校验失败，请重新发起授权。',
                'token_exchange_failed',
            );
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : throw new IdentityException(
            '授权服务器没有返回访问令牌，请重新发起授权。',
            'token_missing',
        );
    }

    /** @return array<string, mixed> */
    private function fetchUserInfo(array $config, string $token): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT)->get($config['userinfo_url']);
        } catch (\Throwable $e) {
            report($e);

            throw new IdentityException('无法读取账号资料，请稍后重试。', 'provider_unreachable');
        }

        if ($response->failed()) {
            throw new IdentityException('读取账号资料失败，请重新发起授权。', 'userinfo_failed');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new IdentityException('账号资料格式无法解析。', 'profile_malformed');
        }

        return $payload;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * 只接受 http/https 的头像地址。
     *
     * 这个值最终会出现在 <img src> 里，虽然现代浏览器不会执行 javascript: 伪协议，
     * 但 data: 之类的地址可以被用来塞入任意内容，没有任何理由放行。
     */
    private function safeUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
