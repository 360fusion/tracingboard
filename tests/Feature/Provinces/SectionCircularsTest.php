<?php

namespace Tests\Feature\Provinces;

use App\Domains\ClubAccounting\Models\Member;
use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\PassedOnItem;
use App\Models\Province;
use App\Models\SectionCircular;
use App\Models\User;
use App\Notifications\SectionCircularNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SectionCircularsTest extends TestCase
{
    use RefreshDatabase;

    private Club $section;

    private Club $chapter;

    private Club $markLodge;

    private User $sectionAdmin;

    private User $chapterSecretary;

    private User $chapterAdmin;

    private User $chapterMember;

    private User $markSecretary;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $mark = ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']);

        $group = Club::factory()->provinceGroup($durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($group, $roseCroix)->create(['name' => 'Durham Rose Croix']);
        $this->sectionAdmin = $this->user($this->section, 'admin');

        $this->chapter = Club::factory()->create(['club_type_id' => $roseCroix->id, 'province_id' => $durham->id]);
        // The Secretary by office, not by login role.
        $this->chapterSecretary = $this->user($this->chapter, 'member');
        Member::create(['club_id' => $this->chapter->id, 'user_id' => $this->chapterSecretary->id, 'first_name' => 'Alan', 'last_name' => 'Smith', 'membership_status' => 'active', 'current_office' => 'secretary']);
        $this->chapterAdmin = $this->user($this->chapter, 'admin');
        $this->chapterMember = $this->user($this->chapter, 'member');

        $this->markLodge = Club::factory()->create(['club_type_id' => $mark->id, 'province_id' => $durham->id]);
        $this->markSecretary = $this->user($this->markLodge, 'secretary');
    }

    private function user(Club $club, string $role): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function sendCircular(): SectionCircular
    {
        $this->actingAs($this->sectionAdmin)->post(route('admin.circulars.store', ['clubSlug' => $this->section->slug]), [
            'title' => 'Provincial meeting dates',
            'body' => "The dates for next year.\n\nPlease tell your members.",
            'attachment' => UploadedFile::fake()->create('dates.pdf', 20, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SectionCircular::firstOrFail();
    }

    public function test_circular_notifies_chapter_secretaries_only(): void
    {
        Notification::fake();
        $this->sendCircular();

        Notification::assertSentTo($this->chapterSecretary, SectionCircularNotification::class, fn (SectionCircularNotification $notification) => $notification->queue === 'transactional');
        Notification::assertSentTo($this->chapterAdmin, SectionCircularNotification::class);
        Notification::assertNotSentTo($this->markSecretary, SectionCircularNotification::class);
        Notification::assertNotSentTo($this->chapterMember, SectionCircularNotification::class);
        Notification::assertNotSentTo($this->sectionAdmin, SectionCircularNotification::class);
    }

    public function test_a_mark_sections_circular_counts_lodges(): void
    {
        Notification::fake();
        $markSection = Club::factory()->provinceSection(Club::findOrFail($this->section->parent_club_id), $this->markLodge->clubType)->create();

        $this->actingAs($this->user($markSection, 'admin'))->post(route('admin.circulars.store', ['clubSlug' => $markSection->slug]), ['title' => 'Dates', 'body' => 'Dates'])
            ->assertSessionHas('success', 'Sent to 1 lodge. Their Secretaries pass it on to their members.');
    }

    public function test_circular_shows_on_the_chapters_pass_on_screen(): void
    {
        Notification::fake();
        $circular = $this->sendCircular();

        $this->actingAs($this->chapterSecretary)->get(route('member.pass_on', ['slug' => $this->chapter->slug]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('circulars', 1)
                ->where('circulars.0.id', $circular->id)
                ->where('circulars.0.title', 'Provincial meeting dates')
                ->where('circulars.0.body', "The dates for next year.\n\nPlease tell your members."));

        $this->actingAs($this->markSecretary)->get(route('member.pass_on', ['slug' => $this->markLodge->slug]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('circulars', 0));
    }

    public function test_circular_attachment_is_refused_outside_its_chapters(): void
    {
        Notification::fake();
        $circular = $this->sendCircular();

        $this->actingAs($this->chapterSecretary)->get(route('member.pass_on.circular_attachment', ['slug' => $this->chapter->slug, 'id' => $circular->id]))->assertOk();
        $this->actingAs($this->markSecretary)->get(route('member.pass_on.circular_attachment', ['slug' => $this->markLodge->slug, 'id' => $circular->id]))->assertNotFound();
        $this->actingAs($this->chapterMember)->get(route('member.pass_on.circular_attachment', ['slug' => $this->chapter->slug, 'id' => $circular->id]))->assertForbidden();
    }

    public function test_circular_sends_no_member_email(): void
    {
        Notification::fake();
        Mail::fake();
        $this->sendCircular();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame(0, PassedOnItem::count());
    }

    public function test_circulars_are_only_for_sections(): void
    {
        $this->actingAs($this->user($this->chapter, 'admin'))->get(route('admin.circulars.index', ['clubSlug' => $this->chapter->slug]))->assertNotFound();
    }
}
