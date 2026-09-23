<?php

namespace App\Enums;

/**
 * 外部身份提供方。
 *
 * 只有「代码需要分支」的提供方才配拥有枚举值：驱动类型（oauth / stub）
 * 决定了走哪条实现路径，因此必须显式列出。
 * 而端点、字段映射这些纯配置留在 config/identity.php ——
 * 把它们做成枚举会在每次接入新渠道时逼着人改 PHP 代码。
 *
 * 提供方的**展示名**也放在配置里，这里的 label 只是缺省兜底。
 */
enum IdentityProvider: string
{
    /** 鹰角通行证（官方账号体系）。正式 OAuth 凭据未到位时退化为手工登记 UID。 */
    case Hypergryph = 'hypergryph';

    /** 本地演示渠道：仅非生产环境启用，用于在没有凭据时跑通完整链路。 */
    case Stub = 'stub';

    public function config(): array
    {
        return (array) config("identity.providers.{$this->value}", []);
    }

    public function driver(): string
    {
        return (string) ($this->config()['driver'] ?? 'oauth');
    }

    public function label(): string
    {
        return (string) ($this->config()['label'] ?? $this->value);
    }

    /** 界面上的英文微标签，与全站「大写等宽」的语言一致。 */
    public function short(): string
    {
        return (string) ($this->config()['short'] ?? mb_strtoupper($this->value));
    }

    public function blurb(): string
    {
        return (string) ($this->config()['blurb'] ?? '');
    }

    /**
     * 配置里的启用开关。
     *
     * 注意它**不**看凭据是否齐备，因此 isConfigured() 可以安全地依赖它 ——
     * 这两个判断一旦互相调用，stub 渠道就会陷入无限递归（渲染登录页直接 500）。
     */
    private function flagEnabled(): bool
    {
        $config = $this->config();

        return $config !== [] && ($config['enabled'] ?? true) !== false;
    }

    /**
     * 该驱动所需的东西是否齐备。
     *
     * OAuth 驱动要求三段端点与客户端凭据都在；演示驱动不发起任何网络请求，
     * 因此它不需要任何凭据，恒为「已配置」。
     */
    public function isConfigured(): bool
    {
        if ($this->driver() === 'stub') {
            return true;
        }

        $config = $this->config();

        return filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && filled($config['authorize_url'] ?? null)
            && filled($config['token_url'] ?? null)
            && filled($config['userinfo_url'] ?? null);
    }

    /**
     * 是否可以出现在界面上。
     *
     * 两个条件取交集：开关开着，且该驱动所需的东西齐备。
     * stub 渠道的开关在配置里与运行环境绑定，因此这条判断同时也是
     * 「非本地环境不存在一键登录后门」的保证。
     */
    public function isEnabled(): bool
    {
        return $this->flagEnabled() && $this->isConfigured();
    }

    /** 是否提供「手工登记」这条路（OAuth 不可用时的可用替代）。 */
    public function allowsManual(): bool
    {
        $config = $this->config();

        return (bool) ($config['allow_manual'] ?? false) && array_key_exists('manual_label', $config);
    }

    public function manualLabel(): string
    {
        return (string) ($this->config()['manual_label'] ?? '账号 ID');
    }

    public function manualHint(): string
    {
        return (string) ($this->config()['manual_hint'] ?? '');
    }

    /**
     * 可供用户选择的全部提供方。
     *
     * 不过滤 isEnabled：设置页需要把「未启用的渠道」也显示出来并说明原因，
     * 否则用户会以为是功能缺失。
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return list<self> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p->isEnabled()));
    }

    /**
     * 从路由参数解析提供方。
     *
     * 路由上用 `{provider}` 而不是枚举绑定，因此必须自己做一次严格转换：
     * 非法值一律当作 404，而不是回落到某个默认渠道。
     */
    public static function fromRoute(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
