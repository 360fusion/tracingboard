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

    public function test_a_removed_person_can_be_invited_again_with_one_pivot_and_one_carrier(): void
    {
        $invite = ['name' => 'Tom Reasurer', 'email' => 'tom@example.com', 'office' => 'Treasurer', 'role' => 'treasurer'];
        $this->actingAs($this->owner)->post($this->url(), $invite);
        $user = User::where('email', 'tom@example.com')->firstOrFail();
        $this->delete($this->url("/{$user->id}"))->assertSessionHas('success');

        $this->post($this->url(), $invite)->assertSessionMissing('error');

        $this->assertSame(1, $this->club->users()->where('users.id', $user->id)->count());
        $pivot = $this->club->users()->where('users.id', $user->id)->first()->pivot;
        $this->assertContains($pivot->status, ['pending', 'active']);
        $this->assertSame('treasurer', $pivot->role);
        $this->assertSame(1, Member::where('club_id', $this->club->id)->where('email', 'tom@example.com')->count());
    }

    public function test_removal_clears_the_invitation_token_and_a_past_person_is_not_found(): void
    {
        $sec = $this->staff('secretary');
        $this->club->users()->updateExistingPivot($sec->id, ['invitation_token' => 'abc']);

        $this->actingAs($this->owner)->delete($this->url("/{$sec->id}"));

        $this->assertNull($this->club->users()->where('users.id', $sec->id)->first()->pivot->invitation_token);
        $this->put($this->url("/{$sec->id}"), ['office' => 'X', 'role' => 'admin'])->assertNotFound();
        $this->delete($this->url("/{$sec->id}"))->assertNotFound();
    }

    public function test_inviting_a_lodge_member_leaves_their_account_and_lodge_pivot_alone(): void
    {
        $lodge = Club::factory()->create();
        $existing = User::factory()->create(['name' => 'Original Name', 'email' => 'bro@example.com']);
        $lodge->users()->attach($existing->id, ['role' => 'member', 'status' => 'active']);
        $password = $existing->password;

        $this->actingAs($this->owner)->post($this->url(), ['name' => 'Other Name', 'email' => 'bro@example.com', 'office' => 'Secretary', 'role' => 'secretary'])->assertSessionMissing('error');

        $existing->refresh();
        $this->assertSame(['Original Name', $password], [$existing->name, $existing->password]);
        $this->assertSame('member', $lodge->users()->where('users.id', $existing->id)->first()->pivot->role);
        $this->assertSame('secretary', $this->club->users()->where('users.id', $existing->id)->first()->pivot->role);
    }

    public function test_a_duplicate_invite_returns_the_error(): void
    {
        $sec = $this->staff('secretary');

        $this->actingAs($this->owner)->post($this->url(), ['name' => 'X Y', 'email' => $sec->email, 'office' => 'Secretary', 'role' => 'secretary'])
            ->assertSessionHas('error', 'That person is already on the board.');
    }

    public function test_admin_cannot_remove_an_owner(): void
    {
        $this->actingAs($this->staff('admin'))->delete($this->url("/{$this->owner->id}"))->assertForbidden();
    }
}
