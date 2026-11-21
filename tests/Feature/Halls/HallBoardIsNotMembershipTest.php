<?php

namespace Tests\Feature\Halls;

use App\Enums\Visibility;
use App\Models\Club;
use App\Models\NewsletterSubscription;
use App\Models\NewsletterType;
use App\Models\Post;
use App\Models\User;
use App\Support\MemberScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HallBoardIsNotMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function boardMemberOf(Club $club, User $user): void
    {
        $club->users()->attach($user->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_hall_board_member_with_no_lodge_has_no_member_clubs(): void
    {
        $hall = Club::factory()->hall()->create();
        $user = User::factory()->create();
        $this->boardMemberOf($hall, $user);

        $this->assertTrue($user->activeClubIds()->isEmpty());
        $this->assertSame([$hall->id], $user->boardHallClubIds()->all());
        $this->assertTrue(MemberScope::for($user)->memberClubs->isEmpty());

        foreach (['', '/news', '/events', '/profile'] as $path) {
            $this->actingAs($user)->get('/members/'.$hall->slug.$path)->assertNotFound();
        }
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get('/members/'.$hall->slug)->assertNotFound();
    }

    public function test_board_member_of_a_hall_who_is_in_a_lodge_keeps_only_the_lodge(): void
    {
        $hall = Club::factory()->hall()->create();
        $lodge = Club::factory()->create();
        $user = User::factory()->create();
        $this->boardMemberOf($hall, $user);
        $lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);

        $this->assertSame([$lodge->id], $user->activeClubIds()->all());
        $this->assertSame([$lodge->id], MemberScope::for($user)->memberClubs->pluck('id')->all());
    }

    public function test_network_content_is_not_shown_to_a_board_only_user(): void
    {
        $hall = Club::factory()->hall()->create();
        $other = Club::factory()->create();
        $user = User::factory()->create();
        $this->boardMemberOf($hall, $user);

        $post = Post::create([
            'club_id' => $other->id,
            'author_id' => User::factory()->create()->id,
            'title' => 'Network news',
            'slug' => 'network-news',
            'content' => '',
            'status' => 'published',
            'visibility' => Visibility::Network,
        ]);

        $this->assertFalse($post->isVisibleTo($user));
    }

    public function test_my_lodges_lists_only_the_lodge_for_a_board_member_who_is_in_one(): void
    {
        $hall = Club::factory()->hall()->create();
        $lodge = Club::factory()->create();
        $user = User::factory()->create();
        $this->boardMemberOf($hall, $user);
        $lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($user)->get('/members/lodges')
            ->assertInertia(fn ($page) => $page->where('own', fn ($own) => collect($own)->pluck('slug')->all() === [$lodge->slug]));
    }

    public function test_a_board_member_is_not_signed_up_to_the_halls_channels(): void
    {
        $hall = Club::factory()->hall()->create();
        $lodge = Club::factory()->create();
        $user = User::factory()->create();
        $this->boardMemberOf($hall, $user);
        $lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        $channel = NewsletterType::create(['club_id' => $hall->id, 'name' => 'Hall news', 'slug' => 'hall-news', 'is_external_subscribable' => true, 'is_mandatory' => false]);

        $this->actingAs($user)->get('/members/subscriptions')
            ->assertInertia(fn ($page) => $page->where('clubMatrix', fn ($matrix) => collect($matrix)->pluck('id')->all() === [$lodge->id]));
        $this->assertSame(0, NewsletterType::where('club_id', $hall->id)->where('id', '!=', $channel->id)->count());

        // The board row does not put them in the hall's audience, so the page will not switch its channel.
        $this->post("/members/subscriptions/{$hall->slug}/{$channel->id}/toggle")->assertNotFound();
        $this->assertNull(NewsletterSubscription::where('user_id', $user->id)->where('newsletter_type_id', $channel->id)->value('status'));
    }

    public function test_registering_through_a_halls_join_link_does_not_ask_to_join_the_hall(): void
    {
        config(['app.public_registration' => true]);
        $hall = Club::factory()->hall()->create();

        $this->post('/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'a-long-secure-password-9', 'password_confirmation' => 'a-long-secure-password-9', 'club' => $hall->slug])
            ->assertRedirect(route('login'));

        $this->assertSame(0, $hall->users()->count());
        $this->assertTrue(User::where('email', 'new@example.com')->exists());
    }
}
