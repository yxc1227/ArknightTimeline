<?php

namespace App\Models;

use App\Enums\UserAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户操作日志（账号维度的审计轨迹）。
 *
 * 「谁在什么时候把这个账号改成了什么」是用户管理页最容易说不清的问题，
 * 因此这里把两类信息分开存：
 *
 *  - `action`  机器可读的动作类型（枚举），用于筛选与着色；
 *  - `description` 一句话说明，直接展示；
 *  - `field_changes` **已渲染**的字段级变化 {字段: {label, from, to}}，如
 *    `{ role: { label: '角色', from: '访客', to: '编辑者' } }`。
 *
 * 关于 `field_changes` 存渲染值而不是原始值：这是一张面向人的审计表，
 * 存 'editor' 就意味着展示层要再判断一次「这个字段是角色还是状态」才能翻译，
 * 把枚举翻译散落到视图里迟早会漏。原始值可从 event_revisions 等业务表回溯。
 *
 * ⚠️ 列名为什么是 field_changes 而不是 changes：
 * Eloquent 的 HasAttributes 特性自带 `protected $changes`（属性脏值缓存）。
 * 若列名取 changes，类外 `$log->changes` 会经 __get 拿到数据库列，而
 * 类内 `$this->changes` 会直接命中那个内部数组 —— 后者恒为初始空数组，
 * 于是模型内部方法静默读到空值。这种「内外不一致」不报任何错，只能在改名时避开。
 *
 * 日志只增不改，因此没有 updated_at（与 event_revisions 同构）。
 */
#[Fillable(['user_id', 'actor_id', 'action', 'description', 'field_changes', 'ip_address'])]
class UserActivityLog extends Model
{
    /** 审计表不可修改：显式关掉 updated_at，避免 Eloquent 自动写入。 */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => UserAction::class,
            'field_changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** 日志主体：这条记录描述的是哪个账号。 */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** 操作人；为空表示系统动作。 */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function actorLabel(): string
    {
        return $this->actor?->displayLabel() ?? '系统';
    }

    /**
     * 把 field_changes 展平成视图可直接渲染的行。
     *
     * 注意这里用 `$this->field_changes` 而不是任何名为 changes 的引用 ——
     * 后者在类内会撞上 Eloquent 的 protected $changes（详见类注释）。
     *
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changeRows(): array
    {
        $rows = [];

        foreach ($this->field_changes ?? [] as $field => $delta) {
            $rows[] = [
                // label 由 UserManager 写入；缺省时回落到字段名，避免历史日志渲染成空白
                'field' => (string) ($delta['label'] ?? $field),
                'from' => (string) ($delta['from'] ?? '—'),
                'to' => (string) ($delta['to'] ?? '—'),
            ];
        }

        return $rows;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'action_badge' => $this->action->badgeClass(),
            'description' => $this->description,
            'actor' => $this->actorLabel(),
            'ip_address' => $this->ip_address,
            // 对外仍叫 changes：这是接口字段名，不会与 Eloquent 内部属性冲突
            'changes' => $this->changeRows(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
