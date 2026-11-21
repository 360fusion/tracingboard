<?php

namespace App\Services\Halls;

use App\Models\Club;
use App\Models\HallClaim;
use App\Models\MasonicHall;
use App\Models\User;
use App\Notifications\HallClaimNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Everything that can happen to a hall claim. Each step checks the claim is in the right state and changes it inside a
 * transaction. Approving goes through HallClubs, which is also what stops a hall having two boards.
 */
class HallClaimService
{
    public function __construct(private readonly HallClubs $halls) {}

    /**
     * @param  array{claimant_role: string, message: string, phone?: ?string}  $data
     */
    public function submit(MasonicHall $hall, User $user, array $data): HallClaim
    {
        if ($hall->club_id) {
            throw ValidationException::withMessages(['hall' => 'This hall already has a board. If someone already runs it, ask them for access.']);
        }

        if (HallClaim::where('masonic_hall_id', $hall->id)->where('user_id', $user->id)->open()->exists()) {
            throw ValidationException::withMessages(['hall' => 'You already have a claim for this hall waiting to be checked.']);
        }

        if (HallClaim::where('user_id', $user->id)->open()->count() >= HallClaim::MAX_OPEN_PER_USER) {
            throw ValidationException::withMessages(['hall' => 'You have '.HallClaim::MAX_OPEN_PER_USER.' claims waiting to be checked. Wait for a decision, or withdraw one, before making another.']);
        }

        $claim = HallClaim::create([...$data, 'masonic_hall_id' => $hall->id, 'user_id' => $user->id, 'status' => HallClaim::PENDING]);

        $this->tellSuperAdmins(HallClaimNotification::submitted($claim->load(['hall', 'user'])));

        return $claim;
    }

    public function requestInfo(HallClaim $claim, User $admin, string $question): void
    {
        $this->guardOpen($claim, [HallClaim::PENDING]);

        $claim->forceFill(['status' => HallClaim::INFO_REQUESTED, 'question' => $question])->save();

        $claim->load(['hall', 'user'])->user->notify(HallClaimNotification::moreInfoNeeded($claim, $question));
    }

    public function respond(HallClaim $claim, User $user, string $reply): void
    {
        abort_unless($claim->user_id === $user->id, 404);
        $this->guardOpen($claim, [HallClaim::INFO_REQUESTED]);

        $claim->forceFill(['status' => HallClaim::PENDING, 'reply' => $reply])->save();

        $this->tellSuperAdmins(HallClaimNotification::replied($claim->load(['hall', 'user'])));
    }

    public function withdraw(HallClaim $claim, User $user): void
    {
        abort_unless($claim->user_id === $user->id, 404);
        $this->guardOpen($claim);

        $this->settle($claim, HallClaim::WITHDRAWN);
    }

    public function reject(HallClaim $claim, User $admin, string $reason): void
    {
        $this->guardOpen($claim);

        $this->settle($claim, HallClaim::REJECTED, $admin, $reason);

        $claim->load(['hall', 'user'])->user->notify(HallClaimNotification::rejected($claim));
    }

    /**
     * Approve by creating the hall's club with the claimant as its owner. If a board appeared in the meantime the claim
     * is left as it was and a validation error says so.
     */
    public function approve(HallClaim $claim, User $admin, ?string $note = null): Club
    {
        $club = DB::transaction(function () use ($claim, $admin, $note) {
            $locked = HallClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['claim' => 'This claim has already been dealt with.']);
            }

            try {
                $club = $this->halls->createFor($locked->hall, $locked->user);
            } catch (DomainException) {
                throw ValidationException::withMessages(['claim' => 'This hall already has a board, so the claim was left as it was. Refuse it or ask the person a question.']);
            }

            $this->settle($locked, HallClaim::APPROVED, $admin, $note);
            $claim->setRawAttributes($locked->getAttributes(), true);

            return $club;
        });

        $claim->load(['hall', 'user'])->user->notify(HallClaimNotification::approved($claim, $club));

        return $club;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function guardOpen(HallClaim $claim, array $allowed = HallClaim::OPEN): void
    {
        if (! in_array($claim->fresh()->status, $allowed, true)) {
            throw ValidationException::withMessages(['claim' => 'This claim has already been dealt with.']);
        }
    }

    /**
     * The decision fields are not mass-assignable on purpose, so nothing but this service sets them.
     */
    private function settle(HallClaim $claim, string $status, ?User $by = null, ?string $note = null): void
    {
        $claim->forceFill(['status' => $status, 'decided_by' => $by?->id, 'decided_at' => now(), 'decision_note' => $note])->save();
    }

    private function tellSuperAdmins(HallClaimNotification $notification): void
    {
        Notification::send(User::where('is_super_admin', true)->get(), $notification);
    }
}
