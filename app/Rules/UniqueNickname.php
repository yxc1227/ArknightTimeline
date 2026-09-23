<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 昵称唯一性：不区分大小写、且**包含软删除的账号**。
 *
 * 为什么不用 Rule::unique：
 *
 *  1. 大小写敏感性取决于数据库排序规则 —— MySQL 的 utf8mb4_unicode_ci 不区分、
 *     SQLite 的 TEXT 区分。同一条数据在测试环境与生产环境的校验结果不同，
 *     是最难排查的一类问题，所以这里显式 lower() 把语义钉死。
 *  2. 唯一索引包含软删除行（被删除的账号仍占着昵称）。Rule::unique 默认也包含，
 *     但只要有人为了「用户体验」加上 withoutTrashed，校验就会与索引脱节、
 *     把 500 带给用户。写在这里可以避免那次「顺手优化」。
 */
class UniqueNickname implements ValidationRule
{
    private ?int $ignoreUserId = null;

    /** 修改资料时排除自己，否则「什么都没改」也会撞上自己的唯一索引。 */
    public function ignore(?int $userId): self
    {
        $this->ignoreUserId = $userId;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $query = User::withTrashed()->whereNicknameIs($this->normalize($value));

        if ($this->ignoreUserId !== null) {
            $query->whereKeyNot($this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail('该昵称已被占用。若它属于一个已删除的账号，请先恢复该账号或换一个昵称。');
        }
    }

    /**
     * 与 UserManager::normalizeNickname 保持一致：先把连续空白折成一个空格再比较，
     * 否则「考据 员」与「考据  员」会在校验层被当成两个、在写入层被当成一个。
     */
    private function normalize(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }
}
