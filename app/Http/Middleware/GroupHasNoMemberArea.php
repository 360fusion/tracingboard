<?php

namespace App\Http\Middleware;

use App\Models\Club;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A group's board (a hall, province group or section) is not its members, so /members/{group}/... does not exist
 * (superadmins included), apart from its events and single posts, which its audience uses (see GroupAudience).
 * A province group has no audience, so all of it is a 404. Self-activates on every route under /members/{slug}, so new member pages are covered.
 */
class GroupHasNoMemberArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');
        $uri = $request->route()->uri();

        if (is_string($slug) && str_starts_with($uri, 'members/{slug}')) {
            $club = $request->attributes->get("club:{$slug}")
                ?? tap(Club::where('slug', $slug)->with('clubType')->first(), fn (?Club $club) => $club && $request->attributes->set("club:{$slug}", $club));

            // The open sections: a group's events and its posts one by one, for its audience.
            $isOpen = $uri === 'members/{slug}/events' || str_starts_with($uri, 'members/{slug}/events/') || $uri === 'members/{slug}/news/{id}';
            $inAudience = $club?->isGroup() && $isOpen && $request->user()?->groupAudienceClubIds()->contains($club->id);

            abort_if($club?->isGroup() && ! ($isOpen && $inAudience), 404);
        }

        return $next($request);
    }
}
