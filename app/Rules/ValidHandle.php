<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 登录名格式。
 *
 * 单独成类而不是把正则直接写进 rules()，是因为同一条规则要同时出现在
 * 新建、修改、外部注册三处，且提示语必须与 UserManager 里的服务层守卫逐字一致 ——
 * 校验说的话和写入时抛的错不一样，是最容易让人困惑的一类不一致。
 */
class ValidHandle implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // 空值交给 required 去报错
        }

        // 服务层会统一转小写再校验，这里保持一致，避免「大写输入通过校验却写不进去」
        if (! User::isValidHandle(mb_strtolower(trim($value)))) {
            $fail('登录名需以字母开头，只能包含字母、数字、下划线与连字符，长度 3-30 位。');
        }
    }
}
