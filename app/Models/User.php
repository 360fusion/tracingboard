<?php

namespace App\Models;

use App\Enums\ClubMemberStatus;
use App\Support\GroupAudience;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Pivot $pivot The club_user row, present on users loaded through a club's users() relation.
 */
// 'is_super_admin' is deliberately NOT fillable: it must never be settable from
// request input. Grant it explicitly via forceFill or the superadmin:grant command.
#[Fillable(['name', 'email', 'avatar_url', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'calendar_token', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var Collection<int, int>|null */
    private ?Collection $groupAudienceClubIds = null;

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
            'is_super_admin' => 'boolean',
            'closure_requested_at' => 'datetime',
            'purged_at' => 'datetime',
            'push_muted' => 'array',
            'passed_on_prefs' => 'array',
        ];
    }

    /**
     * Whether this user may use platform-wide access right now: a superadmin who has two-factor sign-in turned on
     * (where it is required, see superAdminNeedsTwoFactor). Without it, a stolen superadmin password alone would open every
     * lodge's books and members, not just the superadmin area. Use this for access; `is_super_admin` is only the flag.
     */
    public function actsAsSuperAdmin(): bool
    {
        return $this->is_super_admin && ($this->two_factor_confirmed_at !== null || ! self::superAdminNeedsTwoFactor());
    }

    /**
     * Superadmins need two-factor on every real server. Local, test and e2e databases are exempt, and a staging server
     * may switch it off with SUPERADMIN_TWO_FACTOR=false; production always requires it.
     */
    public static function superAdminNeedsTwoFactor(): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        return ! app()->environment(['local', 'testing', 'e2e']) && config('auth.superadmin_two_factor');
    }

    /**
     * @return BelongsToMany<Club, $this, ClubUser>
     */
    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class)
            ->using(ClubUser::class)
            ->withPivot(['role', 'rank', 'committee_role', 'home_club_name', 'home_club_number', 'member_number', 'status', 'phone', 'emergency_contact', 'dietary_notes', 'invitation_token', 'invited_at', 'invitation_accepted_at', 'invitation_reminded_at', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Phones the app is signed in on, for push notifications.
     *
     * @return HasMany<PushDevice, $this>
     */
    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class);
    }

    /** @return HasMany<DeviceSignInKey, $this> */
    public function deviceSignInKeys(): HasMany
    {
        return $this->hasMany(DeviceSignInKey::class);
    }

    /**
     * The provinces whose lodge listings this user looks after (see Province::admins).
     *
     * @return BelongsToMany<Province, $this>
     */
    public function administeredProvinces(): BelongsToMany
    {
        return $this->belongsToMany(Province::class, 'province_admins')->withTimestamps();
    }

    /**
     * The lodges this user follows in the public directory. Private: a lodge never sees who follows it.
     *
     * @return BelongsToMany<Lodge, $this>
     */
    public function followedLodges(): BelongsToMany
    {
        return $this->belongsToMany(Lodge::class, 'lodge_follows')->withPivot(['in_calendar', 'notify_summons'])->withTimestamps();
    }

    /**
     * IDs of the clubs this user is an active member of. A hall board row is not membership, so halls are left out.
     *
     * @return Collection<int, int>
     */
    public function activeClubIds(): Collection
    {
        return $this->clubs()->notGroups()->wherePivot('status', ClubMemberStatus::Active->value)->pluck('clubs.id');
    }

    /**
     * IDs of the hall and section clubs whose "club" audience reaches this user (see GroupAudience).
     * Kept on the instance, so a loop over events does not query per event.
     *
     * @return Collection<int, int>
     */
    public function groupAudienceClubIds(): Collection
    {
        return $this->groupAudienceClubIds ??= GroupAudience::groupClubsFor(
            $this->clubs()->notGroups()->wherePivot('status', ClubMemberStatus::Active->value)->get(['clubs.id', 'clubs.masonic_hall_id', 'clubs.province_id', 'clubs.club_type_id'])
        )->pluck('id');
    }

    /**
     * IDs of the groups (halls, province groups and sections) this user is active in.
     *
     * @return Collection<int, int>
     */
    public function boardGroupClubIds(): Collection
    {
        return $this->clubs()->whereHas('clubType', fn ($type) => $type->whereIn('code', ClubType::GROUP_CODES))
            ->wherePivot('status', ClubMemberStatus::Active->value)->pluck('clubs.id');
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
