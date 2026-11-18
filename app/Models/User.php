<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $suspended_at
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /** Spatie roles are registered on the web guard; API users resolve them via the model. */
    protected string $guard_name = 'web';

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'phone_verified_at', 'email_verified_at',
        'google_sub', 'avatar_url', 'avatar_path', 'locale', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'google_sub'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The most recent subscription that is not expired (there is at most one by service invariant). */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', '!=', SubscriptionStatus::Expired->value)
            ->latestOfMany('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function devotionalReads(): HasMany
    {
        return $this->hasMany(DevotionalRead::class);
    }

    public function activityDays(): HasMany
    {
        return $this->hasMany(UserActivityDay::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(config('uec.admin.roles'));
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->email ?: ($this->phone ?: 'UEC member'));
    }

    /** Email used for gateway checkouts; gateways require one even for phone-only users. */
    public function billingEmail(): string
    {
        return $this->email ?: strtolower($this->id).'@'.config('uec.payments.placeholder_email_domain');
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', config('uec.admin.roles')));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->whereLike('name', "%{$term}%")
                ->orWhereLike('email', "%{$term}%")
                ->orWhereLike('phone', "%{$term}%")
                ->orWhere('id', $term);
        });
    }
}
