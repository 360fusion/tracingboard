<?php

namespace App\Http\Controllers;

use App\Models\Lodge;
use App\Models\LodgeSchedule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lodges and chapters that meet at a hall, with their meeting pattern. Read-only: it links nowhere into a lodge's admin.
 */
class HallLodgesController extends Controller
{
    public function index(string $clubSlug): Response
    {
        $hall = $this->findClub($clubSlug)->hall()->firstOrFail();

        // A claimed lodge is shown once, through its club, and the club's own hall decides whether it is listed here.
        $listed = $hall->clubs()->notGroups()->with('lodge.schedules')->get()->map(fn ($club) => [
            'name' => $club->name,
            'number' => $club->lodge_number ?? $club->lodge?->number,
            'meets_text' => $club->lodge?->meets_text,
            'schedules' => $club->lodge?->schedules,
        ])->concat(
            Lodge::where('masonic_hall_id', $hall->id)->whereNull('club_id')->with('schedules')->get()->map(fn ($lodge) => [
                'name' => $lodge->name,
                'number' => $lodge->number,
                'meets_text' => $lodge->meets_text,
                'schedules' => $lodge->schedules,
            ])
        );

        return Inertia::render('Admin/Hall/Lodges', [
            'club' => $this->findClub($clubSlug),
            'lodges' => $listed->map(fn (array $row) => [
                'name' => $row['name'],
                'number' => $row['number'],
                'meets_text' => $row['meets_text'],
                'schedules' => collect($row['schedules'])->map(fn (LodgeSchedule $s) => $s->only(['kind', 'occurrence', 'day_of_week', 'months', 'start_time']))->values(),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ]);
    }
}
