<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'display_name', 'email', 'password', 'role', 'strict_source_scope', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * 数据库默认值在「新建但未回读」的模型实例上是不可见的：
     * `User::create([...])` 不会把未提交的列补进属性数组，于是 `$user->role` 会是 null。
     * 权限判定一旦读到 null 就会静默降级（例如出处范围限制被绕过），
     * 所以在模型层显式声明默认值，让权限判断在任何时刻都拿到确定的输入。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'viewer',
        'strict_source_scope' => true,
        'is_active' => true,
    ];

    /**
     * 用户列表允许的排序字段白名单。
     *
     * 必须白名单：排序列会直接拼进 ORDER BY，用请求参数拼 SQL 是最典型的注入面。
     * 顺带把「可排序」这件事变成显式契约 —— 视图里的排序表头据此生成。
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'email', 'role', 'is_active', 'last_login_at', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'strict_source_scope' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ 角色与状态 */

    public function role(): UserRole
    {
        // $this->role 已由 casts 转成枚举；这里再兜一层，避免历史数据里的空值放行
        return $this->role instanceof UserRole ? $this->role : UserRole::Viewer;
    }

    /** 账号状态。空值按「启用」处理 —— 与 role 的兜底方向一致（不能让 null 变成禁用）。 */
    public function status(): UserStatus
    {
        return $this->is_active === false ? UserStatus::Disabled : UserStatus::Active;
    }

    public function isActive(): bool
    {
        return $this->status()->isActive();
    }

    /** 出处范围限制。空值按「开启」处理 —— 权限缺省的默认方向必须是更严而非更松。 */
    public function enforcesSourceScope(): bool
    {
        return $this->strict_source_scope === null ? true : (bool) $this->strict_source_scope;
    }

    public function displayLabel(): string
    {
        return $this->display_name ?: $this->name;
    }

    public function isAdmin(): bool
    {
        return $this->role()->canAdminister();
    }

    public function canEditEvents(): bool
    {
        return $this->role()->canEditEvents();
    }

    public function canReview(): bool
    {
        return $this->role()->canReview();
    }

    /** 头像方块里的字：中文取首字，拉丁取首字母缩写（无图片依赖，保持零构建）。 */
    public function initials(): string
    {
        $label = trim($this->displayLabel());

        if ($label === '') {
            return '?';
        }

        if (preg_match('/^[\x{4e00}-\x{9fff}]/u', $label) === 1) {
            return mb_substr($label, 0, 1, 'UTF-8');
        }

        $parts = preg_split('/\s+/u', $label) ?: [];

        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8').mb_substr($parts[1], 0, 1, 'UTF-8'));
        }

        return mb_strtoupper(mb_substr($label, 0, 2, 'UTF-8'));
    }

    /* ------------------------------------------------------------------ 关系 */

    /** 用户负责的出处（用于 source 维度的编辑范围限制）。 */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'source_user')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'created_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EventRevision::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    /** 针对本账号的操作日志（本账号是被操作对象）。 */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(UserActivityLog::class, 'user_id');
    }

    /** 本账号作为操作人产生的日志。 */
    public function performedActions(): HasMany
    {
        return $this->hasMany(UserActivityLog::class, 'actor_id');
    }

    public function ownedSourceIds(): array
    {
        return $this->sources()->pluck('sources.id')->all();
    }

    /* ------------------------------------------------------------------ 查询作用域 */

    /** 关键词：登录名 / 显示名 / 邮箱。 */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // 转义 LIKE 通配符：否则用户搜一个 % 就会命中全部账号
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(function (Builder $inner) use ($escaped) {
            $inner->whereRaw('lower(name) like ?', ['%'.mb_strtolower($escaped).'%'])
                ->orWhereRaw('lower(coalesce(display_name, \'\')) like ?', ['%'.mb_strtolower($escaped).'%'])
                ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower($escaped).'%']);
        });
    }

    public function scopeOfRole(Builder $query, ?string $role): Builder
    {
        return $role === null || $role === ''
            ? $query
            : $query->where('role', $role);
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            UserStatus::Active->value => $query->where('is_active', true),
            UserStatus::Disabled->value => $query->where('is_active', false),
            default => $query,
        };
    }

    /**
     * 应用排序。列名经白名单校验，方向只接受 asc / desc。
     *
     * 固定追加 id 作为末位排序键：否则同一时刻注册的账号在分页时顺序不稳定，
     * 会出现「第 2 页又看到第 1 页的人」。
     */
    public function scopeSorted(Builder $query, ?string $column, ?string $direction): Builder
    {
        $column = in_array($column, self::SORTABLE, true) ? $column : 'created_at';
        $direction = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    /* ------------------------------------------------------------------ 序列化 */

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'label' => $this->displayLabel(),
            'initials' => $this->initials(),
            'email' => $this->email,
            'role' => $this->role()->value,
            'role_label' => $this->role()->label(),
            'role_level' => $this->role()->level(),
            'status' => $this->status()->value,
            'status_label' => $this->status()->label(),
            'status_badge' => $this->status()->badgeClass(),
            'is_active' => $this->isActive(),
            'strict_source_scope' => $this->enforcesSourceScope(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
