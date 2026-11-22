<?php

namespace App\Http\Controllers;

use App\Domains\ClubAccounting\Services\MemberInvitationException;
use App\Enums\ClubMemberStatus;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\User;
use App\Services\Halls\HallBoard;
use App\Services\Provinces\ProvinceGroups;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A province group's admin adds an order's section (its own club), appoints the section's admin, and removes it. */
class ProvinceSectionsController extends Controller
{
    public function index(string $clubSlug): Response
    {
        $group = $this->findGroup($clubSlug);

        return Inertia::render('Admin/Province/Sections', [
            'club' => $group->only(['id', 'name', 'slug']),
            'orders' => $this->availableOrders($group)->get(['id', 'name']),
            'sections' => $group->sections()->with([
                'orderClubType:id,name',
                'users' => fn ($q) => $q->wherePivot('role', 'admin')
                    ->wherePivotIn('status', [ClubMemberStatus::Active->value, ClubMemberStatus::Pending->value])
                    ->orderBy('users.name'),
            ])->orderBy('name')->get()->map(fn (Club $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'order' => $s->orderClubType?->name,
                'admins' => $s->users->map(fn (User $u) => [
                    'name' => $u->name,
                    'pending' => $u->pivot->status === ClubMemberStatus::Pending->value,
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request, string $clubSlug, ProvinceGroups $groups): RedirectResponse
    {
        $group = $this->findGroup($clubSlug);

        $data = $request->validate([
            'order_club_type_id' => ['required', Rule::in($this->availableOrders($group)->pluck('id')->all())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $groups->createSection($group, ClubType::findOrFail($data['order_club_type_id']), $data['name']);
        } catch (DomainException $e) {
            return back()->withErrors(['order_club_type_id' => $e->getMessage()]);
        }

        return back()->with('success', "{$data['name']} added.");
    }

    public function storeAdmin(Request $request, string $clubSlug, int $sectionId, HallBoard $board): RedirectResponse
    {
        $group = $this->findGroup($clubSlug);
        $section = $group->sections()->findOrFail($sectionId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $sent = $board->invite($section, $data['name'], $data['email'], 'Section admin', 'admin', $request->user());
        } catch (DomainException|MemberInvitationException $e) {
            return back()->with('error', rtrim($e->getMessage(), '.').'.');
        }

        return back()->with('success', $sent === null || $sent['emailed']
            ? "Invitation sent to {$data['email']}."
            : "Admin added. Invitation link: {$sent['url']}");
    }

    public function destroy(string $clubSlug, int $sectionId, ProvinceGroups $groups): RedirectResponse
    {
        $group = $this->findGroup($clubSlug);
        $section = $group->sections()->findOrFail($sectionId);

        try {
            $groups->removeSection($section);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$section->name} removed.");
    }

    private function findGroup(string $clubSlug): Club
    {
        $group = $this->findClub($clubSlug);
        abort_unless($group->isProvinceGroup(), 404);

        return $group;
    }

    /**
     * The orders this group has no active section for yet.
     *
     * @return Builder<ClubType>
     */
    private function availableOrders(Club $group)
    {
        return ClubType::orders()
            ->whereNotIn('id', $group->sections()->whereNotNull('order_club_type_id')->select('order_club_type_id'))
            ->orderBy('name');
    }
}
