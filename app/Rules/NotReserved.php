<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 保留名（登录名与昵称共用）。
 *
 * 这条规则的目的不是洁癖而是**防冒充**：昵称会出现在时间线的标注、版本记录旁边，
 * 一旦有人把自己叫「博士」或「官方客服」，他的发言看起来就像官方口径。
 * 角色标签全部在保留名单里，因此「精英干员」这种徽章不会同时作为昵称出现。
 */
class NotReserved implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $reserved = array_map(
            fn (mixed $name) => mb_strtolower(trim((string) $name)),
            (array) config('identity.reserved_names', []),
        );

        if (in_array(mb_strtolower(trim($value)), $reserved, true)) {
            $fail('该名称保留给系统使用，请换一个。');
        }
    }
}
