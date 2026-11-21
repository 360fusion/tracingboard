<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuperAdminLodgeClaim\RejectRequest;
use App\Http\Requests\SuperAdminLodgeClaim\RequestInfoRequest;
use App\Models\HallClaim;
use App\Services\Halls\HallClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The superadmin's answers to hall claims. They are listed on the lodge claims page
 * (SuperAdminLodgeClaimController::index).
 */
class SuperAdminHallClaimController extends Controller
{
    public function __construct(private readonly HallClaimService $claims) {}

    public function approve(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate(['note' => 'nullable|string|max:1000']);
        $claim = HallClaim::findOrFail($id);

        $club = $this->claims->approve($claim, $request->user(), $validated['note'] ?? null);

        return back()->with('success', "Approved. {$club->name} has been created and {$claim->user->name} is its owner.");
    }

    public function reject(RejectRequest $request, int $id): RedirectResponse
    {
        $this->claims->reject(HallClaim::findOrFail($id), $request->user(), $request->validated()['reason']);

        return back()->with('success', 'The claim was refused and the person has been told why.');
    }

    public function requestInfo(RequestInfoRequest $request, int $id): RedirectResponse
    {
        $this->claims->requestInfo(HallClaim::findOrFail($id), $request->user(), $request->validated()['question']);

        return back()->with('success', 'Your question has been sent.');
    }
}
