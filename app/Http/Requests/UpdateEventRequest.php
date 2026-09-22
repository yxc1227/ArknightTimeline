<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEventRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 更新请求。
 *
 * `expected_version` 是**必填**的，这不是普通的表单字段而是协作协议的组成部分：
 * 没有它就无法做 compare-and-swap，也就无法保证不丢更新。
 * 与其在服务端猜，不如让客户端明确声明「我基于哪个版本改的」。
 */
class UpdateEventRequest extends FormRequest
{
    use HasEventRules;

    public function authorize(): bool
    {
        return $this->user()?->canEditEvents() ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:500'],

            // 冲突解决模式：字段名 => mine / theirs / 自定义值
            'resolutions' => ['nullable', 'array'],
            ...$this->eventRules(creating: false),
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->eventMessages(),
            'expected_version.required' => '缺少版本号 expected_version，无法进行并发安全保存。',
        ];
    }

    /** 防止把自己设为自己的父事件 / 起因，造成自环。 */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $eventId = $this->route('event')?->id;

                if (! $eventId) {
                    return;
                }

                foreach (['parent_event_id', 'caused_by_event_id'] as $field) {
                    if ((int) $this->input($field) === (int) $eventId) {
                        $validator->errors()->add($field, '不能把条目自身设为上级或起因。');
                    }
                }
            },
        ];
    }
}
