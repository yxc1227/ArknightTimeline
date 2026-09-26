<?php

namespace App\Providers;

use App\Enums\AnomalySeverity;
use App\Enums\ProposalStatus;
use App\Models\AiProposal;
use App\Models\TimelineAnomaly;
use App\Services\Ai\AiDriver;
use App\Services\Ai\HeuristicAiDriver;
use App\Services\Ai\OpenAiCompatibleDriver;
use App\Services\Identity\IdentityManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerAiDriver();
        $this->registerIdentityManager();
    }

    /**
     * IdentityManager 必须是单例。
     *
     * 它内部持有一张「渠道 → 驱动」的注册表，而 extend() 是替换驱动实现的唯一接缝
     * （测试用它注入假驱动，从而完全不发网络请求）。
     * 若每次解析都得到新实例，extend() 就只改到了一个没人用的对象上，
     * 测试会静默地打到真实驱动 —— 那是最难查的一类失败。
     */
    private function registerIdentityManager(): void
    {
        $this->app->singleton(IdentityManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 默认分页视图输出 Tailwind 类名，而本项目零构建、没有 Tailwind，
        // 因此统一改用自带的 HUD 风格分页视图，避免分页退化成裸链接。
        Paginator::defaultView('vendor.pagination.hud');
        Paginator::defaultSimpleView('vendor.pagination.hud');

        $this->shareNavigationCounters();
    }

    /**
     * 导航角标：待审提案数与阻断级异常数。
     *
     * 用 safeCount 包住的原因：站点可能在没有跑迁移的环境里被访问（例如刚 clone），
     * 导航角标这种附属信息不应该让整个页面 500。
     */
    private function shareNavigationCounters(): void
    {
        View::composer('layouts.app', function ($view) {
            $view->with([
                'proposalPending' => $this->safeCount(
                    fn () => AiProposal::where('status', ProposalStatus::Pending->value)->count()
                ),
                'anomalyOpen' => $this->safeCount(
                    fn () => TimelineAnomaly::where('status', 'open')
                        ->where('severity', AnomalySeverity::Error->value)
                        ->count()
                ),
            ]);
        });
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * AI 驱动的选择。
     *
     * 注意这里的兜底方向：配置了 openai-compatible 但**没有 API Key** 时，
     * 静默降级到 heuristic 而不是抛异常。理由是「AI 梳理」在时间线项目里
     * 是增强功能而非核心链路 —— 它挂掉不应该让整个站点不可用。
     */
    private function registerAiDriver(): void
    {
        $this->app->singleton(AiDriver::class, function () {
            $config = config('timeline.ai');

            if (($config['driver'] ?? 'heuristic') === 'openai-compatible' && filled($config['api_key'] ?? null)) {
                return new OpenAiCompatibleDriver(
                    endpoint: $config['endpoint'],
                    apiKey: $config['api_key'],
                    modelName: $config['model'],
                    timeout: (int) $config['timeout'],
                );
            }

            return new HeuristicAiDriver;
        });
    }
}
