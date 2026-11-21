<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\User;
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

        $this->actingAs($user)->get('/members/dashboard')->assertRedirect("/{$hall->slug}/admin");
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
}
