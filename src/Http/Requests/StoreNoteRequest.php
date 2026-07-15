<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new yikes note. The context payload is deliberately loose —
 * it is captured client-side and stored as-is in the note frontmatter; only
 * shape and size are checked, never app-specific structure (the package
 * stays policy-neutral about what an "account" or "department" looks like).
 */
final class StoreNoteRequest extends FormRequest
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
        $maxStateBytes = (int) config('yikes.max_state_kb', 256) * 1024;

        return [
            'body' => ['required', 'string', 'max:20000'],
            'title' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::enum(NoteType::class)],
            // Fast-track: a note may be born approved, but never any further
            // along — done/ignored are triage outcomes, not capture inputs.
            'status' => ['nullable', Rule::in([NoteStatus::New->value, NoteStatus::Approved->value])],

            'context' => ['nullable', 'array'],
            'context.url' => ['nullable', 'string', 'max:2048'],
            'context.route' => ['nullable', 'string', 'max:255'],
            'context.page' => ['nullable', 'string', 'max:255'],
            'context.account' => ['nullable', 'array'],
            'context.department' => ['nullable', 'array'],
            'context.dark_mode' => ['nullable', 'boolean'],
            'context.viewport' => ['nullable', 'array'],
            'context.viewport.width' => ['nullable', 'integer'],
            'context.viewport.height' => ['nullable', 'integer'],
            'context.user_agent' => ['nullable', 'string', 'max:1024'],
            'context.title' => ['nullable', 'string', 'max:255'],
            'context.element' => ['nullable', 'array'],
            'context.element.selector' => ['nullable', 'string', 'max:1024'],
            'context.element.tag' => ['nullable', 'string', 'max:64'],
            'context.element.text' => ['nullable', 'string', 'max:1000'],

            'state' => ['nullable', 'string', 'max:' . $maxStateBytes],

            'screenshots' => ['nullable', 'array', 'max:20'],
            'screenshots.*' => ['string', 'uuid'],
        ];
    }
}
