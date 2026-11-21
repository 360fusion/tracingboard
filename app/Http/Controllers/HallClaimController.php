<?php

namespace App\Http\Controllers;

use App\Http\Requests\HallClaim\StoreHallClaimRequest;
use App\Http\Requests\LodgeClaim\RespondRequest;
use App\Models\HallClaim;
use App\Models\MasonicHall;
use App\Services\Halls\HallClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking to run a hall's board from its directory page, and following up on the request. Every change goes through
 * HallClaimService.
 */
class HallClaimController extends Controller
{
    public function __construct(private readonly HallClaimService $claims) {}

    public function create(Request $request, string $slug): Response|RedirectResponse
    {
        $hall = MasonicHall::with('province:id,name')->where('slug', $slug)->firstOrFail();

        if ($hall->club_id) {
            return redirect()->route('lodges.hall', $hall->slug)->with('error', 'This hall already has a board. Ask the person who runs it for access.');
        }

        $claim = HallClaim::where('masonic_hall_id', $hall->id)->where('user_id', $request->user()->id)->open()->latest('id')->first();

        return Inertia::render('Lodges/HallClaim', [
            'hall' => ['slug' => $hall->slug, 'name' => $hall->name, 'town' => $hall->town, 'province' => $hall->province?->name],
            'roles' => HallClaim::ROLES,
            'claim' => $claim ? ['id' => $claim->id, 'status' => $claim->status, 'question' => $claim->question] : null,
        ]);
    }

    public function store(StoreHallClaimRequest $request, string $slug): RedirectResponse
    {
        $hall = MasonicHall::where('slug', $slug)->firstOrFail();

        $this->claims->submit($hall, $request->user(), collect($request->validated())->only(['claimant_role', 'message', 'phone'])->all());

        return redirect()->route('lodges.hall', $hall->slug)->with('success', 'Your claim has been sent. A member of our team will check it and be in touch.');
    }

    public function respond(RespondRequest $request, string $slug, int $id): RedirectResponse
    {
        $this->claims->respond($this->ownClaim($slug, $id), $request->user(), $request->validated()['reply']);

        return redirect()->route('lodges.hall', $slug)->with('success', 'Thank you. Your reply has been sent.');
    }

    public function destroy(Request $request, string $slug, int $id): RedirectResponse
    {
        $this->claims->withdraw($this->ownClaim($slug, $id), $request->user());

        return redirect()->route('lodges.hall', $slug)->with('success', 'Your claim has been withdrawn.');
    }

    private function ownClaim(string $slug, int $id): HallClaim
    {
        return HallClaim::whereKey($id)->whereHas('hall', fn ($q) => $q->where('slug', $slug))->firstOrFail();
    }
}
