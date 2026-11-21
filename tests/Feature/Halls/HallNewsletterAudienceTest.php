<?php

namespace Tests\Feature\Halls;

use App\Http\Controllers\NewsletterTypeAdminController;
use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\Newsletter;
use App\Models\NewsletterSubscription;
use App\Models\NewsletterType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HallNewsletterAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Club $hallClub;

    private Club $lodge;

    private User $board;

    private NewsletterType $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $this->lodge = Club::factory()->create(['masonic_hall_id' => $hall->id]);
        $this->board = User::factory()->create();
        $this->hallClub->users()->attach($this->board->id, ['role' => 'admin', 'status' => 'active']);
        $this->channel = NewsletterType::create(['club_id' => $this->hallClub->id, 'name' => 'Hall news', 'slug' => 'hall-news', 'is_external_subscribable' => true, 'is_mandatory' => false]);
    }

    private function member(Club $club, string $status = 'active', string $role = 'member'): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => $role, 'status' => $status]);

        return $user;
    }

    private function send(): Newsletter
    {
        $newsletter = Newsletter::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'subject' => 'Hall news', 'content' => '<p>Hi</p>', 'target_roles' => ['member'], 'status' => 'draft']);
        $this->actingAs($this->board)->post(route('admin.newsletters.send', ['clubSlug' => $this->hallClub->slug, 'id' => $newsletter->id]))->assertSessionHasNoErrors();

        return $newsletter;
    }

    public function test_hall_newsletter_reaches_active_members_of_lodges_at_the_hall(): void
    {
        Mail::fake();
        $active = $this->member($this->lodge, 'active', 'treasurer');
        $second = Club::factory()->create(['masonic_hall_id' => $this->lodge->masonic_hall_id]);
        $this->member($this->lodge, 'pending');
        $this->member($this->lodge, 'past');
        $elsewhere = Club::factory()->create();
        $this->member($elsewhere);
        $second->users()->attach($active->id, ['role' => 'member', 'status' => 'active']);
        NewsletterSubscription::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'email' => 'visitor@example.test', 'name' => 'V', 'status' => 'active']);

        $newsletter = $this->send();

        $this->assertEqualsCanonicalizing([$active->email, 'visitor@example.test'], $newsletter->deliveries()->pluck('email')->all());
    }

    public function test_board_rows_alone_do_not_make_someone_a_recipient(): void
    {
        Mail::fake();

        $this->assertSame(0, $this->send()->deliveries()->count());
    }

    public function test_unsubscribe_link_from_a_hall_newsletter_stops_the_next_one(): void
    {
        Mail::fake();
        $member = $this->member($this->lodge);

        $first = $this->send();
        $url = route('newsletters.unsubscribe', ['token' => $first->deliveries()->sole()->token]);
        $this->post($url)->assertOk()->assertSee('You have been unsubscribed');

        $this->assertDatabaseHas('newsletter_subscriptions', ['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'user_id' => $member->id, 'status' => 'unsubscribed']);
        $this->assertSame(0, $this->send()->deliveries()->count());
    }

    public function test_hall_channel_cannot_be_mandatory(): void
    {
        $payload = ['name' => 'Notices', 'color' => '#111111', 'icon' => 'x', 'is_external_subscribable' => false, 'require_approval' => false, 'is_mandatory' => true, 'require_home_club_info' => false];

        $this->actingAs($this->board)->post(route('admin.newsletters.types.store', ['clubSlug' => $this->hallClub->slug]), $payload)->assertSessionHasErrors('is_mandatory');

        $this->actingAs($this->board)->post(route('admin.newsletters.types.store', ['clubSlug' => $this->hallClub->slug]), ['is_mandatory' => false] + $payload)->assertSessionHasNoErrors();
        $this->assertSame(0, NewsletterType::where('club_id', $this->hallClub->id)->where('is_mandatory', true)->count());
    }

    public function test_default_channels_of_a_hall_are_not_mandatory(): void
    {
        $fresh = Club::factory()->hall()->create();
        NewsletterTypeAdminController::ensureDefaultTypes($fresh);

        $this->assertSame(0, NewsletterType::where('club_id', $fresh->id)->where('is_mandatory', true)->count());
    }

    public function test_hall_newsletter_screen_shows_counts_not_recipients(): void
    {
        $member = $this->member($this->lodge);
        $this->member($this->lodge);
        NewsletterSubscription::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'user_id' => $member->id, 'email' => $member->email, 'name' => $member->name, 'status' => 'unsubscribed']);
        Newsletter::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'subject' => 'Draft', 'content' => '<p>Hi</p>', 'target_roles' => ['member'], 'status' => 'draft']);

        $this->actingAs($this->board)->get(route('admin.newsletters.index', ['clubSlug' => $this->hallClub->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('newsletters.data.0.recipient_count', 1))
            ->assertDontSee($member->email);

        $this->actingAs($this->board)->get(route('admin.newsletters.subscribers', ['clubSlug' => $this->hallClub->slug]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('subscriptions', 0));
    }
}
