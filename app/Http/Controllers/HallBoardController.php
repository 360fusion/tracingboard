<?php

namespace App\Http\Controllers;

use App\Domains\ClubAccounting\Services\MemberInvitationException;
use App\Domains\ClubAccounting\Services\MemberInvitationService;
use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Services\Halls\HallBoard;
use App\Support\ClubAccess;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people on a hall's board: invited by email, given an office title and an access role. A board row never
 * counts as lodge membership.
 */
class HallBoardController extends Controller
{
    public const OFFICE_ROLES = HallBoard::OFFICE_ROLES;

    private const ROLES = ['owner', 'admin', 'secretary', 'treasurer'];

    public function index(string $clubSlug): Response
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isHall(), 404);

        return Inertia::render('Admin/Hall/Board', [
            'club' => $club,
            'people' => $club->users()->wherePivotIn('status', [ClubMemberStatus::Active->value, ClubMemberStatus::Pending->value])
                ->orderBy('users.name')->get()->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'office' => $u->pivot->committee_role,
                    'role' => $u->pivot->role,
                    'pending' => $u->pivot->status === ClubMemberStatus::Pending->value,
                ])->values(),
            'officeRoles' => HallBoard::OFFICE_ROLES,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request, string $clubSlug, HallBoard $board): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isHall(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'office' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        abort_unless(ClubAccess::canAssignRole($request->user(), $club, $data['role'], null), 403, 'Your role cannot give this role.');

        try {
            $sent = $board->invite($club, $data['name'], $data['email'], $data['office'], $data['role'], $request->user());
        } catch (DomainException|MemberInvitationException $e) {
            return back()->with('error', rtrim($e->getMessage(), '.').'.');
        }

        return back()->with('success', $sent === null || $sent['emailed']
            ? "Invitation sent to {$data['email']}."
            : "Board member added. Invitation link: {$sent['url']}");
    }

    public function update(Request $request, string $clubSlug, int $userId): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isHall(), 404);

        $data = $request->validate([
            'office' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $user = $this->boardMember($club, $userId);

        // Owners are locked while the last-owner check and the write happen, so two owners cannot demote each other at once.
        $error = DB::transaction(function () use ($request, $club, $userId, $data) {
            $this->lockOwners($club);
            $current = $club->users()->where('users.id', $userId)->first()->pivot->role;

            abort_unless(ClubAccess::canAssignRole($request->user(), $club, $data['role'], $current, $userId), 403, 'Your role cannot change this person.');
            if ($current === ClubRole::Owner->value && $data['role'] !== $current && $this->isLastOwner($club, $userId)) {
                return 'A hall needs at least one owner.';
            }

            $club->users()->updateExistingPivot($userId, ['committee_role' => $data['office'], 'role' => $data['role']]);

            return null;
        });

        return $error ? back()->with('error', $error) : back()->with('success', "{$user->name} updated.");
    }

    public function destroy(Request $request, string $clubSlug, int $userId, MemberInvitationService $invitations): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isHall(), 404);

        $user = $this->boardMember($club, $userId);

        $error = DB::transaction(function () use ($request, $club, $userId, $user, $invitations) {
            $this->lockOwners($club);
            $role = $club->users()->where('users.id', $userId)->first()->pivot->role;

            abort_if($role === ClubRole::Owner->value && ! $request->user()->actsAsSuperAdmin() && ClubAccess::role($request->user(), $club) !== ClubRole::Owner->value, 403, 'Only an owner can remove another owner.');
            if ($role === ClubRole::Owner->value && $this->isLastOwner($club, $userId)) {
                return 'A hall needs at least one owner.';
            }

            // Someone who never signed in is taken off again; anyone else keeps their history as a past board member.
            $invitations->revoke($club, $user);
            $club->users()->updateExistingPivot($userId, ['status' => ClubMemberStatus::Past->value, 'invitation_token' => null]);

            return null;
        });

        return $error ? back()->with('error', $error) : back()->with('success', "{$user->name} removed from the board.");
    }

    private function boardMember(Club $club, int $userId): User
    {
        return $club->users()->wherePivotIn('status', [ClubMemberStatus::Active->value, ClubMemberStatus::Pending->value])->where('users.id', $userId)->firstOrFail();
    }

    private function lockOwners(Club $club): void
    {
        DB::table('club_user')->where('club_id', $club->id)->where('role', ClubRole::Owner->value)->where('status', ClubMemberStatus::Active->value)->lockForUpdate()->get();
    }

    private function isLastOwner(Club $club, int $userId): bool
    {
        return ! $club->users()->wherePivot('status', ClubMemberStatus::Active->value)->wherePivot('role', ClubRole::Owner->value)->where('users.id', '!=', $userId)->exists();
    }
}
