<?php

namespace App\Support;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The clubs a member's page is allowed to look at: all of their active
 * memberships, or just one of them. Every member-area query gets its clubs from
 * here, so one club's data cannot leak onto another club's page.
 */
final class MemberScope
{
    /** @var Collection<int, Club>|null */
    private ?Collection $hallClubs = null;

    /**
     * @param  Collection<int, Club>  $memberClubs  every club the user is an active member of
     */
    private function __construct(
        public readonly User $user,
        public readonly Collection $memberClubs,
        public readonly ?Club $club,
    ) {}

    /**
     * Scope for a URL such as /members/{club}; a 404 when the user is not an active member.
     */
    public static function for(User $user, ?string $slug = null): self
    {
        $memberClubs = $user->clubs()->notGroups()->with('clubType')->wherePivot('status', ClubMemberStatus::Active->value)->orderBy('clubs.id')->get();

        if ($slug === null) {
            return new self($user, $memberClubs, null);
        }

        $club = $memberClubs->firstWhere('slug', $slug)
            ?? ($user->actsAsSuperAdmin() ? Club::where('slug', $slug)->with('clubType')->first() : null);

        abort_if($club === null, 404);

        return new self($user, $memberClubs, $club);
    }

    /**
     * Scope for the member event pages: the same as for(), except that a hall's events are open to the members of
     * the lodges that meet there. The hall is the scope's club only; it never joins memberClubs.
     */
    public static function forEvents(User $user, string $slug): self
    {
        $club = Club::where('slug', $slug)->with('clubType')->first();

        if (! $club?->isHall()) {
            return self::for($user, $slug);
        }

        abort_unless($user->hallAudienceClubIds()->contains($club->id), 404);

        return new self($user, self::for($user)->memberClubs, $club);
    }

    /**
     * Scope for a member page. A club in the URL (/members/{club}/...) is strict and
     * a 404 for anyone who is not an active member; the optional ?club= filter on the
     * all-clubs pages is lenient, and an unknown club is ignored.
     */
    public static function fromRequest(Request $request, ?string $slug = null): self
    {
        /** @var User $user */
        $user = $request->user();

        if ($slug !== null) {
            return self::for($user, $slug);
        }

        $filter = $request->query('club');
        $scope = self::for($user);

        if (! is_string($filter) || ! $scope->memberClubs->contains('slug', $filter)) {
            return $scope;
        }

        return self::for($user, $filter);
    }

    public function isSingle(): bool
    {
        return $this->club !== null;
    }

    /**
     * @return Collection<int, Club>
     */
    public function clubs(): Collection
    {
        return $this->club ? collect([$this->club]) : $this->memberClubs;
    }

    /**
     * @return Collection<int, int>
     */
    public function clubIds(): Collection
    {
        return $this->clubs()->pluck('id');
    }

    /**
     * Hall clubs whose "club" events reach this scope's clubs (events only: a hall has no meetings or posts for members).
     *
     * @return Collection<int, Club>
     */
    public function hallClubs(): Collection
    {
        return $this->hallClubs ??= MasonicHall::hallClubsFor($this->clubs());
    }

    /**
     * @return Collection<int, int>
     */
    public function hallClubIds(): Collection
    {
        return $this->hallClubs()->pluck('id');
    }

    public function clubFor(int $clubId): ?Club
    {
        return $this->clubs()->firstWhere('id', $clubId) ?? $this->hallClubs()->firstWhere('id', $clubId);
    }

    public function roleIn(int $clubId): string
    {
        return $this->memberClubs->firstWhere('id', $clubId)?->pivot->role ?? 'member';
    }

    public function isStaffIn(int $clubId): bool
    {
        return ! in_array($this->roleIn($clubId), [null, ClubRole::Member->value, ClubRole::Visitor->value], true);
    }
}
