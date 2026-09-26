<?php

namespace App\Http\Requests\Concerns;

use App\Rules\NotReserved;
use App\Rules\UniqueNickname;
use App\Rules\ValidHandle;
use Illuminate\Validation\Rule;

/**
 * 账号命名的校验（登录名 / 昵称 / 邮箱）。
 *
 * 四个入口共用同一份定义：管理员建号、管理员改资料、网页自助注册、外部渠道注册。
 *
 * 抽出来的理由不是「少写几行」，而是这几条规则**必须与数据库唯一索引严格对齐**
 * —— 包含软删除行、昵称不区分大小写、登录名统一小写。
 * 任何一处漏掉，结果都是「校验放行 → 插入撞索引 → 用户看到 500」，
 * 而散在四个文件里的规则迟早会漏掉一处。
 */
trait ValidatesAccountNaming
{
    /**
     * @param  int|null  $ignoreUserId  修改资料时排除自己
     * @return array<string, list<mixed>>
     */
    protected function namingRules(?int $ignoreUserId = null): array
    {
        // 条件式地 ignore()，而不是传 null 进去：后者依赖框架内部对字面量 'NULL'
        // 的解析，读代码的人无法一眼确认它是安全的
        $handle = Rule::unique('users', 'name');
        $email = Rule::unique('users', 'email');

        if ($ignoreUserId !== null) {
            $handle->ignore($ignoreUserId);
            $email->ignore($ignoreUserId);
        }

        return [
            'name' => ['required', 'string', new ValidHandle, new NotReserved, $handle],
            'nickname' => [
                'required', 'string', 'max:60',
                new NotReserved,
                (new UniqueNickname)->ignore($ignoreUserId),
            ],
            'email' => ['required', 'string', 'email', 'max:190', $email],
        ];
    }

    /**
     * 命名冲突时的提示。
     *
     * 分管理员与自助两种语境：管理员看到「可先恢复已删除的账号」是有用的，
     * 而自助注册的人没有恢复账号的能力，让他去做一件做不到的事只会造成困惑。
     *
     * @return array<string, string>
     */
    protected function namingMessages(bool $adminContext = true): array
    {
        if ($adminContext) {
            return [
                'name.unique' => '该登录名已被占用。若它属于一个已删除的账号，请先在列表中恢复该账号。',
                'email.unique' => '该邮箱已被占用。若它属于一个已删除的账号，请先在列表中恢复该账号。',
                'nickname.required' => '昵称不能为空：它是账号对外展示的名字，与登录名相互独立。',
            ];
        }

        return [
            'name.unique' => '该登录名已被占用，请换一个。',
            'email.unique' => '该邮箱已在本站注册过，请直接登录，或联系管理员处理。',
            'nickname.required' => '请填写昵称：它是你在时间线上的展示名。',
        ];
    }

    /**
     * 把三个字段归一化后再交给校验。
     *
     * 必须与服务层写入时的归一化完全一致：否则「Admin」会通过校验、
     * 写进库里变成「admin」，再遇到另一个「admin」时才在唯一索引上炸掉。
     */
    protected function normaliseNamingInput(): void
    {
        $this->merge([
            'name' => mb_strtolower(trim((string) $this->input('name', ''))),
            'nickname' => (string) preg_replace('/\s+/u', ' ', trim((string) $this->input('nickname', ''))),
            'email' => mb_strtolower(trim((string) $this->input('email', ''))),
        ]);
    }
}
