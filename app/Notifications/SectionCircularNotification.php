<?php

namespace App\Notifications;

use App\Mail\LodgeClaimMail;
use App\Models\Club;
use App\Models\SectionCircular;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To a chapter's Secretary and pass-on staff: their province section sent a circular, ready to pass on from their
 * pass-on screen. In the bell and by email.
 */
class SectionCircularNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SectionCircular $circular, public Club $section, public Club $chapter)
    {
        $this->onQueue('transactional');
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
            'category' => ClubNotification::NOTICE,
            'title' => 'Circular from '.$this->section->name.': '.$this->circular->title,
            'body' => 'Ready for you to pass on to '.$this->chapter->name.'.',
            'important' => false,
            'club' => ['id' => $this->chapter->id, 'name' => $this->chapter->name, 'slug' => $this->chapter->slug],
            'url' => route('member.pass_on', ['slug' => $this->chapter->slug], false),
        ];
    }

    public function toMail(object $notifiable): LodgeClaimMail
    {
        return (new LodgeClaimMail(
            'Circular from '.$this->section->name.': '.$this->circular->title,
            $this->section->name.' has sent a circular for '.$this->chapter->name.'. Pass it on to your members from your pass-on screen.',
            'Pass it on',
            route('member.pass_on', ['slug' => $this->chapter->slug]),
        ))->to($notifiable->email);
    }
}
