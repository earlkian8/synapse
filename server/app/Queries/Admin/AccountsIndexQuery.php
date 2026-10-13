<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Queries\Admin\Concerns\ReadsListFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Every ERP sign-in account on the platform (ADR 0085) — identities, whichever
 * organisations they belong to (ADR 0023), or none yet.
 */
class AccountsIndexQuery
{
    use ReadsListFilters;

    public const SORTABLE = ['name', 'email', 'created_at', 'last_login_at', 'memberships_count'];

    public const STATUSES = ['all', 'active', 'inactive', 'unverified', 'no_workspace', 'archived'];

    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        [$sort, $direction] = $this->sort($request);

        $query = User::query()
            ->withCount('memberships')
            ->tap(fn (Builder $query) => $this->matchTokens($query, $this->search($request)))
            ->tap(fn (Builder $query) => match ($this->status($request)) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                'unverified' => $query->whereNull('email_verified_at'),
                'no_workspace' => $query->doesntHave('memberships'),
                'archived' => $query->onlyTrashed(),
                default => $query,
            });

        if ($sort === 'name') {
            $query->orderBy('last_name', $direction)->orderBy('first_name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
    }

    /**
     * "Ana Reyes" finds Ana Reyes: every word must match one of the searchable
     * columns ({@see User::scopeSearch()}), so the words are ANDed.
     *
     * @param  Builder<User>  $query
     */
    private function matchTokens(Builder $query, string $term): void
    {
        foreach (array_slice(preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $token) {
            $query->search($token);
        }
    }

    /**
     * @return array{total: int, active_30d: int, unverified: int, inactive: int, no_workspace: int, mobile_sessions: int}
     */
    public function stats(): array
    {
        return [
            'total' => User::query()->count(),
            'active_30d' => User::query()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'unverified' => User::query()->whereNull('email_verified_at')->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
            'no_workspace' => User::query()->doesntHave('memberships')->count(),
            'mobile_sessions' => self::liveMobileTokens()->count(),
        ];
    }

    /**
     * The phone app's tokens that can still be used.
     *
     * @return Builder<PersonalAccessToken>
     */
    public static function liveMobileTokens(): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
