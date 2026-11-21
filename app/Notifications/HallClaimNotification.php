<?php

namespace App\Notifications;

use App\Mail\LodgeClaimMail;
use App\Models\Club;
use App\Models\HallClaim;
use Illuminate\Notifications\Notification;

/**
 * Something happened to a hall claim: a new claim or a reply for the superadmins, a decision or a question for the
 * person who made it. It lands in the bell and in an email, like LodgeClaimNotification.
 */
class HallClaimNotification extends Notification
{
    /**
     * @param  string  $route  a named route, without a domain
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly string $hallName,
        public readonly string $route,
        public readonly array $params = [],
        public readonly string $actionLabel = 'Open',
        public readonly bool $important = false,
    ) {}

    public static function submitted(HallClaim $claim): self
    {
        return new self(
            ClubNotification::NOTICE,
            'New hall claim: '.$claim->hall->name,
            $claim->user->name.' ('.(HallClaim::ROLES[$claim->claimant_role] ?? $claim->claimant_role).') has asked to run this hall.',
            $claim->hall->name,
            'superadmin.lodge_claims.index',
            [],
            'Review the claim',
            true,
        );
    }

    public static function replied(HallClaim $claim): self
    {
        return new self(
            ClubNotification::NOTICE,
            'Claim updated: '.$claim->hall->name,
            $claim->user->name.' has replied to your question about their claim.',
            $claim->hall->name,
            'superadmin.lodge_claims.index',
            [],
            'Review the claim',
        );
    }

    public static function moreInfoNeeded(HallClaim $claim, string $question): self
    {
        return new self(
            ClubNotification::MEMBERSHIP,
            'More information needed for '.$claim->hall->name,
            $question,
            $claim->hall->name,
            'lodges.hall.claim',
            ['slug' => $claim->hall->slug],
            'Reply to the question',
            true,
        );
    }

    public static function approved(HallClaim $claim, Club $club): self
    {
        return new self(
            ClubNotification::MEMBERSHIP,
            'You now run '.$claim->hall->name,
            'Your claim was approved. You can set up the hall board, its details and its events for '.$club->name.'.',
            $claim->hall->name,
            'member.dashboard',
            ['slug' => $club->slug],
            'Open your hall',
            true,
        );
    }

    public static function rejected(HallClaim $claim): self
    {
        return new self(
            ClubNotification::MEMBERSHIP,
            'Your claim for '.$claim->hall->name.' was not approved',
            (string) $claim->decision_note,
            $claim->hall->name,
            'lodges.hall',
            ['slug' => $claim->hall->slug],
            'See the hall page',
        );
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => mb_strimwidth(strip_tags($this->body), 0, 200, '…'),
            'important' => $this->important,
            'club' => ['id' => null, 'name' => $this->hallName, 'slug' => null],
            'url' => route($this->route, $this->params, false),
        ];
    }

    public function toMail(object $notifiable): LodgeClaimMail
    {
        return (new LodgeClaimMail($this->title, $this->body, $this->actionLabel, route($this->route, $this->params)))
            ->to($notifiable->email);
    }
}
