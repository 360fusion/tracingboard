<?php

namespace Tests\Feature\Halls;

use App\Domains\ClubAccounting\Models\Member;
use App\Mail\MemberInvitationMail;
use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Halls\HallClubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HallBoardTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create();
        $this->club = app(HallClubs::class)->createFor(MasonicHall::factory()->create(), $this->owner);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $this->club->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return "/{$this->club->slug}/admin/board{$suffix}";
    }

    public function test_owner_invites_a_treasurer_who_gets_the_treasurer_role(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['name' => 'Tom Reasurer', 'email' => 'Tom@example.com', 'office' => 'Treasurer', 'role' => 'treasurer'])
            ->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        Mail::assertQueued(MemberInvitationMail::class, fn ($m) => $m->hasTo('tom@example.com'));
        $user = User::where('email', 'tom@example.com')->firstOrFail();
        $pivot = $this->club->users()->where('users.id', $user->id)->first()->pivot;
        $this->assertSame('treasurer', $pivot->role);
        $this->assertSame('Treasurer', $pivot->committee_role);
        $this->assertSame(1, Member::where('club_id', $this->club->id)->count());
    }

    public function test_admin_cannot_grant_owner(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post($this->url(), ['name' => 'A B', 'email' => 'ab@example.com', 'office' => 'Chairman', 'role' => 'owner'])->assertForbidden();
    }

    public function test_secretary_cannot_reach_the_board_screen(): void
    {
        $this->actingAs($this->staff('secretary'))->get($this->url())->assertForbidden();
    }

    public function test_owner_sees_the_board_and_changes_an_office(): void
    {
        $sec = $this->staff('secretary');

        $this->actingAs($this->owner)->get($this->url())->assertOk();
        $this->put($this->url("/{$sec->id}"), ['office' => 'Chairman', 'role' => 'admin'])->assertSessionHasNoErrors();

        $pivot = $this->club->users()->where('users.id', $sec->id)->first()->pivot;
        $this->assertSame(['admin', 'Chairman'], [$pivot->role, $pivot->committee_role]);
    }

    public function test_removing_the_last_owner_is_refused(): void
    {
        $this->actingAs($this->owner)->delete($this->url("/{$this->owner->id}"))->assertSessionHas('error');
        $this->put($this->url("/{$this->owner->id}"), ['office' => 'Chairman', 'role' => 'admin'])->assertForbidden();

        $this->assertSame('active', $this->club->users()->where('users.id', $this->owner->id)->first()->pivot->status);
    }

    public function test_removing_someone_ends_their_access(): void
    {
        $sec = $this->staff('secretary');

        $this->actingAs($this->owner)->delete($this->url("/{$sec->id}"))->assertSessionHas('success');

        $this->actingAs($sec)->get("/{$this->club->slug}/admin/hall")->assertForbidden();
    }

    public function test_treasurer_can_open_accounting_but_not_hall_details(): void
    {
        $treasurer = $this->staff('treasurer');

        $this->actingAs($treasurer)->get("/{$this->club->slug}/admin/hall")->assertForbidden();
        $this->assertNotSame(403, $this->actingAs($treasurer)->get("/{$this->club->slug}/admin/accounting")->getStatusCode());
    }
}
