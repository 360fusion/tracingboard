<?php

namespace App\Http\Controllers;

use App\Services\Provinces\SectionChapters;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lodges or chapters of a section's order in its province. Read-only.
 */
class SectionChaptersController extends Controller
{
    public function index(string $clubSlug): Response
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        return Inertia::render('Admin/Province/Chapters', ['club' => $club, 'chapters' => SectionChapters::for($club)]);
    }
}
