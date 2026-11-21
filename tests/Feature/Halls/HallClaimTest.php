<?php

namespace Tests\Feature\Halls;

use App\Models\HallClaim;
use App\Models\MasonicHall;
use App\Models\User;
use App\Notifications\HallClaimNotification;
use App\Services\Halls\HallClubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HallClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $claimant;

    private MasonicHall $hall;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.public_directory' => true]);

        $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->claimant = User::factory()->create();
        $this->hall = MasonicHall::factory()->create(['name' => 'Freemasons Hall Leeds']);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return ['claimant_role' => 'secretary', 'message' => 'I am the hall secretary and run it day to day.', 'confirm' => true, ...$overrides];
    }

    private function claim(?User $user = null): HallClaim
    {
        return HallClaim::factory()->create(['masonic_hall_id' => $this->hall->id, 'user_id' => ($user ?? $this->claimant)->id]);
    }

    public function test_signed_in_user_claims_a_hall_and_superadmin_approves(): void
    {
        Notification::fake();

        $this->get(route('lodges.hall.claim', $this->hall->slug))->assertRedirect(route('login'));

        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.store', $this->hall->slug), $this->data())
            ->assertRedirect(route('lodges.hall', $this->hall->slug))->assertSessionHas('success');
        Notification::assertSentTo($this->superAdmin, HallClaimNotification::class);
        Notification::assertNotSentTo($this->claimant, HallClaimNotification::class);

        $claim = HallClaim::firstOrFail();
        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.approve', $claim->id), ['note' => 'Checked with the hall company'])
            ->assertSessionHas('success');

        $club = $this->hall->fresh()->club;
        $this->assertTrue($club->isHall());
        $this->assertSame('owner', $club->users()->whereKey($this->claimant->id)->first()->pivot->role);
        $this->assertSame(HallClaim::APPROVED, $claim->fresh()->status);
        $this->assertSame($this->superAdmin->id, $claim->fresh()->decided_by);
        Notification::assertSentTo($this->claimant, HallClaimNotification::class, fn ($n) => str_starts_with($n->title, 'You now run')
            && $n->toArray($this->claimant)['url'] === route('admin.hall.edit', ['clubSlug' => $club->slug], false)
            && $n->toMail($this->claimant)->actionUrl === route('admin.hall.edit', ['clubSlug' => $club->slug]));
    }

    public function test_cannot_claim_a_hall_that_already_has_a_board(): void
    {
        app(HallClubs::class)->createFor($this->hall);

        $this->actingAs($this->claimant)->get(route('lodges.hall.claim', $this->hall->slug))->assertRedirect(route('lodges.hall', $this->hall->slug));
        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.store', $this->hall->slug), $this->data())->assertSessionHasErrors('hall');
        $this->assertSame(0, HallClaim::count());
    }

    public function test_the_claim_link_only_shows_while_the_hall_has_no_board(): void
    {
        $this->get(route('lodges.hall', $this->hall->slug))->assertOk()->assertInertia(fn ($page) => $page->where('hall.can_claim', true));

        app(HallClubs::class)->createFor($this->hall);

        $this->get(route('lodges.hall', $this->hall->slug))->assertOk()->assertInertia(fn ($page) => $page->where('hall.can_claim', false));
    }

    public function test_one_pending_claim_per_user_per_hall_and_a_cap_across_halls(): void
    {
        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.store', $this->hall->slug), $this->data())->assertSessionHasNoErrors();
        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.store', $this->hall->slug), $this->data())->assertSessionHasErrors('hall');
        $this->assertSame(1, HallClaim::count());

        HallClaim::factory()->count(HallClaim::MAX_OPEN_PER_USER - 1)->create(['user_id' => $this->claimant->id]);
        $other = MasonicHall::factory()->create();
        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.store', $other->slug), $this->data())->assertSessionHasErrors('hall');
    }

    public function test_approving_when_a_board_appeared_meanwhile_fails_cleanly(): void
    {
        $claim = $this->claim();
        app(HallClubs::class)->createFor($this->hall);

        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.approve', $claim->id))->assertSessionHasErrors('claim');

        $this->assertSame(HallClaim::PENDING, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->decided_by);
        $this->assertSame(0, $this->hall->fresh()->club->users()->count());
    }

    public function test_request_info_then_respond_then_reject(): void
    {
        Notification::fake();
        $claim = $this->claim();

        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.request_info', $claim->id), ['question' => 'Which company owns the hall?'])->assertSessionHas('success');
        $this->assertSame(HallClaim::INFO_REQUESTED, $claim->fresh()->status);
        Notification::assertSentTo($this->claimant, HallClaimNotification::class, fn ($n) => $n->body === 'Which company owns the hall?');

        $this->actingAs($this->claimant)->get(route('lodges.hall.claim', $this->hall->slug))
            ->assertInertia(fn ($page) => $page->where('claim.status', 'info_requested')->where('claim.question', 'Which company owns the hall?'));

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post(route('lodges.hall.claim.respond', [$this->hall->slug, $claim->id]), ['reply' => 'Not mine'])->assertNotFound();

        $this->actingAs($this->claimant)->post(route('lodges.hall.claim.respond', [$this->hall->slug, $claim->id]), ['reply' => 'Leeds Masonic Hall Ltd.'])->assertSessionHas('success');
        $this->assertSame(HallClaim::PENDING, $claim->fresh()->status);
        $this->assertSame('Leeds Masonic Hall Ltd.', $claim->fresh()->reply);
        Notification::assertSentTo($this->superAdmin, HallClaimNotification::class, fn ($n) => str_starts_with($n->title, 'Claim updated'));

        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.reject', $claim->id), ['reason' => 'We could not confirm it.'])->assertSessionHas('success');
        $this->assertSame(HallClaim::REJECTED, $claim->fresh()->status);
        $this->assertNull($this->hall->fresh()->club_id);
        Notification::assertSentTo($this->claimant, HallClaimNotification::class, fn ($n) => str_contains($n->title, 'was not approved'));

        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.reject', $claim->id), ['reason' => 'Again'])->assertSessionHasErrors('claim');
    }

    public function test_a_claimant_can_withdraw_and_only_a_superadmin_can_decide(): void
    {
        $claim = $this->claim();

        $this->actingAs($this->claimant)->post(route('superadmin.hall_claims.approve', $claim->id))->assertRedirect(route('home'));
        $this->assertSame(HallClaim::PENDING, $claim->fresh()->status);
        $this->assertNull($this->hall->fresh()->club_id);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->delete(route('lodges.hall.claim.withdraw', [$this->hall->slug, $claim->id]))->assertNotFound();
        $this->assertSame(HallClaim::PENDING, $claim->fresh()->status);

        $this->actingAs($this->claimant)->delete(route('lodges.hall.claim.withdraw', [$this->hall->slug, $claim->id]))->assertSessionHas('success');
        $this->assertSame(HallClaim::WITHDRAWN, $claim->fresh()->status);
    }

    public function test_a_decided_claim_cannot_be_withdrawn_or_approved_again(): void
    {
        $claim = $this->claim();
        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.approve', $claim->id))->assertSessionHasNoErrors();

        $this->actingAs($this->claimant)->delete(route('lodges.hall.claim.withdraw', [$this->hall->slug, $claim->id]))->assertSessionHasErrors('claim');
        $this->actingAs($this->superAdmin)->post(route('superadmin.hall_claims.approve', $claim->id))->assertSessionHasErrors('claim');
        $this->assertSame(HallClaim::APPROVED, $claim->fresh()->status);
    }

    public function test_superadmin_page_lists_waiting_hall_claims(): void
    {
        $this->claim();

        $this->actingAs($this->superAdmin)->get(route('superadmin.lodge_claims.index'))
            ->assertInertia(fn ($page) => $page->has('hallClaims', 1)->where('hallClaims.0.hall.name', 'Freemasons Hall Leeds'));
    }
}
