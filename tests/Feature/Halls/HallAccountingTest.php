<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HallAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function treasurerOf(Club $club): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => 'treasurer', 'status' => 'active']);

        return $user;
    }

    public function test_a_hall_has_no_due_to_province_report_or_annual_return(): void
    {
        $hall = Club::factory()->hall()->create();
        $treasurer = $this->treasurerOf($hall);

        $this->actingAs($treasurer)->get(route('admin.accounting.index', ['clubSlug' => $hall->slug, 'tab' => 'reporting']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reports.provincial_due', null));
        $this->get(route('admin.accounting.reports.export', ['clubSlug' => $hall->slug, 'report' => 'provincial_due']))->assertNotFound();
        $this->get(route('admin.accounting.reports.export_pdf', ['clubSlug' => $hall->slug, 'report' => 'provincial_due']))->assertNotFound();
        $this->get(route('admin.accounting.annual_return', ['clubSlug' => $hall->slug]))->assertNotFound();
        $this->get(route('admin.accounting.treasurer_report.export_pdf', ['clubSlug' => $hall->slug]))->assertOk();
    }

    public function test_a_lodge_keeps_its_due_to_province_report(): void
    {
        $lodge = Club::factory()->create();
        $treasurer = $this->treasurerOf($lodge);

        $this->actingAs($treasurer)->get(route('admin.accounting.index', ['clubSlug' => $lodge->slug, 'tab' => 'reporting']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('reports.provincial_due.member_count'));
        $this->get(route('admin.accounting.reports.export', ['clubSlug' => $lodge->slug, 'report' => 'provincial_due']))->assertOk();
    }
}
