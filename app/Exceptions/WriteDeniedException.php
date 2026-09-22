<?php

namespace App\Exceptions;

use RuntimeException;

/** 因权限 / 条目状态 / 出处归属而被拒绝写入。与冲突不同：这类拒绝没有「合并」余地。 */
class WriteDeniedException extends RuntimeException
{
    public function __construct(string $reason, public readonly string $code_ = 'write_denied')
    {
        parent::__construct($reason);
    }

    public function toApiPayload(): array
    {
        return [
            'error' => $this->code_,
            'message' => $this->getMessage(),
        ];
    }
}
