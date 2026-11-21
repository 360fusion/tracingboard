# Hall Board Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a masonic hall's board sign in and run the hall (its details, events, website, accounts and newsletters to the lodges that meet there) without gaining anything at those lodges.

**Architecture:** A hall is a `Club` of a new `hall` club type, linked one-to-one to its `MasonicHall` through a new `masonic_halls.club_id`. Board members are `club_user` rows on that club. These rows are left out of every "is a member of a club" check. A hall's club-audience events and its newsletters reach the active members of clubs whose `clubs.masonic_hall_id` is the hall. Lodge-only admin sections return 404 for a hall.

**Tech Stack:** Laravel 13, Inertia v3 + Vue 3, Tailwind v4, PHPUnit, PostgreSQL (tests on SQLite).

**Spec:** `docs/superpowers/specs/2026-11-21-hall-board-design.md`

## Global Constraints

- Follow CLAUDE.md: `$this->findClub($clubSlug)`, `ClubAccess`, `config/club_permissions.php` `route_map`, no per-item queries, `Club::factory()` / `ClubType::factory()` in tests, `Currencies` for money, migrations PostgreSQL-first.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, `composer analyse` (no new baseline entries), `npm run build`, `npm run check:templates` and `npm run check:design` after Vue changes.
- A hall board role never grants anything at a lodge. A board row never counts as club membership.
- Hall details changes go live straight away. The board cannot change `province_id`, `slug`, `ugle_url` or `source_url`.
- The hall board sees names and contacts only of people who booked one of its events. It never sees the lodge members' list or a newsletter's recipient list (counts and delivery status only).
- A hall newsletter channel can never be mandatory.
- UI copy: sentence case, "Hall", "Board", "Members of lodges that meet here".

## Review Focus

1. A user who is on a hall board **and** a member of a lodge: the lodge still appears in the member area, the hall does not, and they still see the hall's club-audience events because their lodge meets there. Test in Task 2 and Task 6.
2. A lodge whose admin moves it to a different hall: its members stop seeing the old hall's club-audience events and stop getting its newsletters on the next send. Test in Task 6 and Task 8.
3. A hall club must not appear in its own "hall's lodges" list, its own event audience or its own newsletter audience (the hall club has `clubs.masonic_hall_id` null). Test in Task 5 and Task 8.
4. A lodge member who unsubscribed from a hall channel is not sent the next hall newsletter, even though they never had a subscription row before. Test in Task 8.
5. Two claims, or a claim plus a superadmin invite, for the same hall: only one hall club can ever exist for a `MasonicHall`. Test in Task 10 (unique index plus a service guard).

---

### Task 1: Hall club type and the hall ↔ club link

**Files:**
- Create: `database/migrations/2026_11_21_100000_add_club_id_to_masonic_halls_table.php`
- Create: `database/migrations/2026_11_21_100001_add_hall_club_type.php`
- Create: `app/Services/Halls/HallClubs.php`
- Modify: `app/Models/MasonicHall.php`, `app/Models/Club.php`, `database/seeders/ClubTypeSeeder.php`, `database/factories/ClubTypeFactory.php`
- Test: `tests/Feature/Halls/HallClubsTest.php`

**Interfaces:**
- Produces: `ClubType::HALL = 'hall'` (const on `App\Models\ClubType`); `Club::isHall(): bool` (club type code is `hall`, uses the loaded `clubType`); `Club::hall(): HasOne<MasonicHall, $this>` (via `masonic_halls.club_id`); `MasonicHall::club(): BelongsTo<Club, $this>`; `ClubType::factory()->hall()` state; `HallClubs::createFor(MasonicHall $hall, User $owner): Club`; `Club::scopeNotHalls(Builder $q)` excluding hall-type clubs.

- [ ] **Step 1: Write the failing tests**

