<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 外部身份流程中的可预期失败：state 过期、被他人绑定、缺少授权码、对方接口不可用……
 *
 * 与 WriteDeniedException 的区别是它不会被全局渲染器处理：
 * 这些失败发生在浏览器跳转链路上，回哪个页面取决于当时在做「登录」还是「绑定」，
 * 只有控制器知道答案，因此由 IdentityController 统一捕获后定向跳转。
 */
class IdentityException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'identity_failed')
    {
        parent::__construct($message);
    }
}
