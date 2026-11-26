<?php

namespace Tests\Feature;

use App\Domains\ClubAccounting\Models\Member;
use App\Mail\MemberInvitationMail;
use App\Models\Club;
use App\Models\Lodge;
use App\Models\MasonicHall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SuperAdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
    }

    public function test_search_finds_listings_of_the_chosen_kind(): void
    {
        $lodge = Lodge::factory()->create(['name' => 'Harmony Lodge', 'number' => '1234']);
        Lodge::factory()->create(['name' => 'Discord Lodge']);
        MasonicHall::factory()->create(['name' => 'Harmony Hall']);

        $this->actingAs($this->superAdmin)->getJson(route('superadmin.accounts.search', ['type' => $lodge->club_type_id, 'q' => 'harmony']))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $lodge->id)->assertJsonPath('0.has_account', false);
        $this->actingAs($this->superAdmin)->getJson(route('superadmin.accounts.search', ['type' => $lodge->club_type_id, 'q' => '1234']))
            ->assertJsonCount(1);
        $this->actingAs($this->superAdmin)->getJson(route('superadmin.accounts.search', ['type' => 'hall', 'q' => 'harmony']))
            ->assertJsonCount(1)->assertJsonPath('0.label', 'Harmony Hall'.(MasonicHall::first()->town ? ', '.MasonicHall::first()->town : ''));
    }

    public function test_invited_and_active_people_are_listed_with_their_status(): void
    {
        $lodge = Lodge::factory()->create();
        $this->actingAs($this->superAdmin)->post(route('superadmin.lodges.account', $lodge->id), ['name' => 'Ivy Invited', 'email' => 'ivy@example.com']);
        $club = Club::findOrFail($lodge->refresh()->club_id);
        $club->users()->attach(User::factory()->create(['name' => 'Alan Active'])->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($this->superAdmin)->get(route('superadmin.invitations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SuperAdmin/Invitations/Index')->has('setUpTypes')
                ->has('people.data', 2)
                ->where('people.data', fn ($rows) => collect($rows)->pluck('status', 'name')->all() === ['Ivy Invited' => 'invited', 'Alan Active' => 'active']));

        $this->actingAs($this->superAdmin)->get(route('superadmin.invitations.index', ['status' => 'invited']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('people.data', 1));

        $this->actingAs($this->superAdmin)->get(route('superadmin.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clubs.0.people.0.status', fn ($status) => in_array($status, ['invited', 'active'], true))->has('setUpTypes'));

        $this->travel(15)->days();
        $this->actingAs($this->superAdmin)->get(route('superadmin.invitations.index', ['status' => 'invited']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('people.data.0.status', 'expired'));
    }

    public function test_an_invitation_can_be_resent_and_cancelled(): void
    {
        $lodge = Lodge::factory()->create();
        $this->actingAs($this->superAdmin)->post(route('superadmin.lodges.account', $lodge->id), ['name' => 'Ivy Invited', 'email' => 'ivy@example.com']);
        $club = Club::findOrFail($lodge->refresh()->club_id);
        $ivy = User::where('email', 'ivy@example.com')->firstOrFail();

        $this->travel(1)->hour();
        $this->actingAs($this->superAdmin)->post(route('superadmin.invitations.resend', [$club->id, $ivy->id]))->assertSessionHas('success');
        Mail::assertQueued(MemberInvitationMail::class, 2);

        $this->actingAs($this->superAdmin)->delete(route('superadmin.invitations.cancel', [$club->id, $ivy->id]))->assertSessionHas('success');
        $this->assertFalse($club->users()->whereKey($ivy->id)->exists());
    }

    public function test_someone_already_signed_in_has_no_invitation_to_cancel(): void
    {
        $club = Club::factory()->create();
        $alan = User::factory()->create();
        $club->users()->attach($alan->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($this->superAdmin)->delete(route('superadmin.invitations.cancel', [$club->id, $alan->id]))->assertSessionHas('error');
        $this->assertTrue($club->users()->whereKey($alan->id)->exists());
    }

    public function test_account_page_lists_roster_and_sign_in_people(): void
    {
        $lodge = Lodge::factory()->create();
        $this->actingAs($this->superAdmin)->post(route('superadmin.lodges.account', $lodge->id), ['name' => 'Ivy Invited', 'email' => 'ivy@example.com']);
        $club = Club::findOrFail($lodge->refresh()->club_id);
        Member::create(['club_id' => $club->id, 'first_name' => 'Rex', 'last_name' => 'Roster', 'masonic_rank' => 'WBro', 'membership_status' => 'active']);
        $club->users()->attach(User::factory()->create(['name' => 'Odd Owner'])->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($this->superAdmin)->get(route('superadmin.accounts.show', $club->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SuperAdmin/Accounts/Show')
                ->where('club.listing_slug', $lodge->slug)
                ->has('people', 3)
                ->where('people', fn ($people) => collect($people)->pluck('sign_in', 'email')->only(['ivy@example.com'])->all() === ['ivy@example.com' => 'invited']
                    && collect($people)->firstWhere('name', 'Odd Owner')['sign_in'] === 'active'
                    && collect($people)->first(fn ($p) => str_contains($p['name'], 'Roster'))['sign_in'] === 'none'));

        $this->actingAs(User::factory()->create())->get(route('superadmin.accounts.show', $club->id))->assertRedirect();
    }

    public function test_a_listing_page_shows_the_listing_until_it_has_an_account(): void
    {
        $lodge = Lodge::factory()->create();

        $this->actingAs($this->superAdmin)->get(route('superadmin.lodges.show', $lodge->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SuperAdmin/Accounts/Show')->where('lodgeId', $lodge->id)->where('club.listing_slug', $lodge->slug)->has('people', 0));

        $this->actingAs($this->superAdmin)->post(route('superadmin.lodges.account', $lodge->id), ['name' => 'Ivy Invited', 'email' => 'ivy@example.com']);
        $this->actingAs($this->superAdmin)->get(route('superadmin.lodges.show', $lodge->id))->assertRedirect(route('superadmin.accounts.show', $lodge->refresh()->club_id));
    }

    public function test_a_member_is_invited_from_the_account_page(): void
    {
        $club = Club::factory()->create();

        $this->actingAs($this->superAdmin)->post(route('superadmin.accounts.invite', $club->id), ['name' => 'Mo Member', 'email' => 'mo@example.com', 'role' => 'owner'])
            ->assertSessionHas('success');

        $this->assertSame('member', $club->users()->where('email', 'mo@example.com')->firstOrFail()->pivot->role);
        $this->assertTrue(Member::where('club_id', $club->id)->where('email', 'mo@example.com')->exists());
        Mail::assertQueued(MemberInvitationMail::class, fn ($mail) => $mail->hasTo('mo@example.com'));

        $this->actingAs($this->superAdmin)->post(route('superadmin.accounts.invite', $club->id), ['name' => 'Mo Member', 'email' => 'mo@example.com'])->assertSessionHas('error');
        $this->actingAs(User::factory()->create())->post(route('superadmin.accounts.invite', $club->id), ['name' => 'X', 'email' => 'x@example.com']);
        $this->assertFalse($club->users()->where('email', 'x@example.com')->exists());
    }

    public function test_only_superadmins_reach_these_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('superadmin.invitations.index'))->assertRedirect();
        $this->actingAs($user)->getJson(route('superadmin.accounts.search', ['type' => 'hall']))->assertStatus(403);
    }
}
