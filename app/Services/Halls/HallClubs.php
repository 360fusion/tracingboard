<?php

namespace App\Services\Halls;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\MasonicHall;
use App\Models\User;
use App\Support\ClubAccess;
use App\Support\ClubPermissions;
use App\Support\ReservedClubSlugs;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HallClubs
{
    /**
     * Make the hall's own club (its board) and link it to the hall.
     *
     * @param  User|null  $owner  made the active owner; none leaves the club without members (the superadmin invites one)
     *
     * @throws DomainException when the hall already has one
     */
    public function createFor(MasonicHall $hall, ?User $owner = null): Club
    {
        return DB::transaction(function () use ($hall, $owner) {
            $locked = MasonicHall::whereKey($hall->id)->lockForUpdate()->firstOrFail();

            if ($locked->club_id) {
                throw new DomainException('This hall already has a board.');
            }

            $club = Club::create([
                'club_type_id' => ClubType::hall()->id,
                'province_id' => $locked->province_id,
                'name' => $locked->name,
                'slug' => $this->uniqueSlug($locked),
                'status' => 'active',
                'town_city' => $locked->town,
            ]);

            if ($owner) {
                $club->users()->attach($owner->id, ['role' => ClubRole::Owner->value, 'status' => ClubMemberStatus::Active->value]);
            }

            $locked->forceFill(['club_id' => $club->id])->save();
            $hall->club_id = $club->id;

            return $club;
        });
    }

    /**
     * Where someone on a group's board starts: a hall's details or a province group's sections when they may edit
     * them, else the admin dashboard.
     * There is no /{slug}/admin page and a hall has no member area, so every way into a hall comes through here.
     */
    public static function landingUrl(Club $club, ?User $user, bool $absolute = true): string
    {
        return self::landingUrlFor($club, ClubAccess::can($user, $club, self::homeCapability($club)), $absolute);
    }

    /**
     * The same for a hall loaded through `$user->clubs()`, read from its pivot so a list of clubs needs no query each.
     */
    public static function landingUrlFromPivot(Club $club, User $user): string
    {
        $role = ($club->pivot->status ?? null) === ClubMemberStatus::Active->value ? $club->pivot->role : null;

        return self::landingUrlFor($club, $user->actsAsSuperAdmin() || ClubPermissions::allows($club, $role, self::homeCapability($club)));
    }

    private static function homeCapability(Club $club): string
    {
        return $club->isProvinceGroup() ? 'manage_sections' : 'manage_hall';
    }

    private static function landingUrlFor(Club $club, bool $mayOpenHome, bool $absolute = true): string
    {
        if ($club->isProvinceGroup() && $mayOpenHome) {
            return route('admin.sections.index', ['clubSlug' => $club->slug], $absolute);
        }

        return $mayOpenHome && ! $club->isProvinceGroup() && ! $club->isProvinceSection()
            ? route('admin.hall.edit', ['clubSlug' => $club->slug], $absolute)
            : route('admin.analytics', ['slug' => $club->slug], $absolute);
    }

    private function uniqueSlug(MasonicHall $hall): string
    {
        $base = Str::slug($hall->name) ?: 'hall';
        $slug = $base;

        for ($i = 2; ReservedClubSlugs::isReserved($slug) || Club::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
