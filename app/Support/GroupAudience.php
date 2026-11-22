<?php

namespace App\Support;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\Lodge;
use App\Models\MasonicHall;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Halls and province sections have no members of their own: their "club" audience is the active members of other
 * clubs. A hall reaches the lodges that meet there; a section reaches the clubs of its order in its province.
 * Being on a group's board is never membership.
 */
final class GroupAudience
{
    /**
     * The ids of the platform clubs (never groups) whose members a group reaches.
     *
     * @return Collection<int, int>
     */
    public static function memberClubIds(Club $group): Collection
    {
        if ($group->isHall()) {
            return $group->hall?->memberClubIds() ?? collect();
        }

        if ($group->isProvinceSection() && $group->province_id && $group->order_club_type_id) {
            return Club::notGroups()->where('province_id', $group->province_id)->where('club_type_id', $group->order_club_type_id)->pluck('id');
        }

        return collect();
    }

    /**
     * The hall and section clubs whose audience includes any of the given clubs, with their type loaded.
     * One query for the clubs and one for their types, however many clubs are given.
     *
     * @param  Collection<int, Club>  $clubs
     * @return Collection<int, Club>
     */
    public static function groupClubsFor(Collection $clubs): Collection
    {
        $hallIds = $clubs->pluck('masonic_hall_id')->filter()->unique()->values();
        $orders = $clubs->filter(fn (Club $club) => $club->province_id && $club->club_type_id)
            ->unique(fn (Club $club) => $club->province_id.'-'.$club->club_type_id);

        if ($hallIds->isEmpty() && $orders->isEmpty()) {
            return collect();
        }

        return Club::with('clubType')->where(function (Builder $query) use ($hallIds, $orders): void {
            if ($hallIds->isNotEmpty()) {
                $query->orWhere(fn (Builder $halls) => $halls
                    ->whereHas('clubType', fn ($type) => $type->where('code', ClubType::HALL))
                    ->whereIn('id', MasonicHall::whereIn('id', $hallIds)->whereNotNull('club_id')->select('club_id')));
            }

            if ($orders->isNotEmpty()) {
                $query->orWhere(fn (Builder $sections) => $sections
                    ->whereHas('clubType', fn ($type) => $type->where('code', ClubType::PROVINCE_SECTION))
                    ->where(function (Builder $pairs) use ($orders): void {
                        foreach ($orders as $club) {
                            $pairs->orWhere(fn (Builder $pair) => $pair->where('province_id', $club->province_id)->where('order_club_type_id', $club->club_type_id));
                        }
                    }));
            }
        })->get();
    }

    /**
     * How the "club members only" audience reads on a group's forms.
     */
    public static function audienceLabel(Club $group): string
    {
        if ($group->isHall()) {
            return 'Members of lodges that meet here';
        }

        $order = $group->orderClubType;
        $word = Str::plural(strtolower(Lodge::typeWordFor($order?->code) ?? 'club'));

        return "Members of {$order?->name} {$word}".($group->province ? " in {$group->province->name}" : '');
    }
}
