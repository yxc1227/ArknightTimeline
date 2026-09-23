<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesAccountNaming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 修改账号资料（管理员）。
 *
 * 刻意不接受 password 字段：改密码走「重置密码」这个独立动作，
 * 因为它是唯一需要把明文返回给操作者一次的操作，混在资料更新里
 * 会让「什么时候密码会被改」变得难以预测。
 *
 * 登录名与昵称的唯一性校验都会排除自身，否则「只改邮箱」也会撞上自己的索引。
 */
class UpdateUserRequest extends FormRequest
{
    use ValidatesAccountNaming;

    public function rules(): array
    {
        return [
            ...$this->namingRules($this->route('user')?->id),
            'role' => ['required', Rule::enum(UserRole::class)],
            'strict_source_scope' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return $this->namingMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNamingInput();

        $this->merge([
            'strict_source_scope' => $this->boolean('strict_source_scope'),
            'is_active' => $this->has('is_active')
                ? $this->boolean('is_active')
                : (bool) $this->route('user')?->isActive(),
        ]);
    }
}
