<?php

namespace App\Http\Requests\Support;

use App\Models\ProblemReport;
use App\Support\ProblemReports\Screenshots;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A problem report (ADR 0096), from the web's dialog or the app's form.
 *
 * The web is always `web`, whatever it says, and its `page` is the path it was
 * on; the app names its own platform and the part of the app the reporter
 * picked. Screenshots are images only, a few, of a sane size — they are drawn
 * again on the way in ({@see Screenshots}).
 */
class StoreProblemReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->fromApp()) {
            $this->merge([
                'platform' => 'web',
                // The path only: a query string can carry a search for a
                // person's name, which a problem report has no need of.
                'page' => is_string($this->input('page')) ? strtok($this->input('page'), '?#') : null,
                'device' => mb_substr((string) $this->userAgent(), 0, 255) ?: null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(ProblemReport::CATEGORIES))],
            'impact' => ['required', Rule::in(array_keys(ProblemReport::IMPACTS))],
            'subject' => ['required', 'string', 'min:4', 'max:120'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'platform' => ['required', Rule::in($this->fromApp() ? ['ios', 'android'] : ['web'])],
            'page' => $this->fromApp()
                ? ['nullable', 'string', 'max:120']
                : ['nullable', 'string', 'max:255', 'starts_with:/'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'device' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'array:viewport,screen,timezone,language,theme'],
            'context.viewport' => ['nullable', 'string', 'max:20'],
            'context.screen' => ['nullable', 'string', 'max:20'],
            'context.timezone' => ['nullable', 'string', 'max:64'],
            'context.language' => ['nullable', 'string', 'max:35'],
            'context.theme' => ['nullable', Rule::in(['light', 'dark'])],
            'screenshots' => ['nullable', 'array', 'max:'.Screenshots::MAX],
            'screenshots.*' => [
                'file',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:'.Screenshots::MAX_KB,
                'dimensions:max_width='.Screenshots::MAX_DIMENSION.',max_height='.Screenshots::MAX_DIMENSION,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Pick what kind of problem it is.',
            'impact.required' => 'Say how much it’s getting in your way.',
            'subject.required' => 'Give the problem a short summary.',
            'subject.min' => 'Give the problem a short summary.',
            'description.required' => 'Tell us what happened.',
            'description.min' => 'Tell us a little more about what happened.',
            'screenshots.max' => 'Attach up to '.Screenshots::MAX.' screenshots.',
            'screenshots.*.mimetypes' => 'Screenshots must be PNG, JPEG or WebP images.',
            'screenshots.*.max' => 'Each screenshot must be under '.(Screenshots::MAX_KB / 1024).' MB.',
            'screenshots.*.dimensions' => 'That image is too large. Attach a screenshot instead.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->safe()->except('screenshots');
    }

    /**
     * @return list<UploadedFile>
     */
    public function screenshots(): array
    {
        return array_values(array_filter((array) $this->file('screenshots', []), fn ($file) => $file instanceof UploadedFile));
    }

    private function fromApp(): bool
    {
        return $this->is('api/*');
    }
}
