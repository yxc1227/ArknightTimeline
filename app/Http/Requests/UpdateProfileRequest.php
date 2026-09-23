<?php

namespace App\Http\Requests;

use App\Rules\NotReserved;
use App\Rules\UniqueNickname;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人修改自己的资料。
 *
 * 只有一个字段：登录名与邮箱是账号凭据，改动它们等于改「谁能用这个账号登录」。
 * 在本项目还没有邮箱验证流程的前提下，自服务修改邮箱是最典型的账号劫持路径
 * （会话被盗即可永久夺走账号），因此只能由管理员在账号管理里操作。
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nickname' => [
                'required', 'string', 'max:60',
                new NotReserved,
                (new UniqueNickname)->ignore($this->user()?->getKey()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nickname.required' => '昵称不能为空。',
            'nickname.max' => '昵称不能超过 60 个字符。',
        ];
    }

    protected function prepareForValidation(): void
    {
        // 与 UserManager 的归一化保持一致：连续空白折成一个空格
        $this->merge([
            'nickname' => (string) preg_replace('/\s+/u', ' ', trim((string) $this->input('nickname', ''))),
        ]);
    }
}
