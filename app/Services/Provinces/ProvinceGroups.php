<?php

namespace App\Services\Provinces;

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
                'slug' => $this->uniqueSlug($locked),
                'status' => 'active',
            ]);
        });
    }

    private function uniqueSlug(Province $province): string
    {
        $base = Str::slug($province->name) ?: 'province';
        $slug = $base;

        for ($i = 2; ReservedClubSlugs::isReserved($slug) || Club::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
