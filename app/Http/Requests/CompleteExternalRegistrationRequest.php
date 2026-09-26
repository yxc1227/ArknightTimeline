<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountNaming;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 外部渠道注册的第二步：补全本站资料。
 *
 * 外部渠道只告诉我们「他确实是某个外部账号的持有者」，
 * 并不会给出一个本系统可用的登录名 / 昵称 / 邮箱 ——
 * 那三个都是本站的命名空间，必须由本人当场选定，且各自唯一。
 *
 * 这里不要求密码：外部渠道注册出来的账号本来就靠渠道登录，
 * 密码可以在「账号设置」里随时补设（届时不需要验证旧密码，
 * 因为库里那个随机占位值连他本人也不知道）。
 */
class CompleteExternalRegistrationRequest extends FormRequest
{
    use ValidatesAccountNaming;

    public function rules(): array
    {
        return $this->namingRules();
    }

    public function messages(): array
    {
        return $this->namingMessages(adminContext: false);
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNamingInput();
    }
}
