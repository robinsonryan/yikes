<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RobinsonRyan\Yikes\Enums\NoteStatus;

final class UpdateNoteStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(NoteStatus::class)],
        ];
    }
}
