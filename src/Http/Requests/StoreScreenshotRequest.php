<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an on-demand screenshot upload. The file must be a real image
 * (content-sniffed via mimetypes:, not just the extension) and is capped at
 * `yikes.max_screenshot_kb`.
 */
final class StoreScreenshotRequest extends FormRequest
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
            'screenshot' => [
                'required',
                'file',
                'image',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:' . (int) config('yikes.max_screenshot_kb', 4096),
            ],
        ];
    }
}
