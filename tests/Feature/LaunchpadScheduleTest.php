<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class LaunchpadScheduleTest extends TestCase
{
    public function test_launchpad_tasks_on_tables_the_app_never_creates_are_skipped(): void
    {
        $running = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->filtersPass($this->app))
            ->map(fn ($event) => (string) $event->command);

        $this->assertFalse($running->contains(fn ($command) => str_contains($command, 'launchpad:purge-closed')));
        $this->assertFalse($running->contains(fn ($command) => str_contains($command, 'ApiTokenUsage')));
        $this->assertTrue($running->contains(fn ($command) => str_contains($command, 'accounts:purge-closed')));
        $this->assertTrue($running->contains(fn ($command) => str_contains($command, 'WorkspaceEvent')));
    }
}
