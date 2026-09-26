<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\IdentityProvider;
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

#[Fillable(['name', 'nickname', 'email', 'password', 'role', 'strict_source_scope', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * 登录名的格式。
     *
     * 登录名现在是一门**凭据**（可用于登录、出现在 URL 与 @提及里），
     * 因此限制为 ASCII 的字母开头 + 字母数字与 - _ ：
     *
     *  - 允许中文等任意字符会引入同形字攻击：西里尔字母的 «а» 与拉丁的 «a»
     *    在多数字体里无法区分，'аdmin' 与 'admin' 就成了两个账号；
     *  - 不含 @ 也保证「输入邮箱或登录名都能登录」这条规则不存在歧义。
     *
     * 昵称不受此限 —— 它是展示用的，中文当然可以。
     */
    public const HANDLE_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{2,29}$/';

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
    public const SORTABLE = ['name', 'nickname', 'email', 'role', 'is_active', 'last_login_at', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_set_at' => 'datetime',
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

    /** 对外的展示名：昵称优先，为空时回落到登录名（历史数据兜底）。 */
    public function displayLabel(): string
    {
        return $this->nickname ?: $this->name;
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

    /* ------------------------------------------------------------------ 登录名 */

    public static function isValidHandle(?string $handle): bool
    {
        return is_string($handle) && preg_match(self::HANDLE_PATTERN, $handle) === 1;
    }

    /* ------------------------------------------------------------------ 头像 */

    public function hasLocalAvatar(): bool
    {
        return filled($this->avatar_path);
    }

    /**
     * 头像 URL 的缓存指纹。
     *
     * 文件名本身带随机串，因此每次换头像都会得到新的指纹 ——
     * 这样才能给头像响应打上 immutable 的长缓存，而不是每次刷新都回源。
     */
    public function avatarVersion(): string
    {
        return substr(sha1((string) $this->avatar_path), 0, 10);
    }

    /**
     * 外部渠道带来的头像地址（本地未上传时作为兜底）。
     *
     * 优先取「已核验」的绑定：未核验的绑定是用户自助声明的，
     * 拿它当头像等于让未验证的来源影响展示。
     */
    public function externalAvatarUrl(): ?string
    {
        $identities = $this->relationLoaded('identities')
            ? $this->identities
            : $this->identities()->get();

        return $identities
            ->filter(fn (UserIdentity $identity) => filled($identity->avatar_url))
            ->sortByDesc(fn (UserIdentity $identity) => $identity->isVerified())
            ->first()
            ?->avatar_url;
    }

    /**
     * 最终用于 <img src> 的地址；返回 null 表示没有图片，界面显示首字方块。
     *
     * 把 URL 拼装留在模型里是有意的：管理员列表、账号详情、导航栏、个人设置
     * 四处都要用，散在视图里迟早出现「某个页面忘了带缓存指纹」。
     */
    public function avatarUrl(): ?string
    {
        if ($this->hasLocalAvatar()) {
            return route('avatars.show', ['user' => $this->getKey(), 'v' => $this->avatarVersion()]);
        }

        return $this->externalAvatarUrl();
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

    /* ------------------------------------------------------------------ 登录方式 */

    /**
     * 本人是否掌握一个可用的登录密码。
     *
     * 不能直接看 password 列非空：外部渠道注册的账号也有密码哈希，
     * 但那是一串谁都不知道的随机值。判断依据是 password_set_at。
     */
    public function hasUsablePassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class)->orderBy('provider');
    }

    public function identityFor(IdentityProvider|string $provider): ?UserIdentity
    {
        $value = $provider instanceof IdentityProvider ? $provider->value : $provider;

        if ($this->relationLoaded('identities')) {
            // 不能用 firstWhere('provider', $value)：provider 列已被 casts 转成枚举，
            // 而 `枚举实例 == 'hypergryph'` 恒为 false，会静默查不到任何东西。
            return $this->identities->first(
                fn (UserIdentity $identity) => $identity->provider()->value === $value
            );
        }

        return $this->identities()->ofProvider($value)->first();
    }

    public function hasIdentity(IdentityProvider|string $provider): bool
    {
        return $this->identityFor($provider) !== null;
    }

    /**
     * 除了给定渠道之外，还有没有别的登录方式。
     *
     * 「解绑后会不会被锁在门外」这个问题只在这里回答一次，
     * 避免 IdentityManager 与视图各自实现一遍而漏掉密码那一路。
     */
    public function hasAlternativeLoginMethod(IdentityProvider|string $provider): bool
    {
        if ($this->hasUsablePassword()) {
            return true;
        }

        $value = $provider instanceof IdentityProvider ? $provider->value : $provider;

        return $this->identities()
            ->where('provider', '!=', $value)
            ->exists();
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

    /** 关键词：登录名 / 昵称 / 邮箱。 */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // 转义 LIKE 通配符：否则用户搜一个 % 就会命中全部账号
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $needle = '%'.mb_strtolower($escaped).'%';

        return $query->where(function (Builder $inner) use ($needle) {
            $inner->whereRaw('lower(name) like ?', [$needle])
                ->orWhereRaw('lower(coalesce(nickname, \'\')) like ?', [$needle])
                ->orWhereRaw('lower(email) like ?', [$needle]);
        });
    }

    /**
     * 按登录名精确查找（不区分大小写）。
     *
     * 登录名统一以小写存储，但比对时仍显式 lower()：这样即便将来出现
     * 未归一化的历史数据，语义也不会悄悄变化。同理这三个作用域
     * 被校验规则与「并发冲突后重查」共用，保证两处判断完全一致。
     */
    public function scopeWhereHandleIs(Builder $query, string $handle): Builder
    {
        return $query->whereRaw('lower(name) = ?', [mb_strtolower($handle)]);
    }

    public function scopeWhereNicknameIs(Builder $query, string $nickname): Builder
    {
        return $query->whereRaw('lower(nickname) = ?', [mb_strtolower($nickname)]);
    }

    public function scopeWhereEmailIs(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower(email) = ?', [mb_strtolower($email)]);
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
            'nickname' => $this->nickname,
            'label' => $this->displayLabel(),
            'initials' => $this->initials(),
            'avatar_url' => $this->avatarUrl(),
            'has_local_avatar' => $this->hasLocalAvatar(),
            'email' => $this->email,
            'role' => $this->role()->value,
            'role_label' => $this->role()->label(),
            'role_level' => $this->role()->level(),
            'status' => $this->status()->value,
            'status_label' => $this->status()->label(),
            'status_badge' => $this->status()->badgeClass(),
            'is_active' => $this->isActive(),
            'strict_source_scope' => $this->enforcesSourceScope(),
            'has_password' => $this->hasUsablePassword(),
            'identity_count' => $this->relationLoaded('identities')
                ? $this->identities->count()
                : $this->identities()->count(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
