<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 修改账号资料。
 *
 * 刻意不接受 password 字段：改密码走「重置密码」这个独立动作，
 * 因为它是唯一需要把明文返回给操作者一次的操作，混在资料更新里
 * 会让「什么时候密码会被改」变得难以预测。
 */
class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'display_name' => ['nullable', 'string', 'max:60'],
            // 排除自身，否则「只改显示名」也会撞上自己的唯一索引
            'email' => [
                'required', 'string', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'strict_source_scope' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => '该邮箱已被占用。若它属于一个已删除的账号，请先在列表中恢复该账号。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'strict_source_scope' => $this->boolean('strict_source_scope'),
            'is_active' => $this->has('is_active')
                ? $this->boolean('is_active')
                : (bool) $this->route('user')?->isActive(),
        ]);
    }
}
