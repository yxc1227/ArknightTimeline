<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use App\Enums\IdentityStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 外部身份绑定（一个本地账号 ↔ 一个外部账号）。
 *
 * 两条唯一索引在数据库层保证：
 *   · (provider, provider_user_id) —— 同一个外部账号不能被两个本地账号认领
 *   · (user_id, provider)         —— 同一个本地账号在每个渠道只能绑一个
 * 应用层不再重复做「是否已绑定」的判断，只需要把约束冲突翻译成人话。
 *
 * 注意本表**不存 access_token / refresh_token**，原因见迁移文件的注释。
 */
#[Fillable([
    'user_id', 'provider', 'provider_user_id',
    'nickname', 'avatar_url', 'email',
    'status', 'verified_at', 'verified_by',
    'linked_at', 'last_used_at',
])]
class UserIdentity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'status' => IdentityStatus::class,
            'verified_at' => 'datetime',
            'linked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ 关系 */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 人工核验的操作人；为空表示由授权流程自动确认。 */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ------------------------------------------------------------------ 展示 */

    /** provider 列已由 casts 转成枚举，这里兜一层以防历史脏数据。 */
    public function provider(): IdentityProvider
    {
        return $this->provider instanceof IdentityProvider
            ? $this->provider
            : IdentityProvider::from((string) $this->getRawOriginal('provider'));
    }

    public function status(): IdentityStatus
    {
        return $this->status instanceof IdentityStatus ? $this->status : IdentityStatus::Pending;
    }

    public function isVerified(): bool
    {
        return $this->status()->isVerified();
    }

    /** 由授权流程自动确认（而非人工核验）的身份。 */
    public function isAutoVerified(): bool
    {
        return $this->isVerified() && $this->verified_by === null;
    }

    public function label(): string
    {
        return $this->provider()->label();
    }

    /**
     * 用于展示的外部账号标识。
     *
     * 优先显示对方系统给的昵称，回落时才显示原始 ID：UID 是一串数字，
     * 对用户来说几乎无法自检「这是不是我」，昵称才有辨识度。
     */
    public function displayAccount(): string
    {
        return filled($this->nickname) ? (string) $this->nickname : (string) $this->provider_user_id;
    }

    /** 核验方式的说明文字，直接展示给用户与管理员。 */
    public function verificationNote(): string
    {
        if (! $this->isVerified()) {
            return '用户自助登记，等待核验';
        }

        return $this->verified_by === null
            ? '由授权流程自动确认'
            : '由'.$this->verifier?->displayLabel().'核验';
    }

    /* ------------------------------------------------------------------ 作用域 */

    public function scopeOfProvider(Builder $query, IdentityProvider|string $provider): Builder
    {
        return $query->where('provider', $provider instanceof IdentityProvider ? $provider->value : $provider);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', IdentityStatus::Pending->value);
    }

    /* ------------------------------------------------------------------ 序列化 */

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider()->value,
            'provider_label' => $this->label(),
            'provider_short' => $this->provider()->short(),
            'provider_user_id' => $this->provider_user_id,
            'nickname' => $this->nickname,
            'account' => $this->displayAccount(),
            'avatar_url' => $this->avatar_url,
            'status' => $this->status()->value,
            'status_label' => $this->status()->label(),
            'status_badge' => $this->status()->badgeClass(),
            'is_verified' => $this->isVerified(),
            'verification_note' => $this->verificationNote(),
            'linked_at' => $this->linked_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
