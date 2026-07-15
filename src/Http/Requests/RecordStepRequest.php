<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use RobinsonRyan\Yikes\Enums\StepStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(StepStatus::class)],
            'reason' => ['required_if:status,fail', 'nullable', 'string', 'max:5000'],
        ];
    }
}
