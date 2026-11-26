<?php

namespace App\Http\Controllers;

use App\Domains\ClubAccounting\Models\Member;
use App\Domains\ClubAccounting\Services\MemberInvitationException;
use App\Domains\ClubAccounting\Services\MemberInvitationService;
use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\Lodge;
use App\Models\MasonicHall;
use App\Support\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting up accounts from the superadmin home (finding the hall or lodge listing to set one up for) and the people
 * who run each account: who has been invited, who has signed in, with resend and cancel.
 */
class SuperAdminAccountController extends Controller
{
    /** The roles that run an account, as opposed to its members and visitors. */
    private const STAFF_ROLES = [ClubRole::Owner, ClubRole::Admin, ClubRole::Treasurer, ClubRole::Coach, ClubRole::Secretary, ClubRole::Examiner];

    /**
     * Listings of one kind ("hall", or a club type id) matching the search, for the set-up pop-up.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|string|max:20', 'q' => 'nullable|string|max:100']);
        $like = '%'.trim((string) ($data['q'] ?? '')).'%';

        if ($data['type'] === 'hall') {
            $results = MasonicHall::with('club:id,slug')->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('town', $like))
                ->orderBy('name')->limit(20)->get()
                ->map(fn (MasonicHall $hall) => [
                    'id' => $hall->id,
                    'label' => $hall->name.($hall->town ? ', '.$hall->town : ''),
                    'has_account' => $hall->club_id !== null,
                ]);

            return response()->json($results);
        }

        $q = trim((string) ($data['q'] ?? ''));
        $results = Lodge::with(['clubType:id,code', 'province:id,name'])->where('club_type_id', (int) $data['type'])
            ->where(fn ($query) => $query->whereLike('name', $like)->orWhere('number', $q))
            ->orderBy('name')->limit(20)->get()
            ->map(fn (Lodge $lodge) => [
                'id' => $lodge->id,
                'label' => $lodge->displayName().($lodge->number ? ' No. '.$lodge->number : '').($lodge->province ? ' · '.$lodge->province->name : ''),
                'has_account' => $lodge->club_id !== null,
            ]);

        return response()->json($results);
    }

    public function invitations(Request $request): Response
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:invited,active']);
        $search = trim((string) ($filters['q'] ?? ''));

        $people = DB::table('club_user')
            ->join('users', 'users.id', '=', 'club_user.user_id')
            ->join('clubs', 'clubs.id', '=', 'club_user.club_id')
            ->leftJoin('users as inviters', 'inviters.id', '=', 'club_user.invited_by')
            ->whereIn('club_user.role', array_map(fn (ClubRole $role) => $role->value, self::STAFF_ROLES))
            ->whereIn('club_user.status', [ClubMemberStatus::Active->value, ClubMemberStatus::Pending->value])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('users.name', "%{$search}%")->orWhereLike('users.email', "%{$search}%")->orWhereLike('clubs.name', "%{$search}%")))
            ->when(($filters['status'] ?? null) === 'invited', fn ($q) => $q->whereNotNull('club_user.invitation_token')->whereNull('club_user.invitation_accepted_at'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where(fn ($q) => $q->whereNull('club_user.invitation_token')->orWhereNotNull('club_user.invitation_accepted_at')))
            ->orderByDesc('club_user.invited_at')->orderByDesc('club_user.id')
            ->select([
                'club_user.club_id', 'club_user.user_id', 'club_user.role', 'club_user.committee_role', 'club_user.status',
                'club_user.invitation_token', 'club_user.invitation_accepted_at', 'club_user.invited_at',
                'users.name', 'users.email', 'clubs.name as club_name', 'clubs.slug as club_slug', 'inviters.name as inviter_name',
            ])
            ->paginate(50)->withQueryString();

        $expiryDays = Club::whereIn('id', collect($people->items())->pluck('club_id')->unique())->get()->mapWithKeys(fn (Club $club) => [$club->id => $club->inviteExpirationDays()]);

        return Inertia::render('SuperAdmin/Invitations/Index', [
            'people' => $people->through(fn ($row) => [
                'club_id' => $row->club_id,
                'user_id' => $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
                'club' => $row->club_name,
                'club_slug' => $row->club_slug,
                'role' => $row->committee_role ? ucfirst($row->committee_role) : self::roleLabel($row->role),
                'status' => self::status($row, $expiryDays[$row->club_id] ?? 14),
                'invited_at' => $row->invited_at ? Carbon::parse($row->invited_at)->format('j M Y') : null,
                'invited_by' => $row->inviter_name,
            ]),
            'filters' => ['q' => $search, 'status' => $filters['status'] ?? ''],
            'setUpTypes' => self::setUpTypes(),
        ]);
    }

    /**
     * One account: its details and everyone on its roster or with a sign-in, with their sign-in status.
     */
    public function show(int $club): Response
    {
        $club = Club::with(['clubType', 'province', 'masonicHall', 'lodge'])->findOrFail($club);
        $expiryDays = $club->inviteExpirationDays();
        $users = $club->users()->get()->keyBy('id');

        $members = Member::where('club_id', $club->id)->orderBy('last_name')->orderBy('first_name')->get();
        $people = $members->map(fn (Member $member) => [
            'name' => $member->formatted_rank_name,
            'email' => $member->email ?: $users->get($member->user_id)?->email,
            'office' => $member->current_office->label(),
            'membership' => $member->membership_status->label(),
            'role' => ($user = $users->get($member->user_id)) ? self::roleLabel($user->pivot->role) : null,
            'sign_in' => $user ? self::signInStatus($user->pivot, $expiryDays) : 'none',
        ]);

        // People who can sign in but are not on the roster (a superadmin added as owner, say).
        $people = $people->concat($users->except($members->pluck('user_id')->filter()->all())->values()->map(fn ($user) => [
            'name' => $user->name,
            'email' => $user->email,
            'office' => $user->pivot->committee_role,
            'membership' => null,
            'role' => self::roleLabel($user->pivot->role),
            'sign_in' => self::signInStatus($user->pivot, $expiryDays),
        ]));

        return Inertia::render('SuperAdmin/Accounts/Show', [
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'slug' => $club->slug,
                'number' => $club->lodge_number,
                'type' => $club->clubType?->name,
                'province' => $club->province?->name,
                'hall' => $club->masonicHall?->name,
                'town' => $club->town_city,
                'email' => $club->email,
                'status' => $club->status,
                'plan' => PlanCatalog::forClub($club)['name'],
                'created' => $club->created_at?->format('j M Y'),
                'listing_slug' => $club->lodge?->slug,
            ],
            'people' => $people->values(),
        ]);
    }

    /**
     * A directory listing: its account's page when it has one, otherwise the listing's own details with no people.
     */
    public function lodge(int $lodge): Response|RedirectResponse
    {
        $lodge = Lodge::with(['clubType', 'province', 'masonicHall'])->findOrFail($lodge);

        if ($lodge->club_id) {
            return redirect()->route('superadmin.accounts.show', $lodge->club_id);
        }

        return Inertia::render('SuperAdmin/Accounts/Show', [
            'club' => [
                'name' => $lodge->displayName(),
                'number' => $lodge->number,
                'type' => $lodge->clubType?->name,
                'province' => $lodge->province?->name,
                'hall' => $lodge->masonicHall?->name,
                'town' => $lodge->masonicHall?->town,
                'meets' => $lodge->meets_text,
                'website' => $lodge->website_url,
                'status' => $lodge->status,
                'listing_slug' => $lodge->slug,
            ],
            'lodgeId' => $lodge->id,
            'people' => [],
        ]);
    }

    public function resend(Request $request, int $club, int $user, MemberInvitationService $invitations): RedirectResponse
    {
        $club = Club::findOrFail($club);
        $person = $club->users()->whereKey($user)->firstOrFail();
        $member = Member::where('club_id', $club->id)->where('user_id', $person->id)->first();

        try {
            if (! $member) {
                throw new MemberInvitationException('There is no outstanding invitation to resend.');
            }

            $invitations->resend($member, $club, $request->user());
        } catch (MemberInvitationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "A new invitation was sent to {$person->email}.");
    }

    public function cancel(int $club, int $user, MemberInvitationService $invitations): RedirectResponse
    {
        $club = Club::findOrFail($club);
        $person = $club->users()->whereKey($user)->firstOrFail();

        if ($person->pivot->invitation_token === null || $person->pivot->invitation_accepted_at !== null) {
            return back()->with('error', "{$person->name} has already signed in, so there is no invitation to cancel.");
        }

        $invitations->revoke($club, $person);

        return back()->with('success', "The invitation to {$person->email} was cancelled.");
    }

    /**
     * "invited", "expired" or "active" for a club_user row.
     */
    public static function status(object $pivot, int $expiryDays): string
    {
        if ($pivot->invitation_token === null || $pivot->invitation_accepted_at !== null) {
            return 'active';
        }

        return $pivot->invited_at && Carbon::parse($pivot->invited_at)->addDays($expiryDays)->isPast() ? 'expired' : 'invited';
    }

    /**
     * Like status(), but "left" for someone whose access has ended and "waiting" for a request not yet approved.
     */
    private static function signInStatus(object $pivot, int $expiryDays): string
    {
        return match ($pivot->status) {
            ClubMemberStatus::Past->value => 'left',
            ClubMemberStatus::Pending->value => $pivot->invitation_token === null ? 'waiting' : self::status($pivot, $expiryDays),
            default => self::status($pivot, $expiryDays),
        };
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            ClubRole::Coach->value => 'Secretary',
            ClubRole::Examiner->value => 'Examiner',
            default => ucfirst($role),
        };
    }

    /**
     * The kinds of account the set-up pop-up offers: halls, then each order that has directory listings.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function setUpTypes(): array
    {
        $orders = ClubType::whereIn('id', Lodge::query()->select('club_type_id')->distinct())->orderBy('name')->get(['id', 'name'])
            ->map(fn (ClubType $type) => ['value' => (string) $type->id, 'label' => $type->name]);

        return [['value' => 'hall', 'label' => 'Masonic hall'], ...$orders];
    }
}
