<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountNaming;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 网页自助注册。
 *
 * 这是唯一一条不需要管理员介入、也不需要外部渠道授权的建号路径，
 * 因此它同时也是唯一一条**匿名用户能直接触发数据库写入**的路径。
 * 三道闸门分别在：
 *   · 路由上的 throttle 中间件（按 IP 限流，挡住批量刷号）；
 *   · 本请求的校验（命名与唯一性，与数据库索引严格对齐）；
 *   · UserManager::register 的不变量（角色固定最低档、出处范围限制固定开启）。
 */
class RegisterRequest extends FormRequest
{
    use ValidatesAccountNaming;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->namingRules(),
            // 密码由本人当场设定，因此从一开始就是「本人可知」的
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->namingMessages(adminContext: false),
            'password.min' => '密码至少 8 位。',
            'password.confirmed' => '两次输入的密码不一致。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNamingInput();
    }
}
