<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 登录。
 *
 * 字段叫 identifier 而不是 email：登录名与邮箱都能用来登录
 * （两者都全服唯一，因此不存在歧义）。
 * 用 email 这个名字而实际接受登录名，会在别人读代码时埋下一个「为什么这里是邮箱」
 * 的疑问，也会让错误提示的字段名撒谎。
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => '请输入邮箱或登录名。',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifier' => trim((string) $this->input('identifier', '')),
        ]);
    }
}
