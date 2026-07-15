<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates edits to an existing note's title and body. Context, state, and
 * screenshots are the immutable capture record — only the text is editable.
 */
final class UpdateNoteRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:20000'],
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }
}
