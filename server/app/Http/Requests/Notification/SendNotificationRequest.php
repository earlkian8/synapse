<?php

namespace App\Http\Requests\Notification;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendNotificationRequest extends FormRequest
{
    /**
     * Audiences a notification may be broadcast to.
     *
     * @var list<string>
     */
    public const AUDIENCES = ['all', 'role', 'user'];

    /**
     * Severity levels (drive the icon / colour in the UI).
     *
     * @var list<string>
     */
    public const LEVELS = ['info', 'success', 'warning', 'error'];

    /** A path on this site: one leading slash, no scheme, no second slash, no spaces. */
    public const IN_APP_PATH = '#^/(?![/\\\\])[^\s]*$#';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', 'string', Rule::in(self::AUDIENCES)],
            'role_id' => ['required_if:audience,role', 'nullable', 'integer', TenantRule::exists('roles')],
            'user_id' => ['required_if:audience,user', 'nullable', 'integer', TenantRule::member()],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            // A path inside the app ("/leave"), never an address elsewhere: the
            // link is followed from every recipient's inbox and push alert.
            'url' => ['nullable', 'string', 'max:300', 'regex:'.self::IN_APP_PATH],
            'level' => ['required', 'string', Rule::in(self::LEVELS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['url.regex' => 'The link must be a page in the app, starting with "/" — for example /leave.'];
    }
}
