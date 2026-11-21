<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\Newsletter;
use App\Models\NewsletterType;
use App\Models\User;
use App\Services\Newsletters\NewsletterAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HallSubscriptionsPageTest extends TestCase
{
    use RefreshDatabase;

    private Club $hallClub;

    private NewsletterType $channel;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $lodge = Club::factory()->create(['masonic_hall_id' => $hall->id]);
        $this->channel = NewsletterType::create(['club_id' => $this->hallClub->id, 'name' => 'Hall news', 'slug' => 'hall-news', 'is_external_subscribable' => true, 'is_mandatory' => false]);
        $this->member = User::factory()->create();
        $lodge->users()->attach($this->member->id, ['role' => 'member', 'status' => 'active']);
    }

    private function hallRow(User $user): ?array
    {
        $row = null;
        $this->actingAs($user)->get(route('portal.subscriptions'))->assertOk()
            ->assertInertia(function (Assert $page) use (&$row) {
                $row = collect($page->toArray()['props']['clubMatrix'])->firstWhere('id', $this->hallClub->id);
            });

        return $row;
    }

    private function toggle(User $user): TestResponse
    {
        return $this->actingAs($user)->post(route('portal.subscriptions.toggle', ['clubSlug' => $this->hallClub->slug, 'typeId' => $this->channel->id]));
    }

    private function recipients(): array
    {
        $newsletter = Newsletter::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'subject' => 'Hall news', 'content' => '<p>Hi</p>', 'target_roles' => ['member'], 'status' => 'draft']);

        return app(NewsletterAudience::class)->recipients($newsletter)->pluck('email')->all();
    }

    public function test_lodge_member_sees_the_hall_channels_as_subscribed(): void
    {
        $row = $this->hallRow($this->member);

        $this->assertTrue($row['is_hall']);
        $this->assertFalse($row['is_member']);
        $this->assertSame([['id' => $this->channel->id, 'is_mandatory' => false, 'is_active' => true]], collect($row['channels'])->map(fn ($c) => collect($c)->only('id', 'is_active', 'is_mandatory')->all())->all());
    }

    public function test_unsubscribing_on_the_page_drops_them_from_the_next_hall_newsletter_and_resubscribing_brings_them_back(): void
    {
        $this->assertContains($this->member->email, $this->recipients());

        $this->toggle($this->member)->assertRedirect();
        $this->assertDatabaseHas('newsletter_subscriptions', ['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'user_id' => $this->member->id, 'status' => 'unsubscribed']);
        $this->assertFalse($this->hallRow($this->member)['channels'][0]['is_active']);
        $this->assertNotContains($this->member->email, $this->recipients());

        $this->toggle($this->member)->assertRedirect();
        $this->assertTrue($this->hallRow($this->member)['channels'][0]['is_active']);
        $this->assertContains($this->member->email, $this->recipients());
    }

    public function test_someone_outside_the_hall_audience_cannot_toggle_its_channel(): void
    {
        $this->toggle(User::factory()->create())->assertNotFound();
        $this->assertDatabaseCount('newsletter_subscriptions', 0);
    }

    public function test_a_board_only_user_sees_nothing_for_the_hall_and_cannot_toggle(): void
    {
        $board = User::factory()->create();
        $this->hallClub->users()->attach($board->id, ['role' => 'admin', 'status' => 'active']);

        $this->assertNull($this->hallRow($board));
        $this->toggle($board)->assertNotFound();
    }

    public function test_a_hall_with_no_channels_is_left_out_and_none_are_created(): void
    {
        $this->channel->delete();

        $this->assertNull($this->hallRow($this->member));
        $this->assertSame(0, NewsletterType::where('club_id', $this->hallClub->id)->count());
    }
}
