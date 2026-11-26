<?php

namespace App\Mail;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;

/**
 * Invites a member to set up an online account (or, with $reminder, nudges one who has not yet). The wording is the
 * `account_invitation` / `account_invitation_reminder` template, which a lodge can override for itself.
 */
class MemberInvitationMail extends EventTemplatedMail
{
    public function __construct(
        public Club $club,
        public User $user,
        public string $invitationToken,
        public string $acceptUrl,
        public ?User $inviter = null,
        public bool $reminder = false,
    ) {
        $this->onQueue('transactional');
    }

    protected function club(): Club
    {
        return $this->club;
    }

    protected function templateKey(): string
    {
        return $this->reminder ? 'account_invitation_reminder' : 'account_invitation';
    }

    protected function values(): array
    {
        $pivot = $this->club->users()->whereKey($this->user->id)->first()?->pivot;
        $role = $pivot->role ?? ClubRole::Member->value;

        return [
            'text' => [
                'member_name' => $this->user->name,
                'club_name' => $this->club->name,
                'invite_url' => $this->acceptUrl,
                'expiry_days' => $this->club->inviteExpirationDays(),
                'inviter_name' => $this->inviter->name ?? $this->club->name,
                'role_name' => $pivot?->committee_role ?: self::roleName($role),
                'email' => $this->user->email,
                'sign_in_url' => route('login'),
            ],
            'html' => [
                'what_you_can_do' => '<ul>'.collect($this->whatYouCanDo($role))->map(fn (string $line) => '<li>'.e($line).'</li>')->implode('').'</ul>',
            ],
        ];
    }

    private static function roleName(string $role): string
    {
        return match ($role) {
            ClubRole::Owner->value => 'an owner',
            ClubRole::Admin->value => 'an administrator',
            ClubRole::Treasurer->value => 'Treasurer',
            ClubRole::Coach->value, ClubRole::Secretary->value => 'Secretary',
            ClubRole::Examiner->value => 'examiner or auditor',
            ClubRole::Visitor->value => 'a visitor',
            default => 'a member',
        };
    }

    /**
     * What the account lets this person do, in the words of the kind of club and their role.
     *
     * @return array<int, string>
     */
    private function whatYouCanDo(string $role): array
    {
        $runsIt = in_array($role, [ClubRole::Owner->value, ClubRole::Admin->value], true);

        if ($this->club->isHall()) {
            return array_filter([
                "Keep the hall's details and its page in the lodge directory up to date",
                'Put on events for the lodges that meet at the hall, or for the public, and take bookings online',
                "Run the hall's website and send newsletters to the lodges' members",
                $runsIt || $role === ClubRole::Treasurer->value ? "Keep the hall's accounts" : null,
                $runsIt ? 'Invite the rest of the board and choose what each person can do' : null,
            ]);
        }

        if ($this->club->isGroup()) {
            return array_filter([
                "Keep {$this->club->name}'s pages and events up to date",
                $runsIt ? 'Invite others to help and choose what each person can do' : null,
            ]);
        }

        return array_filter([
            'See upcoming meetings and reply to summonses',
            'Book dinners and events, and pay online where the lodge allows it',
            'Keep your own contact details up to date',
            match ($role) {
                ClubRole::Owner->value, ClubRole::Admin->value => 'Help run the lodge: members, meetings, events and the website',
                ClubRole::Treasurer->value => "Keep the lodge's accounts, dues and bank records",
                ClubRole::Coach->value, ClubRole::Secretary->value => 'Send summonses and keep meetings and members\' records',
                ClubRole::Examiner->value => "Look over the lodge's accounts",
                default => null,
            },
        ]);
    }

    protected function fallbackSubject(): string
    {
        return $this->reminder
            ? "Reminder: your {$this->club->name} invitation"
            : "You're invited to join {$this->club->name}";
    }

    protected function fallbackBody(): string
    {
        $values = $this->values()['text'];

        return '<p>Dear '.e($values['member_name']).',</p><p>'.e($values['club_name']).' has invited you to set up your online member account.</p><p><a href="'.e($values['invite_url']).'">Accept invitation and set up your account</a></p><p>This invitation link will expire in '.e((string) $values['expiry_days']).' days.</p>';
    }
}
