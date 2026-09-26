<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 批量账号操作。
 *
 * ids 只校验「是整数」，不校验 exists：被选中的行可能刚被他人删除，
 * 那种情况应该由服务层逐条回报「该账号不存在」，
 * 而不是让整批操作以一条笼统的 422 失败 —— 后者会让人反复重试。
 */
class BulkUserActionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => '请先勾选要操作的账号。',
            'ids.min' => '请先勾选要操作的账号。',
        ];
    }
}
