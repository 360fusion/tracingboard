<?php

namespace Tests\Feature\Halls;

use App\Http\Controllers\NewsletterTypeAdminController;
use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\Newsletter;
use App\Models\NewsletterSubscription;
use App\Models\NewsletterType;
use App\Models\User;
use App\Services\Newsletters\NewsletterAudience;
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

    private User $admin;

    private NewsletterType $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $this->lodge = Club::factory()->create(['masonic_hall_id' => $hall->id]);
        $this->board = User::factory()->create();
        $this->hallClub->users()->attach($this->board->id, ['role' => 'member', 'status' => 'active']);
        $this->admin = User::factory()->create();
        $this->hallClub->users()->attach($this->admin->id, ['role' => 'admin', 'status' => 'active']);
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
        $this->actingAs($this->admin)->post(route('admin.newsletters.send', ['clubSlug' => $this->hallClub->slug, 'id' => $newsletter->id]))->assertSessionHasNoErrors();

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

        $this->actingAs($this->admin)->post(route('admin.newsletters.types.store', ['clubSlug' => $this->hallClub->slug]), $payload)->assertSessionHasErrors('is_mandatory');

        $this->actingAs($this->admin)->post(route('admin.newsletters.types.store', ['clubSlug' => $this->hallClub->slug]), ['is_mandatory' => false] + $payload)->assertSessionHasNoErrors();
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

        $this->actingAs($this->admin)->get(route('admin.newsletters.index', ['clubSlug' => $this->hallClub->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('newsletters.data.0.recipient_count', 1))
            ->assertDontSee($member->email);

        $this->actingAs($this->admin)->get(route('admin.newsletters.subscribers', ['clubSlug' => $this->hallClub->slug]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('subscriptions', 0));
    }

    public function test_hall_newsletter_must_have_a_channel(): void
    {
        $payload = ['subject' => 'S', 'content' => '<p>x</p>', 'target_roles' => ['member'], 'status' => 'draft', 'newsletter_type_id' => null];

        $this->actingAs($this->admin)->post(route('admin.newsletters.store', ['clubSlug' => $this->hallClub->slug]), $payload)->assertSessionHasErrors('newsletter_type_id');
    }

    public function test_hall_draft_whose_channel_was_deleted_reaches_nobody(): void
    {
        Mail::fake();
        $this->member($this->lodge);
        $newsletter = Newsletter::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'subject' => 'S', 'content' => '<p>x</p>', 'target_roles' => ['member'], 'status' => 'draft']);
        $this->channel->delete();

        $audience = app(NewsletterAudience::class);
        $this->assertNull($newsletter->fresh()->newsletter_type_id);
        $this->assertSame(0, $audience->recipients($newsletter->fresh())->count());
        $this->assertSame(0, $audience->counts($newsletter->fresh())['total']);
        $this->actingAs($this->admin)->post(route('admin.newsletters.send', ['clubSlug' => $this->hallClub->slug, 'id' => $newsletter->id]));
        $this->assertSame(0, $newsletter->deliveries()->count());
    }

    public function test_board_cannot_resubscribe_a_lodge_member_who_opted_out(): void
    {
        $member = $this->member($this->lodge);
        $row = NewsletterSubscription::create(['club_id' => $this->hallClub->id, 'newsletter_type_id' => $this->channel->id, 'user_id' => $member->id, 'email' => $member->email, 'name' => $member->name, 'status' => 'unsubscribed']);

        $this->actingAs($this->admin)->post(route('admin.newsletters.subscribers.status', ['clubSlug' => $this->hallClub->slug, 'id' => $row->id]), ['status' => 'active'])->assertNotFound();
        $this->assertSame('unsubscribed', $row->fresh()->status);
    }

    public function test_hall_channel_is_never_mandatory_even_if_stored_so(): void
    {
        Mail::fake();
        $this->channel->update(['is_mandatory' => true]);
        $this->member($this->lodge);

        $first = $this->send();
        $this->post(route('newsletters.unsubscribe', ['token' => $first->deliveries()->sole()->token]))->assertSee('You have been unsubscribed');
        $this->assertSame(0, $this->send()->deliveries()->count());
    }

    public function test_hall_newsletter_form_flags_the_club_as_a_hall(): void
    {
        $this->actingAs($this->admin)->get(route('admin.newsletters.create', ['clubSlug' => $this->hallClub->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('isHall', true));
    }
}
