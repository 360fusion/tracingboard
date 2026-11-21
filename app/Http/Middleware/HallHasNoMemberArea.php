<?php

namespace App\Http\Middleware;

use App\Models\Club;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hall's board is not its members, so /members/{hall}/... does not exist (superadmins included), apart from its events, which the members of its lodges use.
 * Self-activates on every route under /members/{slug}, so new member pages are covered.
 */
class HallHasNoMemberArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        if (is_string($slug) && str_starts_with($request->route()->uri(), 'members/{slug}')) {
            $club = $request->attributes->get("club:{$slug}")
                ?? tap(Club::where('slug', $slug)->with('clubType')->first(), fn (?Club $club) => $club && $request->attributes->set("club:{$slug}", $club));

            // The one open section: a hall's events, for members of the lodges that meet there.
            $isEvents = $request->route()->uri() === 'members/{slug}/events' || str_starts_with($request->route()->uri(), 'members/{slug}/events/');
            $inAudience = $club !== null && (bool) $request->user()?->hallAudienceClubIds()->contains($club->id);

            abort_if($club?->isHall() && ! ($isEvents && $inAudience), 404);
        }

        return $next($request);
    }
}
