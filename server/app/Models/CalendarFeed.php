<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A person's calendar subscription link for one company (ADR 0070). Calendar
 * apps fetch it without signing in, so the token is the only key: it is found
 * by its sha-256 and kept encrypted, never in plain text. Resetting it gives a
 * new token and the old link stops working.
 */
class CalendarFeed extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'token_hash',
        'token',
        'last_used_at',
    ];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's feed in the current company, made on first ask.
     */
    public static function issueFor(User $user): self
    {
        $feed = static::query()->where('user_id', $user->id)->first();

        if ($feed !== null) {
            return $feed;
        }

        $feed = new static(['user_id' => $user->id, 'organization_id' => app(Tenancy::class)->id()]);
        $feed->fillToken();
        $feed->save();

        return $feed;
    }

    /**
     * The feed a token opens, in whichever company — no tenant is bound when a
     * calendar app asks.
     */
    public static function findByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * The token in plain text, for the link shown to its owner.
     */
    public function plainToken(): string
    {
        return (string) $this->token;
    }

    /**
     * The link in both forms: `webcal://` opens Apple Calendar or Outlook on
     * subscribe; the `https://` one is pasted into Google Calendar ("From URL").
     *
     * @return array{https: string, webcal: string}
     */
    public function links(): array
    {
        $https = url('/api/calendar/'.$this->plainToken().'.ics');

        return ['https' => $https, 'webcal' => (string) preg_replace('#^https?://#', 'webcal://', $https)];
    }

    /**
     * Replace the token; the old link stops working.
     */
    public function reset(): self
    {
        $this->fillToken();
        $this->save();

        return $this;
    }

    private function fillToken(): void
    {
        $token = Str::random(48);

        $this->token = $token;
        $this->token_hash = hash('sha256', $token);
    }
}
