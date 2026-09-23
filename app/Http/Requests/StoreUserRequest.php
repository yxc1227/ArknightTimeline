<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 新建账号。
 *
 * 校验必须与数据库约束**完全对齐**：users.email 上有唯一索引，
 * 而 Rule::unique 默认**包含软删除行**（withoutTrashed 是显式选项）。
 * 这是刻意的 —— 若校验放行了已被软删除账号占用的邮箱，
 * 插入时就会撞唯一索引，用户拿到的是 500 而不是可读的提示。
 */
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'display_name' => ['nullable', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            // 留空则由系统生成随机密码，因此这里是 nullable
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'strict_source_scope' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => '该邮箱已被占用。若它属于一个已删除的账号，请先在列表中恢复该账号。',
            'password.min' => '密码至少 8 位；留空则由系统生成随机密码。',
        ];
    }

    protected function prepareForValidation(): void
    {
        // 复选框未勾选时浏览器不会提交该字段，用 FormRequest 兜住默认值
        $this->merge([
            'strict_source_scope' => $this->boolean('strict_source_scope'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }
}
