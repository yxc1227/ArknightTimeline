<?php

namespace App\Http\Requests;

use App\Enums\AnnotationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnotationRequest extends FormRequest
{
    /** 标注对访客也开放 —— 纠错门槛必须低。 */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(AnnotationType::class)],
            'field' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'suggested_patch' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return ['body.required' => '标注内容不能为空。'];
    }
}
