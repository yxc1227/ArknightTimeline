<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人设置 / 修改登录密码。
 *
 * 「是否需要当前密码」是条件性的，这是本请求存在的唯一理由：
 *
 *  · 已经掌握密码的人 —— 必须先证明记得旧密码，否则一个被劫持的会话
 *    就能把真正的号主锁在门外；
 *  · 外部渠道注册的账号 —— 库里的密码是随机占位值，本人根本不知道，
 *    此时要求「当前密码」等于让人永远设不了密码（也就永远解绑不了外部身份）。
 *
 * 判断依据是 password_set_at（是否被设置为本人可知），不是 password 列是否非空。
 */
class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        $needsCurrent = $this->user()?->hasUsablePassword() ?? true;

        return [
            // 没有可用密码时写 nullable：空值会直接跳过后面的 current_password 校验
            'current_password' => [$needsCurrent ? 'required' : 'nullable', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '请输入当前密码。',
            'current_password.current_password' => '当前密码不正确。',
            'password.min' => '新密码至少 8 位。',
            'password.confirmed' => '两次输入的新密码不一致。',
        ];
    }
}
