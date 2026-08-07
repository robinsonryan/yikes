<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Http\Rules\BoundedContext;

/**
 * Validates a new yikes note. The context payload is deliberately loose —
 * it is captured client-side and stored as-is in the note frontmatter; only
 * shape and size are checked, never app-specific structure (the package
 * stays policy-neutral about what an "account" or "department" looks like).
 *
 * NEVER add `context.<key>` sub-rules here: Laravel's
 * `excludeUnvalidatedArrayKeys` then strips every key not explicitly listed,
 * silently discarding whatever a host app's context providers contribute.
 * `context` is validated as one bounded free-shape attribute (BoundedContext),
 * exactly like `state` — bounded, then stored verbatim.
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

            'context' => ['nullable', 'array', new BoundedContext],

            'state' => ['nullable', 'string', 'max:'.$maxStateBytes],

            'screenshots' => ['nullable', 'array', 'max:20'],
            'screenshots.*' => ['string', 'uuid'],
        ];
    }
}
