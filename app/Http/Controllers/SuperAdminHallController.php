<?php

namespace App\Http\Controllers;

use App\Domains\ClubAccounting\Services\MemberInvitationException;
use App\Http\Requests\MasonicHallDetailsRequest;
use App\Models\MasonicHall;
use App\Models\Province;
use App\Services\Halls\HallBoard;
use App\Services\Halls\HallClubs;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Masonic halls. Split out of SuperAdminController; the URLs, route names and permissions are unchanged.
 */
class SuperAdminHallController extends Controller
{
    /**
     * List every masonic hall, with the number of lodges and other bodies that meet in it.
     */
    public function masonicHallsIndex(): Response
    {
        return Inertia::render('SuperAdmin/MasonicHalls/Index', [
            'halls' => MasonicHall::with(['province:id,name', 'club:id,slug'])->withCount('clubs')->orderBy('name')->get()->each(fn (MasonicHall $h) => $h->setAttribute('club_slug', $h->club?->slug)->unsetRelation('club')),
            'provinces' => Province::orderBy('name')->get(['id', 'name']),
            'kinds' => MasonicHall::KINDS,
        ]);
    }

    /**
     * Add a masonic hall.
     */
    public function storeMasonicHall(Request $request): RedirectResponse
    {
        $validated = $this->validateMasonicHall($request);
        $validated['slug'] = $this->uniqueMasonicHallSlug($validated['name']);

        $hall = MasonicHall::create($validated);

        return redirect()->route('superadmin.masonic_halls.index')->with('success', "'{$hall->name}' added.");
    }

    /**
     * Update a masonic hall.
     */
    public function updateMasonicHall(Request $request, int $id): RedirectResponse
    {
        $hall = MasonicHall::findOrFail($id);
        $hall->update($this->validateMasonicHall($request));

        return redirect()->route('superadmin.masonic_halls.index')->with('success', "'{$hall->name}' updated.");
    }

    /**
     * Set up a hall's board: make its club if it has none, then invite the named person as its owner.
     */
    public function setUpBoard(Request $request, int $id, HallClubs $clubs, HallBoard $board): RedirectResponse
    {
        $hall = MasonicHall::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255']);

        try {
            DB::transaction(function () use ($hall, $data, $clubs, $board, $request) {
                $club = $hall->club ?? $clubs->createFor($hall);
                $board->invite($club, $data['name'], $data['email'], '', 'owner', $request->user());
            });
        } catch (DomainException|MemberInvitationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation sent to {$data['email']}.");
    }

    /**
     * Delete a masonic hall. Lodges that met there are simply left without a hall.
     */
    public function destroyMasonicHall(int $id): RedirectResponse
    {
        $hall = MasonicHall::findOrFail($id);
        $name = $hall->name;
        $hall->delete();

        return redirect()->route('superadmin.masonic_halls.index')->with('success', "'{$name}' deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMasonicHall(Request $request): array
    {
        return $request->validate(['province_id' => 'nullable|exists:provinces,id'] + MasonicHallDetailsRequest::detailRules());
    }

    private function uniqueMasonicHallSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'masonic-hall';
        $slug = $base;

        for ($i = 2; MasonicHall::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
