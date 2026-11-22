<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Lodge;
use App\Models\Province;
use App\Support\ClubAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The province lodge listings screen for a section's editors: only the lodges of the section's order in its province.
 */
class SectionLodgeController extends ProvinceLodgeController
{
    /**
     * Refuse anyone without manage_events on a section before a form is validated, so they get a 403 and not a form error.
     *
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next): Response {
            $section = Club::where('slug', $request->route('clubSlug'))->firstOrFail();
            abort_unless(ClubAccess::can($request->user(), $section, 'manage_events'), 403);
            abort_unless($section->isProvinceSection(), 404);

            // The parent's redirects name the index route without parameters.
            URL::defaults(['clubSlug' => $section->slug]);

            return $next($request);
        }];
    }

    private function section(): Club
    {
        return $this->findClub(request()->route('clubSlug'));
    }

    protected function scope(Request $request): Builder
    {
        return Lodge::where('province_id', $this->section()->province_id)->where('club_type_id', $this->section()->order_club_type_id);
    }

    protected function provinces(Request $request): Builder
    {
        return Province::whereKey($this->section()->province_id);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['club_type_id' => $this->section()->order_club_type_id]);

        return parent::store($request);
    }

    public function sectionUpdate(Request $request, string $clubSlug, int $id): RedirectResponse
    {
        $request->merge(['club_type_id' => $this->section()->order_club_type_id]);

        return $this->update($request, $id);
    }

    protected function screen(): array
    {
        return ['routes' => 'admin.chapters.listings', 'layout' => 'admin', 'full' => false, 'club' => $this->section()->only(['name', 'slug']), 'params' => ['clubSlug' => $this->section()->slug]];
    }
}
