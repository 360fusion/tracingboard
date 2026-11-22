<?php

namespace Tests\Feature\Provinces;

use App\Enums\Visibility;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\Event;
use App\Models\GrandLodge;
use App\Models\Newsletter;
use App\Models\NewsletterSubscription;
use App\Models\NewsletterType;
use App\Models\Post;
use App\Models\Province;
use App\Models\User;
use App\Services\MemberFeed;
use App\Services\Newsletters\NewsletterAudience;
use App\Support\GroupAudience;
use App\Support\MemberScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SectionAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Club $section;

    private User $durhamRoseCroix;

    private User $northumberlandRoseCroix;

    private User $durhamMark;

    private User $pending;

    private Club $durhamChapter;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $northumberland = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Northumberland', 'code' => 'northumberland', 'country' => 'England']);
        $roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $mark = ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']);

        $group = Club::factory()->provinceGroup($durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($group, $roseCroix)->create(['name' => 'Durham Rose Croix']);

        $this->durhamChapter = Club::factory()->create(['club_type_id' => $roseCroix->id, 'province_id' => $durham->id]);
        $this->durhamRoseCroix = $this->member($this->durhamChapter);
        $this->pending = $this->member($this->durhamChapter, 'pending');
        $this->northumberlandRoseCroix = $this->member(Club::factory()->create(['club_type_id' => $roseCroix->id, 'province_id' => $northumberland->id]));
        $this->durhamMark = $this->member(Club::factory()->create(['club_type_id' => $mark->id, 'province_id' => $durham->id]));
    }

    private function member(Club $club, string $status = 'active', string $role = 'member'): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => $role, 'status' => $status]);

        return $user;
    }

    public function test_members_only_event_reaches_only_the_orders_chapters_in_the_province(): void
    {
        Event::create([
            'club_id' => $this->section->id, 'title' => 'Provincial meeting', 'slug' => 'provincial-meeting', 'status' => 'upcoming',
            'starts_at' => now()->addDays(3), 'visibility' => Visibility::Club, 'rsvp_audience' => Visibility::Club, 'requires_payment' => false,
        ]);
        $eventsUrl = route('member.events', ['slug' => $this->section->slug]);

        $this->actingAs($this->durhamRoseCroix)->get($eventsUrl)->assertOk();
        $this->assertStringContainsString('Provincial meeting', $this->calendarFeed($this->durhamRoseCroix));

        foreach ([$this->northumberlandRoseCroix, $this->durhamMark, $this->pending] as $outsider) {
            $this->actingAs($outsider)->get($eventsUrl)->assertNotFound();
            $this->assertStringNotContainsString('Provincial meeting', $this->calendarFeed($outsider));
        }
    }

    private function calendarFeed(User $user): string
    {
        $this->actingAs($user)->get('/members/calendar')->assertOk();
        $url = route('members.calendar.feed', ['token' => $user->fresh()->calendar_token], false);
        auth()->logout();

        return $this->get($url)->assertOk()->getContent();
    }

    public function test_members_only_post_reaches_the_member_feed(): void
    {
        $post = Post::create([
            'club_id' => $this->section->id, 'author_id' => $this->durhamRoseCroix->id, 'title' => 'Provincial news', 'slug' => 'provincial-news',
            'content' => '<p>Body</p>', 'status' => 'published', 'visibility' => Visibility::Club,
        ]);
        $feedTitles = fn (User $user) => collect(app(MemberFeed::class)->items($user, MemberScope::for($user)->clubs()))->pluck('title');

        $this->assertContains('Provincial news', $feedTitles($this->durhamRoseCroix));
        $this->assertNotContains('Provincial news', $feedTitles($this->durhamMark));

        $showUrl = route('member.posts.show', ['slug' => $this->section->slug, 'id' => $post->id]);
        $this->actingAs($this->durhamRoseCroix)->get($showUrl)->assertOk();
        $this->actingAs($this->durhamMark)->get($showUrl)->assertNotFound();
    }

    public function test_section_newsletter_recipients_are_chapter_members_less_opt_outs(): void
    {
        $second = $this->member($this->durhamChapter);
        $channel = NewsletterType::create(['club_id' => $this->section->id, 'name' => 'Provincial news', 'slug' => 'provincial-news', 'is_external_subscribable' => true, 'is_mandatory' => false]);
        $newsletter = Newsletter::create(['club_id' => $this->section->id, 'newsletter_type_id' => $channel->id, 'subject' => 'News', 'content' => '<p>Hi</p>', 'target_roles' => ['member'], 'status' => 'draft']);
        $recipients = fn () => app(NewsletterAudience::class)->recipients($newsletter->fresh())->pluck('email')->all();

        $this->assertEqualsCanonicalizing([$this->durhamRoseCroix->email, $second->email], $recipients());

        NewsletterSubscription::create(['club_id' => $this->section->id, 'newsletter_type_id' => $channel->id, 'user_id' => $second->id, 'email' => $second->email, 'name' => $second->name, 'status' => 'unsubscribed']);

        $this->assertSame([$this->durhamRoseCroix->email], $recipients());
    }

    public function test_section_people_are_not_members(): void
    {
        $admin = $this->member($this->section, role: 'admin');

        $this->assertTrue($admin->activeClubIds()->isEmpty());
        $this->actingAs($admin)->get("/members/{$this->section->slug}/dashboard")->assertNotFound();
        $this->actingAs($admin)->get("/members/{$this->section->slug}")->assertNotFound();
        $this->assertFalse(Visibility::Network->includes($admin, $this->section->id));
    }

    public function test_section_channel_cannot_be_mandatory(): void
    {
        $admin = $this->member($this->section, role: 'admin');
        $payload = ['name' => 'Notices', 'color' => '#111111', 'icon' => 'x', 'is_external_subscribable' => false, 'require_approval' => false, 'is_mandatory' => 1, 'require_home_club_info' => false];

        $this->actingAs($admin)->post(route('admin.newsletters.types.store', ['clubSlug' => $this->section->slug]), $payload)->assertSessionHasErrors('is_mandatory');
    }

    public function test_visibility_label_names_the_order_and_province(): void
    {
        $club = collect(Visibility::options($this->section))->firstWhere('value', Visibility::Club->value);

        $this->assertSame('Members of Rose Croix chapters in Durham', $club['label']);
    }

    public function test_group_clubs_for_makes_at_most_two_queries(): void
    {
        $clubs = Club::whereIn('id', [$this->durhamChapter->id, ...Club::notGroups()->pluck('id')])->get();

        DB::enableQueryLog();
        $groups = GroupAudience::groupClubsFor($clubs);
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->assertSame([$this->section->id], $groups->pluck('id')->all());
    }
}
