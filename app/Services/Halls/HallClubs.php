<?php

namespace App\Services\Halls;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\MasonicHall;
use App\Models\User;
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
