<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'display_name', 'email', 'password', 'role', 'strict_source_scope'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
    ];

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
        ];
    }

    public function role(): UserRole
    {
        // $this->role 已由 casts 转成枚举；这里再兜一层，避免历史数据里的空值放行
        return $this->role instanceof UserRole ? $this->role : UserRole::Viewer;
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

    public function ownedSourceIds(): array
    {
        return $this->sources()->pluck('sources.id')->all();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'role' => $this->role()->value,
            'role_label' => $this->role()->label(),
        ];
    }
}
