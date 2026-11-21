<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Halls\HallClubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HallAdminSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lodge_only_sections_are_404_for_a_hall(): void
    {
        $hall = Club::factory()->hall()->create();
        $owner = User::factory()->create();
        $hall->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        foreach (['meetings', 'users', 'members', 'charity'] as $section) {
            $this->actingAs($owner)->get("/{$hall->slug}/admin/{$section}")->assertNotFound();
        }
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get("/{$hall->slug}/admin/meetings")->assertNotFound();
        $this->actingAs($owner)->get("/{$hall->slug}/admin/events")->assertOk();
    }

    public function test_a_lodge_keeps_its_meetings(): void
    {
        $lodge = Club::factory()->create();
        $owner = User::factory()->create();
        $lodge->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)->get("/{$lodge->slug}/admin/meetings")->assertOk();
    }

    public function test_board_only_user_lands_on_the_hall_admin_after_sign_in(): void
    {
        $hall = Club::factory()->hall()->create();
        $user = User::factory()->create();
        $hall->users()->attach($user->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->get('/members/dashboard')->assertRedirect(route('admin.hall.edit', ['clubSlug' => $hall->slug]));
    }

    public function test_a_board_member_without_hall_details_lands_on_the_dashboard(): void
    {
        $hall = Club::factory()->hall()->create();
        $user = User::factory()->create();
        $hall->users()->attach($user->id, ['role' => 'treasurer', 'status' => 'active']);

        $this->assertSame(route('admin.analytics', ['slug' => $hall->slug]), HallClubs::landingUrl($hall, $user));
        $this->actingAs($user)->get('/members/dashboard')->assertRedirect(route('admin.analytics', ['slug' => $hall->slug]));
    }

    public function test_accepting_a_hall_board_invitation_lands_on_the_hall_admin(): void
    {
        $hall = app(HallClubs::class)->createFor(MasonicHall::factory()->create());
        $user = User::factory()->unverified()->create();
        $hall->users()->attach($user->id, ['role' => 'coach', 'status' => 'pending', 'invitation_token' => hash('sha256', 'hall-token'), 'invited_at' => now()]);

        $this->post(route('invitation.submit', ['slug' => $hall->slug, 'token' => 'hall-token']), ['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])
            ->assertRedirect(route('admin.hall.edit', ['clubSlug' => $hall->slug]));
        $this->get(route('admin.hall.edit', ['clubSlug' => $hall->slug]))->assertOk();
    }

    public function test_a_pending_hall_board_row_is_not_a_pending_club(): void
    {
        $hall = Club::factory()->hall()->create();
        $lodge = Club::factory()->create();
        $user = User::factory()->create();
        $lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        $hall->users()->attach($user->id, ['role' => 'admin', 'status' => 'pending']);

        $this->actingAs($user)->get('/members/dashboard')->assertInertia(fn ($page) => $page->has('pendingClubs', 0));
    }

    public function test_the_hall_dashboard_has_hall_first_steps_and_the_hall_menu_for_a_superadmin(): void
    {
        $hall = app(HallClubs::class)->createFor(MasonicHall::factory()->create(['telephone' => null, 'email' => null]), User::factory()->create());

        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get(route('admin.analytics', ['slug' => $hall->slug]))
            ->assertInertia(fn ($page) => $page->where('isHall', true)->where('clubIsHall', true)
                ->where('gettingStarted', ['details' => false, 'board' => false, 'events' => false]));

        $lodge = Club::factory()->create();
        $this->get(route('admin.analytics', ['slug' => $lodge->slug]))
            ->assertInertia(fn ($page) => $page->where('isHall', false)->where('clubIsHall', false)->has('gettingStarted.meetings'));
    }
}
