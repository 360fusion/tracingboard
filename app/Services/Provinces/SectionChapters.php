<?php

namespace App\Services\Provinces;

use App\Models\Club;
use App\Models\Lodge;
use App\Models\LodgeSchedule;
use App\Support\ClubDomain;
use Illuminate\Support\Collection;

/**
 * The lodges or chapters of a section's order in its province: the directory's listing plus any club on the
 * platform that has no directory row. A directory row that a club manages is shown once, as on the platform.
 */
class SectionChapters
{
    /**
     * @return Collection<int, array{name: string, number: ?string, on_platform: bool, site_url: ?string, hall: ?string, town: ?string, meets: ?string}>
     */
    public static function for(Club $section): Collection
    {
        $match = ['province_id' => $section->province_id, 'club_type_id' => $section->order_club_type_id];

        $lodges = Lodge::listed()->where($match)->with(['masonicHall', 'schedules', 'club', 'clubType'])->get();

        $listed = $lodges->map(fn (Lodge $lodge) => [
            'name' => $lodge->displayName(),
            'number' => $lodge->number,
            'on_platform' => $lodge->club !== null,
            'site_url' => $lodge->club ? self::siteUrl($lodge->club) : null,
            'hall' => $lodge->masonicHall?->name,
            'town' => $lodge->masonicHall?->town,
            'meets' => self::meets($lodge->schedules) ?? $lodge->meets_text,
        ]);

        $managed = Club::notGroups()->where($match)->whereNotIn('id', $lodges->pluck('club_id')->filter())->with('masonicHall')->get()->map(fn (Club $club) => [
            'name' => $club->name,
            'number' => $club->lodge_number,
            'on_platform' => true,
            'site_url' => self::siteUrl($club),
            'hall' => $club->masonicHall?->name,
            'town' => $club->masonicHall?->town,
            'meets' => null,
        ]);

        return $listed->concat($managed)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Mirrors pattern() in resources/js/Pages/Admin/Hall/Lodges.vue; keep the two wordings the same.
     *
     * @param  Collection<int, LodgeSchedule>  $schedules
     */
    private static function meets(Collection $schedules): ?string
    {
        return $schedules->map(function (LodgeSchedule $s) {
            $months = array_map('intval', $s->months ?? []);
            $when = count($months) === 12 ? 'every month' : collect($months)->map(fn (int $m) => date('M', mktime(0, 0, 0, $m, 1)))->implode(', ');

            return "{$s->occurrence} {$s->day_of_week}, {$when}".($s->start_time ? ' at '.substr($s->start_time, 0, 5) : '');
        })->implode('; ') ?: null;
    }

    private static function siteUrl(Club $club): string
    {
        return ClubDomain::publicUrl($club, url('/site/'.$club->slug));
    }
}