```php
public function test_create_for_makes_one_hall_club_owned_by_the_user(): void
{
    $hall = MasonicHall::factory()->create(['name' => 'Freemasons Hall Leeds']);
    $owner = User::factory()->create();

    $club = app(HallClubs::class)->createFor($hall, $owner);

    $this->assertTrue($club->isHall());
    $this->assertSame($club->id, $hall->fresh()->club_id);
    $this->assertNull($club->masonic_hall_id);            // the hall club is not one of its own lodges
    $this->assertSame('owner', $club->users()->first()->pivot->role);
}

public function test_create_for_refuses_a_hall_that_already_has_a_club(): void
{
    // second call for the same hall throws \DomainException and leaves one club
}
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Halls/HallClubsTest.php`. Expected: FAIL (class not found).

- [ ] **Step 3: Implement**
  - Migration 1: nullable `club_id` foreign key on `masonic_halls` (`nullOnDelete`), with a **unique** index.
  - Migration 2: insert the `hall` club type if it is missing: name `Masonic hall`, `available_modules` `['accounting','communications','events','newsletters','website_builder']`, terminology `['club' => 'Hall', 'members' => 'Board', 'master' => 'Chairman']`. Add the same entry to `ClubTypeSeeder`.
  - `ClubSettingsController` (around line 85): a hall club cannot set `masonic_hall_id`. Ignore the field for halls, and add the test `test_hall_club_cannot_set_its_own_masonic_hall`.
  - `HallClubs::createFor`: in a transaction, lock the hall row, throw `\DomainException('This hall already has a board.')` if `club_id` is set, then create the club (`club_type_id` hall, `province_id` from the hall, `name` the hall name, unique slug made the way `LodgeClaimService::uniqueClubSlug` does it, which also checks `ReservedClubSlugs`, `status` `active`, `town_city` the hall's town). Attach the owner as `ClubRole::Owner` / `ClubMemberStatus::Active`, then set `masonic_halls.club_id`.

- [ ] **Step 4: Run the test again.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall club type linked to its masonic hall`

### Task 2: Board rows are not club membership

**Files:**
- Modify: `app/Models/User.php` (`activeClubIds`), `app/Support/MemberScope.php` (`for`), `app/Http/Middleware/HandleInertiaRequests.php` (the `clubs` list around line 104)
- Test: `tests/Feature/Halls/HallBoardIsNotMembershipTest.php`

**Interfaces:**
- Consumes: `Club::scopeNotHalls`, `ClubType::factory()->hall()`.
- Produces: `User::activeClubIds()` returns only non-hall clubs; `User::boardHallClubIds(): Collection<int,int>` (active rows on hall clubs); the shared `clubs` prop carries `'is_hall' => bool` on every club.

- [ ] **Step 1: Write the failing tests**
  - `test_hall_board_member_with_no_lodge_has_no_member_clubs`: `activeClubIds()` is empty, `MemberScope::for($user)->clubs()` is empty, and `GET /members/{hallSlug}` is a 404.
  - `test_board_member_of_a_hall_who_is_in_a_lodge_keeps_only_the_lodge`: `activeClubIds()` equals `[lodge id]`.
  - `test_network_content_is_not_shown_to_a_board_only_user`: an `Event` on another club with `visibility = network` fails `isVisibleTo($user)`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Add `whereHas('clubType', fn ($q) => $q->where('code', '!=', ClubType::HALL))` (through `notHalls`) to `activeClubIds()` and the `MemberScope::for` query. `boardHallClubIds()` is the inverse filter. Leave `EnsureUserCanAdministerClub` alone: it reads the pivot directly, so the board still gets the hall admin.
- [ ] **Step 4: Run** the new test plus `tests/Feature/AdminRouteSweepTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall board rows do not count as club membership`

### Task 3: Hall admin shape (hidden sections, route map, nav, sign-in landing)

**Files:**
- Modify: `config/club_permissions.php`, `app/Http/Middleware/EnsureUserCanAdministerClub.php`, `resources/js/Layouts/AdminLayout.vue`, `app/Http/Controllers/ClubController.php` (admin dashboard for a hall), and wherever a signed-in user with no member clubs is redirected (find with `grep -rn "members.dashboard\|/members'" app/Http/Responses app/Providers`)
- Test: `tests/Feature/Halls/HallAdminSectionsTest.php`, extend `tests/Feature/AdminRouteSweepTest.php`

**Interfaces:**
- Produces: config key `club_permissions.hall_hidden_sections` = `['members','users','candidates','meetings','officers','committee','visitors','subscriptions','dues-subscriptions','charity']`; `route_map` entries `'hall' => 'manage_hall'`, `'board' => 'manage_board'`, `'lodges' => 'view_dashboard'`; new capabilities `manage_hall` (default roles owner, admin, secretary) and `manage_board` (owner, admin).

- [ ] **Step 1: Write the failing tests**
  - `test_lodge_only_sections_are_404_for_a_hall`: as the hall owner, `GET /{hall}/admin/meetings` and `/{hall}/admin/users` return 404, and `/{hall}/admin/events` returns 200.
  - `test_board_only_user_lands_on_the_hall_admin_after_sign_in`: a user whose only active row is a hall board row is redirected to `/{hall}/admin` and not to an empty member area.
  - Add a hall club to the sweep so `hall`, `board` and `lodges` routes are requested as a lodge member, another club's admin and each staff role.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** In the middleware, after the club is loaded: `abort_if($club->isHall() && in_array($section, config('club_permissions.hall_hidden_sections'), true), 404)`, using the same first segment after `/admin` that the route map uses. It also applies to superadmins. In `AdminLayout.vue`, hide the Meetings and Members entries when the current club's `is_hall` is true, and show Hall, Board and Lodges entries instead.
- [ ] **Step 4: Run** the new tests and `AdminRouteSweepTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall admin hides lodge-only sections`

### Task 4: Hall details screen (shared rules, photos, live straight away)

**Files:**
- Create: `app/Http/Requests/MasonicHallDetailsRequest.php`, `app/Http/Controllers/HallDetailsController.php`, `resources/js/Pages/Admin/Hall/Details.vue`
- Modify: `app/Http/Controllers/SuperAdminHallController.php` (use the shared rules), `app/Models/MasonicHall.php` (`HasMedia`, collection `photos`), `routes/club.php`, the public hall page in `LodgeDirectoryController::hall` and its Vue page (show photos)
- Test: `tests/Feature/Halls/HallDetailsTest.php`

**Interfaces:**
- Produces: `MasonicHallDetailsRequest::detailRules(): array` (static; the rules now in `validateMasonicHall` minus `province_id`); routes `admin.hall.edit` (GET `/{clubSlug}/admin/hall`), `admin.hall.update` (PUT), `admin.hall.photos.store` (POST), `admin.hall.photos.destroy` (DELETE `/{clubSlug}/admin/hall/photos/{mediaId}`).

- [ ] **Step 1: Write the failing tests**
  - `test_secretary_updates_the_hall_and_the_directory_shows_it`: PUT `telephone`, then `GET /lodges/halls/{slug}` contains the new number.
  - `test_board_cannot_change_province_slug_or_source_urls`: posting `province_id`, `slug`, `ugle_url` and `source_url` leaves all four unchanged.
  - `test_changing_postcode_clears_coordinates` (keeps the existing `saving` rule).
  - `test_photo_upload_uses_upload_rules`: an SVG is refused, and a 3000px JPEG is stored at no more than 1920px.
  - `test_another_halls_board_cannot_edit_this_hall`: 403.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** The controller loads the hall with `$this->findClub($clubSlug)->hall()->firstOrFail()` and updates it with `$request->safe()->only(...)` of the detail fields. Photos go through `UploadRules` and `ImageDownscaler`, as other uploads do (see `PostAdminController` for the pattern). The superadmin controller keeps `province_id` and adds it to `detailRules()`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall board edits its hall's details`

### Task 5: The hall's lodges list

**Files:**
- Create: `app/Http/Controllers/HallLodgesController.php`, `resources/js/Pages/Admin/Hall/Lodges.vue`
- Modify: `app/Models/MasonicHall.php`, `routes/club.php`
- Test: `tests/Feature/Halls/HallLodgesTest.php`

**Interfaces:**
- Produces: `MasonicHall::memberClubIds(): Collection<int,int>` (ids of non-hall `clubs` with `masonic_hall_id` = this hall); route `admin.hall.lodges` (GET `/{clubSlug}/admin/lodges`).

- [ ] **Step 1: Write the failing tests**
  - `test_lists_directory_lodges_and_platform_clubs_at_the_hall_with_meeting_pattern`: two directory `Lodge`s (with `LodgeSchedule`) plus one `Club` at the hall are listed, and a lodge at another hall is not.
  - `test_the_hall_club_is_not_in_its_own_list`.
  - `test_list_has_no_links_into_lodge_admin`: the response props contain no `admin` URLs.
  - `test_query_count_does_not_grow_with_lodges` (count the queries with 2 lodges, then 6).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Merge the directory `Lodge`s (with `schedules`) and the platform `Club`s at this hall. Where a `Club` is linked to a directory `Lodge` (`ClubLodgeLink`), show it once, and the club's own `masonic_hall_id` decides whether it is listed.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall sees the lodges that meet there`

### Task 6: Hall events reach the members of its lodges

**Files:**
- Modify: `app/Enums/Visibility.php`, `app/Models/Concerns/HasVisibility.php`, `app/Models/User.php`, `app/Support/MemberScope.php`, `app/Services/MemberCalendar.php`, `app/Http/Controllers/MemberHomeController.php`, `app/Services/MemberFeed.php`, the event form's audience options (`EventAdminController` + its Vue page)
- Test: `tests/Feature/Halls/HallEventAudienceTest.php`

**Interfaces:**
- Consumes: `MasonicHall::memberClubIds()`, `User::activeClubIds()` (Task 2).
- Produces: `User::hallAudienceClubIds(): Collection<int,int>` (the hall club ids of the halls where the user's active clubs meet, in one query, memoised per request); `MemberScope::hallClubIds(): Collection<int,int>` (the same, limited to the scope's clubs).

- [ ] **Step 1: Write the failing tests**
  - `test_club_audience_hall_event_is_visible_to_members_of_lodges_at_the_hall`: both `isVisibleTo` and `Event::visibleTo($user)` include it.
  - `test_not_visible_to_members_of_a_lodge_at_another_hall`.
  - `test_lodge_moving_hall_moves_the_audience`: change the lodge's `masonic_hall_id`, and it is no longer visible.
  - `test_hall_event_shows_in_member_calendar_and_home`: `GET /members` and the calendar JSON include the hall event's title.
  - `test_member_can_book_a_club_audience_hall_event` through `EventRegistrationService::register` (or the existing booking route).
  - `test_event_form_labels_club_audience_for_a_hall`: the audience options prop includes `Members of lodges that meet here`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** In `Visibility::includes`, for `Club` use `activeClubIds()->contains($clubId) || hallAudienceClubIds()->contains($clubId)`. In `scopeVisibleTo`, the `Club` branch uses `whereIn($clubId, activeClubIds ∪ hallAudienceClubIds)`, and its "no member clubs" early return checks the union. Where the three member-area services build `$clubIds` for **events only**, add `$scope->hallClubIds()`. Meetings, posts and newsletters stay as they are.
- [ ] **Step 4: Run** the new test, `tests/Feature/*Event*` and `tests/Feature/*Member*Calendar*`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall events reach members of the lodges that meet there`

### Task 7: Hall organiser search only finds past bookers

**Files:**
- Modify: `app/Http/Controllers/EventOrganiserBookingController.php` (`memberSearch`), and `EventRegistrationService::saveByOrganiser` (refuse a `user_id` the hall may not book)
- Test: `tests/Feature/Halls/HallOrganiserSearchTest.php`

**Interfaces:**
- Produces: when `$club->isHall()`, `memberSearch` searches users with an `EventRegistration` on any event of this hall, and returns the same JSON shape with `rank`, `lodge` and `member_number` set to null.

- [ ] **Step 1: Write the failing tests**
  - `test_hall_search_finds_someone_who_booked_a_hall_event`.
  - `test_hall_search_does_not_find_a_lodge_member_who_never_booked`, even though their lodge meets at the hall.
  - `test_hall_organiser_cannot_book_an_unknown_user_id`: `saveByOrganiser` with that user's id fails validation, while booking a visitor by name and email works.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** the hall branch. Keep the existing LIKE escaping, the limit of 10 and the throttle.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall organisers only see their own bookers`

### Task 8: Hall newsletters to the members of its lodges

**Files:**
- Modify: `app/Services/Newsletters/NewsletterAudience.php`, the newsletter channel (`NewsletterType`) create/update validation, and the newsletter admin screen props (no recipient list for a hall)
- Test: `tests/Feature/Halls/HallNewsletterAudienceTest.php`

**Interfaces:**
- Consumes: `MasonicHall::memberClubIds()`.
- Produces: `NewsletterAudience::recipients()` returns, for a hall club, the active users of `memberClubIds()` (any role), minus users with an `unsubscribed` `NewsletterSubscription` on the hall club for that channel, plus that channel's active visitor subscriptions. The return shape is unchanged.

- [ ] **Step 1: Write the failing tests**
  - `test_hall_newsletter_reaches_active_members_of_lodges_at_the_hall` (pending and past members are not included, nor members of a lodge at another hall).
  - `test_board_rows_alone_do_not_make_someone_a_recipient`.
  - `test_unsubscribe_link_from_a_hall_newsletter_stops_the_next_one`: send, follow the unsubscribe URL from the delivery, send again, and that person is not a recipient.
  - `test_hall_channel_cannot_be_mandatory`: saving `is_mandatory = true` on a hall channel is a validation error.
  - `test_hall_newsletter_screen_shows_counts_not_recipients`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** the hall branch in `recipients()`. Before writing it, read how the unsubscribe route (`/webhooks/newsletters/unsubscribe/{token}`) and `NewsletterOptOut` record an opt-out for a member with no subscription row, and make sure the hall case reads the same record. Sending still goes only through `NewsletterSender`.
- [ ] **Step 4: Run** the new tests and `tests/Feature/*Newsletter*`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall newsletters reach members of its lodges`

### Task 9: Board screen

**Files:**
- Create: `app/Http/Controllers/HallBoardController.php`, `resources/js/Pages/Admin/Hall/Board.vue`
- Modify: `routes/club.php`
- Test: `tests/Feature/Halls/HallBoardTest.php`

**Interfaces:**
- Consumes: `MemberInvitationService::invite(Member $member, Club $club, ?User $actor, string $role)`, `MemberInvitationService::revoke`, `ClubAccess::canAssignRole`.
- Produces: routes `admin.board.index` (GET `/{clubSlug}/admin/board`), `admin.board.store` (POST: name, email, office, role), `admin.board.update` (PUT `/{clubSlug}/admin/board/{userId}`: office, role), `admin.board.destroy` (DELETE). The office title is stored in the existing `club_user.committee_role`. Constant `HallBoardController::OFFICE_ROLES = ['Chairman' => 'admin', 'Secretary' => 'secretary', 'Treasurer' => 'treasurer']` gives the role that is pre-selected for each office (other offices default to `secretary`). The role can still be changed.

- [ ] **Step 1: Write the failing tests**
  - `test_owner_invites_a_treasurer_who_gets_the_treasurer_role`: invitation queued, pivot role `treasurer`, `committee_role` `Treasurer`.
  - `test_admin_cannot_grant_owner` (`ClubAccess::canAssignRole`): 403.
  - `test_secretary_cannot_reach_the_board_screen` (`manage_board`): 403.
  - `test_removing_the_last_owner_is_refused`.
  - `test_treasurer_can_open_accounting_but_not_hall_details` (the permission matrix defaults from Task 3).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Invitations need a `Member` row as the carrier: create one on the hall club the way `UserAdminController::storeMember` does. The members screens are hidden for halls (Task 3), so it never shows as a roster. Look up users only through `$club->users()`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: hall board screen`

### Task 10: Superadmin sets up a hall board

**Files:**
- Modify: `app/Http/Controllers/SuperAdminHallController.php`, `routes/superadmin.php`, `resources/js/Pages/SuperAdmin/MasonicHalls*.vue` (the index page)
- Test: `tests/Feature/Halls/SuperAdminHallBoardTest.php`

**Interfaces:**
- Consumes: `HallClubs::createFor`, `MemberInvitationService::invite`.
- Produces: route `superadmin.masonic_halls.board` (POST `/superadmin/masonic-halls/{id}/board`: name, email). It creates the hall club if there is none, then invites that person as `owner`. The index rows gain `club_slug` (null when there is no board).

- [ ] **Step 1: Write the failing tests**
  - `test_superadmin_creates_board_and_invites_owner`.
  - `test_second_call_invites_into_the_same_club_and_creates_no_new_one`.
  - `test_request_is_in_the_superadmin_audit_log` (`superadmin_actions` row).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: superadmin can set up a hall board`

### Task 11: Claiming a hall from the directory

**Files:**
- Create: `database/migrations/2026_11_21_100002_create_hall_claims_table.php`, `app/Models/HallClaim.php` (+ factory), `app/Services/Halls/HallClaimService.php`, `app/Http/Controllers/HallClaimController.php`, `app/Notifications/HallClaimNotification.php`, `resources/js/Pages/Lodges/HallClaim.vue`
- Modify: `routes/club.php` (next to the lodge-claim routes), `routes/superadmin.php`, `app/Http/Controllers/SuperAdminLodgeClaimController.php` and its page (list hall claims in a second section), the public hall page (a "Run this hall?" link)
- Test: `tests/Feature/Halls/HallClaimTest.php`

**Interfaces:**
- Consumes: `HallClubs::createFor`.
- Produces: `hall_claims` (`masonic_hall_id`, `user_id`, `status` `pending|info_requested|approved|rejected|withdrawn`, `claimant_role`, `message`, `phone`, `question`, `reply`, `decided_by`, `decided_at`, `decision_note`); `HallClaimService::submit(MasonicHall, User, array): HallClaim`, `requestInfo(HallClaim, User $admin, string)`, `respond(HallClaim, User, string)`, `withdraw(HallClaim, User)`, `reject(HallClaim, User $admin, string)`, `approve(HallClaim, User $admin, ?string $note): Club`; routes `lodges.hall.claim` (GET/POST `/lodges/halls/{slug}/claim`, POST throttled `6,1`), `superadmin.hall_claims.{approve,reject,request_info}`.

- [ ] **Step 1: Write the failing tests**
  - `test_signed_in_user_claims_a_hall_and_superadmin_approves`: a hall club exists, the claimant is `owner`, and a notification is sent.
  - `test_cannot_claim_a_hall_that_already_has_a_board`: a validation error.
  - `test_one_pending_claim_per_user_per_hall`.
  - `test_approving_when_a_board_appeared_meanwhile_fails_cleanly` (catch `HallClubs`' `\DomainException`, and the claim stays pending).
  - `test_request_info_then_respond_then_reject`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, following `LodgeClaimService` for the transaction and locking pattern, the most-waiting-claims limit (`LodgeClaim::MAX_PENDING`-style constant) and the notifications.
- [ ] **Step 4: Run** the new test and `tests/Feature/*LodgeClaim*`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: claim a hall from the directory`

### Task 12: Full check

- [ ] Run `composer check`, `npm run lint`, `npm run check:templates`, `npm run check:components` and `npm run check:design`. Expected: all pass, with no new PHPStan baseline entries.
- [ ] Open the hall admin in the browser pane as a hall owner (Details, Board, Lodges, Events, Accounting) and as a lodge member at that hall (member home shows the hall event). Open the hall's public site at `/site/{hall}` and check it shows no lodge-only parts (meetings, summonses, members' area links). Anything that does is hidden with `isHall()` in the same change. Take screenshots for the record.
