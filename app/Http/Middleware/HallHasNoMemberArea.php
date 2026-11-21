<?php

namespace App\Http\Middleware;

use App\Models\Club;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hall's board is not its members, so /members/{hall}/... does not exist (superadmins included).
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

            abort_if($club?->isHall(), 404);
        }

        return $next($request);
    }
}
