<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEventRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    use HasEventRules;

    public function authorize(): bool
    {
        return $this->user()?->canEditEvents() ?? false;
    }

    public function rules(): array
    {
        return $this->eventRules(creating: true);
    }

    public function messages(): array
    {
        return $this->eventMessages();
    }
}
