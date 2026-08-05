<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bounds the note context WITHOUT enumerating its keys.
 *
 * The context is free-shape by contract: core capture supplies url/route/page/
 * dark_mode/viewport/user_agent/title/element, and host apps add whatever their
 * own context providers return (surface, organization, abilities, account,
 * department, …). Those unknown keys must reach the note frontmatter verbatim,
 * so `context` is validated as ONE attribute — never with `context.<key>`
 * sub-rules, which would make Laravel's `excludeUnvalidatedArrayKeys` drop
 * every key the package did not think to list.
 *
 * What is still enforced:
 *   - at most MAX_KEYS named keys, each key a string of at most MAX_KEY_LENGTH
 *   - each value is null, a scalar, or a FLAT array of scalars/nulls
 *     (at most MAX_ITEMS_PER_KEY of them) — deeper nesting is rejected
 *   - every string stays under MAX_STRING_LENGTH
 *   - the known keys keep their historical shapes (viewport{width,height} ints,
 *     element{selector,tag,text} bounded strings, dark_mode boolean, …)
 *
 * Overall payload size is already bounded by the body/state caps on the request.
 */
final class BoundedContext implements ValidationRule
{
    public const int MAX_KEYS = 40;

    public const int MAX_KEY_LENGTH = 64;

    public const int MAX_ITEMS_PER_KEY = 40;

    public const int MAX_STRING_LENGTH = 2048;

    /**
     * Shapes the package has always validated. Applied on top of the generic
     * bounds so a malformed viewport/element still 422s, while every other key
     * is accepted as-is.
     *
     * @var array<string, list<string>>
     */
    private const array KNOWN_KEY_RULES = [
        'url' => ['nullable', 'string', 'max:2048'],
        'route' => ['nullable', 'string', 'max:255'],
        'page' => ['nullable', 'string', 'max:255'],
        'account' => ['nullable', 'array'],
        'department' => ['nullable', 'array'],
        'dark_mode' => ['nullable', 'boolean'],
        'viewport' => ['nullable', 'array'],
        'viewport.width' => ['nullable', 'integer'],
        'viewport.height' => ['nullable', 'integer'],
        'user_agent' => ['nullable', 'string', 'max:1024'],
        'title' => ['nullable', 'string', 'max:255'],
        'element' => ['nullable', 'array'],
        'element.selector' => ['nullable', 'string', 'max:1024'],
        'element.tag' => ['nullable', 'string', 'max:64'],
        'element.text' => ['nullable', 'string', 'max:1000'],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not an array: the sibling `array` rule already reports it.
        if (! is_array($value)) {
            return;
        }

        if (count($value) > self::MAX_KEYS) {
            $fail('The :attribute field may not have more than ' . self::MAX_KEYS . ' keys.');

            return;
        }

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                $fail('The :attribute field must be an object of named keys.');

                return;
            }

            if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
                $fail('The :attribute field has a key longer than ' . self::MAX_KEY_LENGTH . ' characters.');

                return;
            }

            if (! $this->validateItem($key, $item, $fail)) {
                return;
            }
        }

        $this->validateKnownKeys($value, $fail);
    }

    /**
     * One context value: scalar/null, or a flat array of scalars/nulls.
     */
    private function validateItem(string $key, mixed $item, Closure $fail): bool
    {
        if ($item === null || is_scalar($item)) {
            return $this->validateScalar($key, $item, $fail);
        }

        if (! is_array($item)) {
            $fail($this->itemMessage($key, 'must be a value or a flat list of values'));

            return false;
        }

        if (count($item) > self::MAX_ITEMS_PER_KEY) {
            $fail($this->itemMessage($key, 'may not have more than ' . self::MAX_ITEMS_PER_KEY . ' values'));

            return false;
        }

        foreach ($item as $nestedKey => $nested) {
            if (is_string($nestedKey) && mb_strlen($nestedKey) > self::MAX_KEY_LENGTH) {
                $fail($this->itemMessage($key, 'has a key longer than ' . self::MAX_KEY_LENGTH . ' characters'));

                return false;
            }

            if ($nested !== null && ! is_scalar($nested)) {
                $fail($this->itemMessage($key, 'is nested too deeply — values may only be scalars or flat lists of scalars'));

                return false;
            }

            if (! $this->validateScalar($key, $nested, $fail)) {
                return false;
            }
        }

        return true;
    }

    private function validateScalar(string $key, mixed $item, Closure $fail): bool
    {
        if (is_string($item) && mb_strlen($item) > self::MAX_STRING_LENGTH) {
            $fail($this->itemMessage($key, 'may not be longer than ' . self::MAX_STRING_LENGTH . ' characters'));

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function validateKnownKeys(array $context, Closure $fail): void
    {
        $validator = Validator::make($context, self::KNOWN_KEY_RULES);

        foreach ($validator->errors()->all() as $message) {
            $fail($message);
        }
    }

    private function itemMessage(string $key, string $problem): string
    {
        return 'The :attribute key [' . Str::limit($key, self::MAX_KEY_LENGTH) . '] ' . $problem . '.';
    }
}
