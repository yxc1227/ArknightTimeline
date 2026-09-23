<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * 外部身份绑定的核验权限。
 *
 * 单独成一个策略而不是塞进 UserPolicy：策略与模型一一对应时，
 * Gate 的自动发现才不需要额外注册，而且「谁能核验身份」这条判断
 * 与「谁能改账号资料」是两件事，将来很可能分道扬镳
 * （例如让审核员也能核验绑定，但不能改角色）。
 */
class UserIdentityPolicy
{
    /**
     * 核验通过：把用户自助登记的绑定转为已核验。
     *
     * 只有管理员能做 —— 「已核验」这个状态对外意味着更高的可信度，
     * 一旦被滥用，这个标记就失去意义。
     */
    public function verify(?User $user, UserIdentity $identity): bool
    {
        return (bool) $user?->isAdmin();
    }

    /** 驳回自助登记（会删除该绑定）。 */
    public function reject(?User $user, UserIdentity $identity): bool
    {
        return (bool) $user?->isAdmin();
    }

    /** 本人或管理员可以解绑；具体的不变量（不能解绑最后一个登录方式）在服务层。 */
    public function unlink(?User $user, UserIdentity $identity): bool
    {
        return (bool) $user && ($user->isAdmin() || $identity->user_id === $user->getKey());
    }
}
