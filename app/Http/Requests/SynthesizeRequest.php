<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 触发 AI 梳理。
 *
 * 原文来源二选一：
 *  - source_id +（可选）覆盖 raw_text：按出处梳理；
 *  - raw_text 直接给：即席梳理粘贴的片段。
 */
class SynthesizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canEditEvents() ?? false;
    }

    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'era_id' => ['nullable', 'integer', 'exists:eras,id'],
            'raw_text' => ['nullable', 'string', 'min:20', 'max:200000'],
            'instruction' => ['nullable', 'string', 'max:500'],
            'persist_raw_text' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (blank($this->input('raw_text')) && ! $this->filled('source_id')) {
                $v->errors()->add('raw_text', '请选择出处或直接粘贴待梳理的原文。');
            }
        });
    }
}
