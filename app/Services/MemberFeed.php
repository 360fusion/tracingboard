<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Club;
use App\Models\ClubUpdate;
use App\Models\Event;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Support\GroupAudience;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What a member's lodges have put out: news, upcoming events, notices and newsletters, newest first (important
 * notices on top). Shown on the home page and, beside the notifications sent to the member, in the inbox.
 */
class MemberFeed
{
    /**
     * Recent news, events, updates and newsletters from the member's clubs.
     *
     * @param  Collection<int, Club>  $clubs
     * @return list<array<string, mixed>>
     */
    public function items(User $user, Collection $clubs, int $limit = 20): array
    {
        if ($clubs->isEmpty()) {
            return [];
        }

        $clubIds = $clubs->pluck('id');
        $groupClubs = GroupAudience::groupClubsFor($clubs);
        $club = function (int $id) use ($clubs, $groupClubs): array {
            $found = $clubs->firstWhere('id', $id) ?? $groupClubs->firstWhere('id', $id);

            return ['name' => $found->name, 'slug' => $found->slug, 'colour' => $found->colourKey()];
        };
        $roles = $clubs->mapWithKeys(fn (Club $c) => [$c->id => $c->pivot->role ?? 'member']);

        $posts = Post::whereIn('club_id', $clubIds->merge($groupClubs->pluck('id')))->published()->visibleTo($user)
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->limit($limit)->get()
            ->map(fn (Post $post) => [
                'key' => 'post-'.$post->id,
                'type' => 'news',
                'club' => $club($post->club_id),
                'title' => $post->title,
                'excerpt' => $this->excerpt($post->excerpt ?: $post->content),
                'at' => ($post->published_at ?? $post->created_at)->toIso8601String(),
                'important' => false,
                'link' => ['name' => 'member.posts.show', 'params' => ['slug' => $club($post->club_id)['slug'], 'id' => $post->id]],
            ]);

        $events = Event::whereIn('club_id', $clubIds->merge($groupClubs->pluck('id')))->visibleTo($user)
            ->where('status', EventStatus::Upcoming->value)
            ->where('starts_at', '>=', now())
            ->latest()->limit($limit)->get()
            ->map(fn (Event $event) => [
                'key' => 'event-'.$event->id,
                'type' => 'event',
                'club' => $club($event->club_id),
                'title' => $event->title,
                'excerpt' => trim($event->starts_at->format('j M Y, H:i').' · '.$event->location, ' ·'),
                'at' => $event->created_at->toIso8601String(),
                'important' => false,
                'link' => ['name' => 'member.events', 'params' => ['slug' => $club($event->club_id)['slug']]],
            ]);

        $updates = ClubUpdate::whereIn('club_id', $clubIds)->where('status', 'sent')
            ->latest('sent_at')->limit($limit)->get()
            ->map(fn (ClubUpdate $update) => [
                'key' => 'update-'.$update->id,
                'type' => 'update',
                'club' => $club($update->club_id),
                'title' => $update->title,
                'excerpt' => $this->excerpt($update->summary),
                'at' => ($update->sent_at ?? $update->created_at)->toIso8601String(),
                'important' => (bool) $update->is_important,
                'link' => ['name' => 'member.dashboard', 'params' => ['slug' => $club($update->club_id)['slug']]],
            ]);

        $newsletters = Newsletter::whereIn('club_id', $clubIds)->where('status', 'sent')
            ->latest('sent_at')->limit($limit)->get()
            ->filter(fn (Newsletter $n) => $n->reachesRole($roles[$n->club_id]))
            ->map(fn (Newsletter $newsletter) => [
                'key' => 'newsletter-'.$newsletter->id,
                'type' => 'newsletter',
                'club' => $club($newsletter->club_id),
                'title' => $newsletter->subject,
                'excerpt' => $this->excerpt($newsletter->content),
                'at' => ($newsletter->sent_at ?? $newsletter->created_at)->toIso8601String(),
                'important' => false,
                'link' => ['name' => 'member.dashboard', 'params' => ['slug' => $club($newsletter->club_id)['slug']]],
            ]);

        return $posts->concat($events)->concat($updates)->concat($newsletters)
            ->sortBy([['important', 'desc'], ['at', 'desc']])
            ->take($limit)
            ->values()
            ->all();
    }

    private function excerpt(?string $html): string
    {
        // Decoded after the tags go, so "&amp;" reads as "&" (Vue escapes it again on screen).
        return Str::limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5))), 160);
    }
}
