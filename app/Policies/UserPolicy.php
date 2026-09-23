<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 账号管理权限。
 *
 * ⚠️ 这里的判定只负责「这一层的角色够不够」，它**看不到全局状态**，
 * 因此无法表达「不能掏空最后一个管理员」这类跨行不变量。
 * 真正的硬约束在 UserManager 里（服务层），本策略的作用是：
 *
 *  1. 让越权在进入控制器前就被 403 掉；
 *  2. 给视图提供「这个按钮该不该显示」的依据。
 *
 * 两层都保留是有意的：策略可能被绕过（命令、队列、新入口），
 * 而服务层是唯一入口，因此不变量必须在服务层再守一次。
 *
 * 自保护两条返回 Response::deny('说明') 而不是 false：
 * 空的 403 会让人以为是权限配错了，而这里其实是「这个操作本身就不该做」，
 * 必须把原因讲清楚。
 */
class UserPolicy
{
    public function viewAny(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function view(?User $user, User $target): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function create(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function update(?User $user, User $target): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function delete(?User $user, User $target): bool|Response
    {
        if (! $user?->isAdmin()) {
            return false;
        }

        return $target->is($user)
            ? Response::deny('不能删除自己的账号 —— 这会立刻让你失去访问权限。')
            : true;
    }

    /** 重置密码对自己是合理的（忘记密码时的自助路径），因此不做 self 限制。 */
    public function resetPassword(?User $user, User $target): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function toggleActive(?User $user, User $target): bool|Response
    {
        if (! $user?->isAdmin()) {
            return false;
        }

        return $target->is($user)
            ? Response::deny('不能禁用或启用自己的账号 —— 这会立刻让你失去访问权限。')
            : true;
    }

    public function bulk(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }

    public function restore(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }
}
