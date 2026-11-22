<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuperAdminLodgeCorrection\ApplyRequest;
use App\Http\Requests\SuperAdminLodgeCorrection\Reject2Request;
use App\Models\Club;
use App\Models\LodgeCorrection;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The queue of suggested fixes for a section's editors: only the unmanaged lodges of the section's order in its province.
 */
class SectionLodgeCorrectionController extends ProvinceLodgeCorrectionController
{
    /**
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return SectionLodgeController::middleware();
    }

    private function section(): Club
    {
        return $this->findClub(request()->route('clubSlug'));
    }

    protected function scope(Request $request): Builder
    {
        $section = $this->section();

        return LodgeCorrection::whereHas('lodge', fn (Builder $lodge) => $lodge->where('province_id', $section->province_id)
            ->where('club_type_id', $section->order_club_type_id)->whereNull('club_id'));
    }

    public function sectionApply(ApplyRequest $request, string $clubSlug, int $id): RedirectResponse
    {
        return $this->apply($request, $id);
    }

    public function sectionReject(Reject2Request $request, string $clubSlug, int $id): RedirectResponse
    {
        return $this->reject($request, $id);
    }

    protected function hallProvinceIds(Request $request): Collection
    {
        return collect([$this->section()->province_id])->filter()->values();
    }

    protected function screen(): array
    {
        return ['routes' => 'admin.chapters.fixes', 'layout' => 'admin', 'club' => $this->section()->only(['name', 'slug']), 'params' => ['clubSlug' => $this->section()->slug]];
    }
}
