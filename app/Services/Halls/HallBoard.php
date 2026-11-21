<?php

namespace App\Services\Halls;

use App\Domains\ClubAccounting\Enums\MembershipStatus;
use App\Domains\ClubAccounting\Models\Member;
use App\Domains\ClubAccounting\Services\MemberInvitationException;
use App\Domains\ClubAccounting\Services\MemberInvitationService;
use App\Enums\ClubMemberStatus;
use App\Models\Club;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class HallBoard
{
    /** The role pre-selected for each office; any other office defaults to secretary. The role can still be changed. */
    public const OFFICE_ROLES = ['Chairman' => 'admin', 'Secretary' => 'secretary', 'Treasurer' => 'treasurer'];

    public function __construct(private MemberInvitationService $invitations) {}

    public static function defaultRole(string $office): string
    {
        return self::OFFICE_ROLES[$office] ?? 'secretary';
    }

    /**
     * Invite someone onto the hall's board. A carrier Member row on the hall club holds the invitation; the members
     * screens are hidden for halls, so it never shows as a roster.
     *
     * @return array{emailed: bool, url: string}|null what MemberInvitationService returns
     *
     * @throws DomainException when the person is already on the board
     * @throws MemberInvitationException
     */
    public function invite(Club $club, string $name, string $email, string $office, string $role, ?User $actor): ?array
    {
        $email = strtolower(trim($email));

        if ($club->users()->whereRaw('lower(users.email) = ?', [$email])->wherePivotIn('status', [ClubMemberStatus::Active->value, ClubMemberStatus::Pending->value])->exists()) {
            throw new DomainException('That person is already on the board.');
        }

        return DB::transaction(function () use ($club, $name, $email, $office, $role, $actor) {
            $parts = explode(' ', trim($name), 2);

            $userId = User::whereRaw('lower(email) = ?', [$email])->value('id');
            // A removed person's carrier keeps its user_id, so reuse it rather than make a second one.
            $member = Member::where('club_id', $club->id)
                ->where(fn ($q) => $q->whereRaw('lower(email) = ?', [$email])->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))
                ->first()
                ?? Member::create([
                    'club_id' => $club->id,
                    'email' => $email,
                    'first_name' => $parts[0],
                    'last_name' => $parts[1] ?? '',
                    'masonic_rank' => 'Bro',
                    'membership_status' => MembershipStatus::Active,
                ]);

            $sent = $this->invitations->invite($member, $club, $actor, $role);
            $club->users()->updateExistingPivot($member->fresh()->user_id, ['role' => $role, 'committee_role' => $office]);

            return $sent;
        });
    }
}
