<?php

namespace Tests\Feature\Halls;

use App\Enums\Visibility;
use App\Models\Club;
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

        $this->actingAs($user)->get('/members/'.$hall->slug.'/news')->assertNotFound();
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
}
