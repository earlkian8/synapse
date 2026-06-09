<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable([
    'first_name',
    'middle_name',
    'last_name',
    'suffix',
    'email',
    'password',
    'phone_number',
    'profile_photo',
    'employee_id',
    'is_active',
    'last_login_at',
    'password_changed_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Scope a query to apply a free-text search across the searchable columns.
     *
     * @param  Builder<User>  $query
     */
    public function scopeSearch($query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';

        $query->where(function ($query) use ($needle) {
            foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'employee_id', 'phone_number'] as $column) {
                $query->orWhereRaw('lower('.$column.') like ?', [$needle]);
            }
        });
    }

    /**
     * The accessors to append to the model's serialized form.
     *
     * @var list<string>
     */
    protected $appends = ['full_name', 'avatar'];

    /**
     * Get the public URL of the user's profile photo, if any.
     *
     * Exposed as `avatar` so shared auth props render the photo in the header.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile_photo
                ? Storage::disk('public')->url($this->profile_photo)
                : null,
        );
    }

    /**
     * Get the user's full name, combining their name parts and suffix.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim(implode(' ', array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
                $this->suffix,
            ]))),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }
}
