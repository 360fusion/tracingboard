<?php

namespace App\Services\Provinces;

use App\Enums\EventStatus;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\Province;
use App\Support\ReservedClubSlugs;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvinceGroups
{
    public function groupFor(Province $province): ?Club
    {
        return Club::where('club_type_id', ClubType::provinceGroup()->id)->where('province_id', $province->id)->first();
    }

    /**
     * Make the province's group club. The province row is locked so two requests cannot both make one.
     *
     * @throws DomainException when the province already has one
     */
    public function createGroup(Province $province): Club
    {
        return DB::transaction(function () use ($province) {
            $locked = Province::whereKey($province->id)->lockForUpdate()->firstOrFail();

            if ($this->groupFor($locked)) {
                throw new DomainException('This province already has a group.');
            }

            return Club::create([
                'club_type_id' => ClubType::provinceGroup()->id,
                'province_id' => $locked->id,
                'name' => $locked->name,
                'slug' => $this->uniqueSlug($locked->name, 'province'),
                'status' => 'active',
            ]);
        });
    }

    /**
     * Add an order's section to a group. The group row is locked so two requests cannot both add the same order.
     *
     * @throws DomainException when the group already has an active section for the order
     */
    public function createSection(Club $group, ClubType $order, string $name): Club
    {
        return DB::transaction(function () use ($group, $order, $name) {
            Club::whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($group->sections()->where('order_club_type_id', $order->id)->exists()) {
                throw new DomainException("This province already has a {$order->name} section.");
            }

            return Club::create([
                'club_type_id' => ClubType::provinceSection()->id,
                'province_id' => $group->province_id,
                'parent_club_id' => $group->id,
                'order_club_type_id' => $order->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($group->slug.' '.$order->name, 'section'),
                'status' => 'active',
            ]);
        });
    }

    /**
     * Take a section out of its group. Nothing is deleted, so its financial rows stay; the order can be added again.
     *
     * @throws DomainException when the section has upcoming events
     */
    public function removeSection(Club $section): void
    {
        $hasUpcoming = $section->events()
            ->where('status', EventStatus::Upcoming->value)
            ->where('starts_at', '>=', now())
            ->exists();

        if ($hasUpcoming) {
            throw new DomainException('This section has upcoming events.');
        }

        $section->update(['status' => 'inactive', 'parent_club_id' => null]);
    }

    private function uniqueSlug(string $text, string $fallback): string
    {
        $base = Str::slug($text) ?: $fallback;
        $slug = $base;

        for ($i = 2; ReservedClubSlugs::isReserved($slug) || Club::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
