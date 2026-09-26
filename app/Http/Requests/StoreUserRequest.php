<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesAccountNaming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 新建账号（管理员）。
 *
 * 命名相关的规则来自 ValidatesAccountNaming —— 它与自助注册、外部注册共用同一份定义，
 * 因为这几条必须与数据库唯一索引严格对齐（含软删除行、昵称不区分大小写）。
 */
class StoreUserRequest extends FormRequest
{
    use ValidatesAccountNaming;

    public function rules(): array
    {
        return [
            ...$this->namingRules(),
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
            ...$this->namingMessages(),
            'password.min' => '密码至少 8 位；留空则由系统生成随机密码。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNamingInput();

        $this->merge([
            // 复选框未勾选时浏览器不会提交该字段，用 FormRequest 兜住默认值
            'strict_source_scope' => $this->boolean('strict_source_scope'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }
}
