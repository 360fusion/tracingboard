# Province Groups and Order Sections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A province gets a group club (for example "Durham"). Inside it are order sections (for example "Rose Croix in Durham"), each its own club with its own admins, editors, website, news, events, newsletters, accounts, officers list and chapters list.

**Architecture:** Halls are already clubs with no members of their own, whose audience is the members of other clubs. Generalise that one idea, which is split today across about 30 `isHall()` checks, into a "group" (`Club::isGroup()`: a hall, province group or province section). Give it a single audience rule (`GroupAudience`: a hall reaches the clubs that meet there; a section reaches the clubs of its order in its province). The new club types then get events, newsletters, member-area reach and "board is not membership" for free. The section-only extras (chapters list, listings, officers, circulars) are small screens on top.

**Tech Stack:** Laravel 13, Inertia v3 + Vue 3, Tailwind v4, PHPUnit on in-memory SQLite, PostgreSQL-first migrations.

**Spec:** `docs/superpowers/specs/2026-11-21-province-groups-design.md`. The hall precedent is `docs/superpowers/specs/2026-11-21-hall-board-design.md`.

## Global Constraints

- Everything in CLAUDE.md: `$this->findClub()`, `ClubAccess`, `config/club_permissions.php` `route_map` for every new admin section, `AdminRouteSweepTest` must pass, `Club::factory()` / `ClubType::factory()` in tests, no per-item queries, `Currencies` for money, PostgreSQL-first migrations, `ReservedClubSlugs` for new slugs, `vendor/bin/pint --dirty --format agent`, `composer analyse` adds no baseline entries, `npm run check:templates` and `npm run check:design` after Vue changes.
- Club type codes: `province_group`, `province_section`.
- A section covers exactly one order (`ClubType` of a lodge or chapter) in exactly one province: the group's `province_id`. At most one group per province, and at most one section per order per group.
- Group and section roles give no access to any lodge's or chapter's private data or admin. A `club_user` row on a group or section is never membership.
- Members-only (`Visibility::Club`) on a section means active members of platform clubs with the section's `province_id` and order `club_type_id`. On a section, the form label reads "Members of {order plural} in {province}", for example "Members of Rose Croix chapters in Durham".
- Section channels can never be mandatory. Members of the section's chapters are signed up and can opt out.
- Section editors are the existing `coach` role, shown as "Editor" on sections and groups, as halls show it as "Secretary". Editors hold every section capability except `manage_board`.
- Sections have the accounting module. Groups don't, and have no events, newsletters or pass-on.

## Review Focus

1. A Rose Croix member in Northumberland, a Mark member in Durham, or a pending Rose Croix member in Durham sees a Durham Rose Croix members-only event or post. Expected: none of them see it. (Task 2)
2. Someone on a section's or group's people list who belongs to no lodge opens `/members`. Expected: no member area for the section, and the section is not in `activeClubIds()`, Network visibility or the club switcher as a member club. (Task 2)
3. A section editor opens another section of the same group, the group's own admin, or a chapter's admin. Expected: 403 each time. (Task 3)
4. A chapter that is both a directory `Lodge` and a platform `Club` shows once in the chapters list, and a managed chapter's listing cannot be edited from the section. (Tasks 6, 7)
5. A section is added twice for the same order, or a second group for the same province. Expected: a validation error, not a second row. (Tasks 4, 5)

---

### Task 1: Club types, the group/section link and `isGroup()`

**Files:**
- Create: `database/migrations/2026_11_22_100000_add_province_group_club_types.php`
- Modify: `app/Models/ClubType.php`, `app/Models/Club.php`, `database/factories/ClubFactory.php`
- Modify (rename `notHalls` → `notGroups`): `app/Models/User.php`, `app/Models/MasonicHall.php`, `app/Support/MemberScope.php`, `app/Http/Controllers/{MemberSubscriptionsController,MemberHomeController,HallLodgesController,Auth/RegisterController,AnalyticsController,MemberLodgesController}.php`
- Test: `tests/Feature/Provinces/ProvinceClubTypesTest.php`

**Interfaces:**
- Produces: `ClubType::PROVINCE_GROUP = 'province_group'`, `ClubType::PROVINCE_SECTION = 'province_section'`, `ClubType::GROUP_CODES = [HALL, PROVINCE_GROUP, PROVINCE_SECTION]`, `ClubType::provinceGroup(): self`, `ClubType::provinceSection(): self` (both `firstOrCreate`, like `hall()`), `ClubType::scopeOrders(Builder): Builder` (types a lodge or chapter can be: every code not in `GROUP_CODES`)
- Produces: `clubs.parent_club_id` (nullable FK to clubs, `nullOnDelete`), `clubs.order_club_type_id` (nullable FK to club_types), unique index `(parent_club_id, order_club_type_id)`
- Produces on `Club`: `isProvinceGroup(): bool`, `isProvinceSection(): bool`, `isGroup(): bool`, `parentClub(): BelongsTo<Club>`, `sections(): HasMany<Club>`, `orderClubType(): BelongsTo<ClubType>`, `scopeNotGroups(Builder): Builder` (replaces `scopeNotHalls`, excluding every code in `GROUP_CODES`)
- Produces: `ClubFactory` states `provinceGroup(Province $province)` and `provinceSection(Club $group, ClubType $order)`

- [ ] **Step 1: Write the failing tests:** `test_group_types_are_not_orders` (asserts `ClubType::orders()->pluck('code')` lacks `hall`, `province_group` and `province_section` but has `rose_croix`), `test_not_groups_leaves_out_halls_groups_and_sections` (a lodge, hall, group and section exist; `Club::notGroups()->pluck('id')` is the lodge only), `test_section_belongs_to_group_and_order` (asserts `$section->parentClub->is($group)`, `$section->orderClubType->code === 'rose_croix'`, `$group->sections->contains($section)`, `$section->isGroup()`, `$section->isProvinceSection()`).
- [ ] **Step 2: Run them:** `php artisan test --compact tests/Feature/Provinces/ProvinceClubTypesTest.php`. Expected: FAIL.
- [ ] **Step 3: Migration:** insert both club types if missing, like `2026_11_21_100001_add_hall_club_type.php`.
  - `province_group`: modules `['communications', 'website_builder']`, terminology `['club' => 'Province', 'members' => 'People']`.
  - `province_section`: modules `['accounting', 'communications', 'events', 'newsletters', 'website_builder']`, terminology `['club' => 'Section', 'members' => 'People']`.
  - Colour from `OrderColours::for()`; add both codes to `app/Support/OrderColours.php` (`slate` for the group, `violet` for the section).
  - Add the two columns and the unique index.
- [ ] **Step 4: Implement the `ClubType` and `Club` members above.** Then replace every `notHalls()` call with `notGroups()` (run `grep -rn notHalls app` and expect no matches afterwards).
- [ ] **Step 5: Run the new tests and `tests/Feature/Halls`:** `php artisan test --compact tests/Feature/Provinces tests/Feature/Halls`. Expected: PASS.
- [ ] **Step 6: Commit:** `feat: province group and section club types`.

### Task 2: One audience rule for halls and sections

**Files:**
- Create: `app/Support/GroupAudience.php`
- Modify: `app/Models/User.php` (`hallAudienceClubIds` → `groupAudienceClubIds`), `app/Models/MasonicHall.php` (`hallClubsFor` moves into `GroupAudience`), `app/Support/MemberScope.php` (`hallClubs`/`hallClubIds` → `groupClubs`/`groupClubIds`; `forEvents` uses `isGroup()`), `app/Services/MemberFeed.php`, `app/Services/MemberCalendar.php`, `app/Http/Controllers/MemberHomeController.php`, `app/Http/Controllers/MemberListController.php`, `app/Models/Concerns/HasVisibility.php`, `app/Enums/Visibility.php`, `app/Http/Middleware/HallHasNoMemberArea.php` (rename to `GroupHasNoMemberArea`), `app/Services/Newsletters/{NewsletterAudience,NewsletterOptOut}.php`, `app/Services/Events/EventRegistrationService.php` (`hallBookers` → `groupBookers`), `app/Http/Controllers/{EventOrganiserBookingController,MemberPortalController,MemberSubscriptionsController,NewsletterTypeAdminController,NewsletterAdminController,EventTicketController,Auth/InvitationController}.php`, `app/Http/Requests/{NewsletterAdmin/StoreNewsletterRequest,NewsletterTypeAdmin/StoreNewsletterTypeRequest}.php`, `app/Services/WeeklyUpdateDigestService.php`, `app/Services/AnnualTreasurerReportService.php`, `app/Services/AccountingReports.php`, `app/Domains/ClubAccounting/Livewire/Banking/BankReconciliationWorkspace.php`, `app/Http/Controllers/AnnualReturnOfMembersController.php`
- Test: `tests/Feature/Provinces/SectionAudienceTest.php`

**Interfaces:**
- Produces: `GroupAudience::memberClubIds(Club $group): Collection<int,int>`: for a hall, `$group->hall->memberClubIds()`; for a section, `Club::notGroups()` where `province_id = $group->province_id` and `club_type_id = $group->order_club_type_id`; empty otherwise.
- Produces: `GroupAudience::groupClubsFor(Collection<int,Club> $clubs): Collection<int,Club>`: the hall and section clubs whose audience includes any of `$clubs`. It makes one query for halls (today's `hallClubsFor` body) and one for sections (match on `(province_id, club_type_id)` pairs of `$clubs`).
- Produces: `GroupAudience::audienceLabel(Club $group): string`: "Members of lodges that meet here" for a hall; "Members of {order name} {chapters|lodges|…} in {province name}" for a section. Use the word from `Lodge::displayNameFor`'s mapping, pluralised.
- Produces: `User::groupAudienceClubIds(): Collection<int,int>`, `MemberScope::groupClubs()`, `MemberScope::groupClubIds()`, `EventRegistrationService::groupBookers(Club $group): Builder`.

The rule for every `isHall()` call site: when it means "this club has no members of its own and reaches other clubs' members", it becomes `isGroup()`. When it means the hall's building or details (`HallDetailsController`, `ClubSettingsController` `masonic_hall_id`, `Analytics` hall tile, `PassOnController` hall lists, `HallClubs::landingUrl`), it stays `isHall()`. `HandleInertiaRequests` gets `clubIsGroup` and `is_group` beside the hall flags.

- [ ] **Step 1: Write the failing tests.** Use a Durham group, a Rose Croix section, Rose Croix chapter clubs in Durham and Northumberland, and a Mark lodge in Durham, each with one active member, plus a pending member of the Durham Rose Croix chapter.
  - `test_members_only_event_reaches_only_the_orders_chapters_in_the_province`: only the Durham Rose Croix member gets 200 on `member.events` for the section, and the event shows in their `/members` calendar feed. The others get 404, and the event is absent from their feeds.
  - `test_members_only_post_reaches_the_member_feed`: `MemberFeed::items()` for the Durham Rose Croix member includes the section's `Visibility::Club` post; for the Mark member it doesn't. `member.posts.show` returns 200 and 404 respectively.
  - `test_section_newsletter_recipients_are_chapter_members_less_opt_outs`: `NewsletterAudience::recipients()` is exactly the Durham Rose Croix active members. After one opts out through `NewsletterSubscription` `unsubscribed`, they are gone.
  - `test_section_people_are_not_members`: a user who is only an `admin` on the section has an empty `activeClubIds()`, gets 404 on `/members/{section}/dashboard`, and `Visibility::Network->includes($user, …)` is false.
  - `test_section_channel_cannot_be_mandatory`: posting `is_mandatory=1` to the newsletter type store route for the section gives a validation error on `is_mandatory`.
  - `test_visibility_label_names_the_order_and_province`: `Visibility::options($section)` has the Club label "Members of Rose Croix chapters in Durham".
- [ ] **Step 2: Run them:** `php artisan test --compact tests/Feature/Provinces/SectionAudienceTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement `GroupAudience` and the renames, then walk every `isHall()` hit** (`grep -rn "isHall()" app`) with the rule above.
- [ ] **Step 4: Run the provinces and halls tests:** `php artisan test --compact tests/Feature/Provinces tests/Feature/Halls`. Expected: PASS (the hall tests prove nothing regressed).
- [ ] **Step 5: Commit:** `feat: one audience rule for halls and province sections`.

### Task 3: Admin sections and permissions for groups and sections

**Files:**
- Modify: `config/club_permissions.php`, `app/Http/Middleware/EnsureUserCanAdministerClub.php`, `app/Http/Controllers/ClubSettingsController.php`, `app/Services/ClubSettingsScreenData.php`, `app/Http/Controllers/HallBoardController.php` (`abort_unless($club->isGroup())`; role labels), `resources/js/Pages/Admin/Hall/Board.vue` (wording by club type), the admin nav component that reads `clubIsHall`
- Test: `tests/Feature/Provinces/GroupAdminSectionsTest.php`, plus `tests/Feature/AdminRouteSweepTest.php` must stay green

**Interfaces:**
- Produces: `config('club_permissions.group_hidden_sections')` keyed by type code. `hall` gets today's list. `province_section` gets the same list. `province_group` gets that list plus `events`, `newsletters`, `accounting`, `pass-on`. `EnsureUserCanAdministerClub` reads `[$club->clubType->code] ?? null` when `$club->isGroup()`.
- Produces: `group_hidden_capabilities` keyed by type code in the same way (replaces `hall_hidden_capabilities`). `lodge_hidden_capabilities` adds `manage_sections` and `manage_provincial_officers`.
- Produces: new capabilities `manage_sections` (roles owner and admin; label "Order sections") and `manage_provincial_officers` (roles owner, admin and coach; label "Provincial officers"). New `route_map` keys: `sections` → `manage_sections`, `provincial-officers` → `manage_provincial_officers`, `chapters` → `manage_events`, `circulars` → `manage_events`.
- Produces: `HallBoardController::roleLabels(Club $club): array<string,string>`: on a hall `coach` is "Secretary"; on a group or section `coach` is "Editor".

- [ ] **Step 1: Write the failing tests:**
  - `test_section_editor_cannot_manage_people`: a `coach` on the section gets 403 posting to `admin.board.store`. An `admin` gets a redirect with `success`.
  - `test_section_role_does_not_reach_sibling_section_or_group`: a section admin gets 403 on the sibling section's `admin.analytics` and on the group's `admin.analytics`.
  - `test_group_has_no_events_or_accounts`: a group owner gets 404 on `/{group}/admin/events` and `/{group}/admin/accounting`.
  - `test_board_screen_calls_coach_editor_on_a_section`: the Inertia prop `roles` labels `coach` as "Editor".
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the config and middleware changes.** Use `ClubFactory` states for groups and sections in the `AdminRouteSweepTest` fixtures only if the sweep needs them to reach the new routes.
- [ ] **Step 4: Run them, then `php artisan test --compact tests/Feature/AdminRouteSweepTest.php tests/Feature/Halls`.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: admin sections and permissions for province groups and sections`.

### Task 4: Superadmin creates a province group

**Files:**
- Create: `app/Services/Provinces/ProvinceGroups.php`
- Modify: `app/Http/Controllers/SuperAdminProvinceController.php` (new `setUpGroup` action), `routes/superadmin.php` (`POST /superadmin/provinces/{id}/group`, name `superadmin.provinces.group`), `resources/js/Pages/SuperAdmin/Provinces/Show.vue` (a "Province group" panel: create, or a link to it, plus an invite form)
- Test: `tests/Feature/Provinces/SuperAdminProvinceGroupTest.php`

**Interfaces:**
- Produces: `ProvinceGroups::createGroup(Province $province): Club` (throws `DomainException` "This province already has a group." if one exists; checked under `lockForUpdate` on the province row). Slug from `Str::slug($province->name)`, made unique against `ReservedClubSlugs` and existing clubs, like `HallClubs::uniqueSlug`. `province_id` is set and `status` is `active`.
- Produces: `ProvinceGroups::groupFor(Province $province): ?Club`
- Consumes: `HallBoard::invite(Club, name, email, office, role, actor)` to invite the first owner (office "Provincial Secretary", role `owner`).

- [ ] **Step 1: Write the failing tests:**
  - `test_superadmin_creates_group_and_invites_owner`: POST with name and email creates one `province_group` club with the province's id, and the invitee is a pending `owner`.
  - `test_second_group_for_the_same_province_is_refused`: the second POST redirects with `error`, and the count stays 1.
  - `test_non_superadmin_cannot_create_a_group`: 403.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the service, action, route and panel.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: superadmin sets up a province group`.

### Task 5: Group admins add sections and appoint their admins

**Files:**
- Create: `app/Http/Controllers/ProvinceSectionsController.php`, `resources/js/Pages/Admin/Province/Sections.vue`
- Modify: `app/Services/Provinces/ProvinceGroups.php`, `routes/club.php` (`GET|POST /{clubSlug}/admin/sections`, `POST /{clubSlug}/admin/sections/{sectionId}/admins`, `DELETE /{clubSlug}/admin/sections/{sectionId}`; names `admin.sections.index|store|admins.store|destroy`)
- Test: `tests/Feature/Provinces/ProvinceSectionsTest.php`

**Interfaces:**
- Produces: `ProvinceGroups::createSection(Club $group, ClubType $order, string $name): Club`. Throws `DomainException` "This province already has a {order} section." on a duplicate. The section gets `parent_club_id`, `order_club_type_id`, the group's `province_id`, `club_type_id` = `province_section`, and a slug from `Str::slug($group->slug.' '.$order->name)` made unique.
- Produces: `ProvinceGroups::removeSection(Club $section): void`. Throws `DomainException` "This section has upcoming events." when any `Event` of the section has `status` upcoming and `starts_at >= now()`. Otherwise it sets `status` to `inactive` and clears `parent_club_id`. Nothing is deleted, so financial rows stay.
- Every action does `abort_unless($group->isProvinceGroup(), 404)` and looks the section up with `$group->sections()->findOrFail($sectionId)`.
- Appointing an admin uses `HallBoard::invite($section, name, email, 'Section admin', 'admin', actor)`. A group admin may do this without a row on the section.

- [ ] **Step 1: Write the failing tests:**
  - `test_group_admin_adds_rose_croix_section`: POST `order_club_type_id` (rose_croix) and `name` "Rose Croix in Durham" creates the section with the correct links.
  - `test_order_picker_lists_orders_not_yet_used`: the Inertia prop `orders` lacks `hall`, the group types and any order that already has a section.
  - `test_duplicate_section_is_refused`: a validation error, and the count stays 1.
  - `test_group_admin_appoints_section_admin`: the invitee becomes a pending `admin` on the section, not on the group.
  - `test_section_id_from_another_group_is_not_found`: 404.
  - `test_section_with_upcoming_event_cannot_be_removed`: redirect with `error`, and the section stays active.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the controller, page and routes.** The page lists sections with their admin names, a link to each section's admin, an add form and an appoint form, using components from `resources/js/Components/Ui`.
- [ ] **Step 4: Run them, then `tests/Feature/AdminRouteSweepTest.php`.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: province group admins add order sections and appoint their admins`.

### Task 6: A section's chapters list (admin and public), and the group landing page

**Files:**
- Create: `app/Services/Provinces/SectionChapters.php`, `app/Http/Controllers/SectionChaptersController.php`, `resources/js/Pages/Admin/Province/Chapters.vue`, `resources/js/Pages/Public/Site/Chapters.vue`
- Modify: `routes/club.php` (`GET /{clubSlug}/admin/chapters`, `admin.chapters.index`), `routes/public.php` (`GET /site/{clubSlug}/chapters`, `public.site.chapters`; `GET /site/{clubSlug}/{orderSlug}` redirects to the section's site when `clubSlug` is a group, registered so it does not shadow existing site pages: only orders the group has), `app/Http/Controllers/PublicSiteController.php` (a group's home payload gets `sections: [{name, url, order}]`), the public site home component (shows the sections list when present), `app/Services/StaticSite/StaticSiteBuilder.php` (add the chapters page to the built pages)
- Test: `tests/Feature/Provinces/SectionChaptersTest.php`

**Interfaces:**
- Produces: `SectionChapters::for(Club $section): Collection<int, array{name: string, number: ?string, on_platform: bool, site_url: ?string, hall: ?string, town: ?string, meets: ?string}>`. Two queries:
  - directory `Lodge::listed()` with that province and type, eager-loading `masonicHall` and `schedules`
  - `Club::notGroups()` with that province and type that have no directory row

  A `Lodge` with `club_id` is on the platform, and its `site_url` is `/site/{club slug}` through `ClubDomain::publicUrl()`. Sort by name. `meets` comes from the same `LodgeSchedule` summary `HallLodgesController` uses.

- [ ] **Step 1: Write the failing tests:**
  - `test_chapters_are_the_orders_lodges_in_the_province`: Durham Rose Croix rows appear; a Northumberland Rose Croix row and a Durham Mark row don't.
  - `test_managed_chapter_appears_once`: a `Lodge` with `club_id` and its `Club` give one row with `on_platform` true.
  - `test_chapters_list_makes_two_queries_whatever_the_count`: the query count with 2 chapters equals the count with 6.
  - `test_public_chapters_page_renders_for_a_visitor`: 200 on `public.site.chapters` with the rows.
  - `test_group_site_order_address_redirects_to_section`: `/site/durham/rose-croix` redirects to `/site/{section slug}`, and an order the group lacks gives 404.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the service, controllers, routes and pages.**
- [ ] **Step 4: Run them, then `npm run check:templates`.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: a province section's chapters list and the group's order links`.

### Task 7: Section editors look after their chapters' directory listings and suggested fixes

**Files:**
- Create: `app/Http/Controllers/SectionLodgeController.php` (extends `ProvinceLodgeController`), `app/Http/Controllers/SectionLodgeCorrectionController.php` (extends `ProvinceLodgeCorrectionController`)
- Modify: `routes/club.php` (`/{clubSlug}/admin/chapters/listings` GET/POST/PUT and `/{clubSlug}/admin/chapters/fixes` GET plus `{id}/apply`, `{id}/reject`, named `admin.chapters.listings.*` and `admin.chapters.fixes.*`), the two parent controllers only where `scope()`, `provinces()` and `screen()` need to become overridable per request
- Test: `tests/Feature/Provinces/SectionListingsTest.php`

**Interfaces:**
- `SectionLodgeController::middleware()` replaces the province-admin check: the URL club must be a section and the user must pass `ClubAccess::can($user, $section, 'manage_events')`, giving 403 before validation (see CLAUDE.md on authorization order).
- `scope()` is `Lodge::where('province_id', $section->province_id)->where('club_type_id', $section->order_club_type_id)`. `provinces()` is that one province. `screen()` returns `['routes' => 'admin.chapters.listings', 'layout' => 'admin', 'full' => false]`.
- The correction subclass is limited the same way, and also to unmanaged lodges, as the parent already does.

- [ ] **Step 1: Write the failing tests:**
  - `test_editor_edits_a_durham_rose_croix_listing`: PUT updates the `Lodge`.
  - `test_editor_cannot_edit_a_mark_lodge_in_durham`: 404.
  - `test_managed_chapter_listing_is_refused`: 403 with the parent's message.
  - `test_editor_applies_a_suggested_fix_for_their_chapter`: the fix is applied, and one for a Mark lodge gives 404.
  - `test_lodge_member_without_section_role_gets_403`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the subclasses and routes.** Link to both screens from `Chapters.vue`.
- [ ] **Step 4: Run them, plus the existing province-admin tests:** `php artisan test --compact --filter=Province`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat: section editors keep their chapters' listings and fixes`.

### Task 8: Provincial officers list

**Files:**
- Create: migration `create_provincial_officers_table`, `app/Models/ProvincialOfficer.php` (+ factory), `app/Http/Controllers/ProvincialOfficerController.php`, `resources/js/Pages/Admin/Province/Officers.vue`, `resources/js/Pages/Public/Site/Officers.vue`
- Modify: `routes/club.php` (`/{clubSlug}/admin/provincial-officers` index, store, update, destroy and `reorder`), `routes/public.php` (`GET /site/{clubSlug}/officers`, `public.site.officers`, only for a section and only when the section has officers), `app/Services/StaticSite/StaticSiteBuilder.php`
- Test: `tests/Feature/Provinces/ProvincialOfficersTest.php`

**Interfaces:**
- Table `provincial_officers`: `id`, `club_id` (FK, cascade), `office` string(150), `name` string(150), `rank` nullable string(150), `position` unsigned int, timestamps. Rows are always looked up with `where('club_id', $club->id)`.

- [ ] **Step 1: Write the failing tests:**
  - `test_editor_adds_and_orders_officers`: two stores and a reorder, then the public page lists them in order with office, rank and name.
  - `test_officer_of_another_section_cannot_be_edited`: 404.
  - `test_lodge_has_no_provincial_officers_section`: 404 on the admin route for a lodge.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the migration, model, controller, routes and pages.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: a province section's officers list`.

### Task 9: Circulars from a section for its chapters to pass on

**Files:**
- Create: migration `create_section_circulars_table`, `app/Models/SectionCircular.php`, `app/Http/Controllers/SectionCircularController.php`, `resources/js/Pages/Admin/Province/Circulars.vue`, `app/Notifications/SectionCircularNotification.php`
- Modify: `routes/club.php` (`/{clubSlug}/admin/circulars` index and store), `app/Http/Controllers/PassOnController.php` (`index` adds `circulars`: circulars from sections whose `GroupAudience::memberClubIds` contains this club, from the last 90 days), `resources/js/Pages/Member/PassOn/Index.vue` (a "From the Province" list; "Pass this on" fills the existing form with kind `provincial`, the title, the body and the attachment)
- Test: `tests/Feature/Provinces/SectionCircularsTest.php`

**Interfaces:**
- Table `section_circulars`: `id`, `club_id` (the section), `sent_by` (user), `title`, `body` (sanitised with `RichTextSanitizer`), `attachment_path`/`attachment_name` on the private `local` disk (validated with `UploadRules`, PDF only, as pass-on attachments are), timestamps.
- When a circular is stored, `SectionCircularNotification` (queued, `transactional` queue) goes to each chapter's users for whom `PassOnController::canSend($user, $chapterClub)` is true. That is one query for those clubs' staff and Secretary office holders, not one per chapter.
- Passing it on creates an ordinary `PassedOnItem` through the existing `store`. The section never creates `PassedOnItem`s or emails chapter members itself.
- The attachment is downloaded through `GET /members/{slug}/pass-on/circulars/{id}/attachment`, only by a user for whom `canSend` holds in a chapter the circular reaches.

- [ ] **Step 1: Write the failing tests:**
  - `test_circular_notifies_chapter_secretaries_only`: `Notification::assertSentTo` for the Durham Rose Croix chapter's Secretary, `assertNotSentTo` for the Mark lodge's Secretary and for an ordinary member.
  - `test_circular_shows_on_the_chapters_pass_on_screen`: the Inertia prop `circulars` has it for the Rose Croix chapter and not for the Mark lodge.
  - `test_circular_attachment_is_refused_outside_its_chapters`: 404 for the Mark Secretary.
  - `test_circular_sends_no_member_email`: `Mail::assertNothingQueued()` and no `PassedOnItem` rows after the store.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run them, plus `--filter=PassOn`.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat: a province section's circulars for its chapters to pass on`.

### Task 10: Dashboards, landing, wording and a full check

**Files:**
- Modify: `app/Http/Controllers/AnalyticsController.php` (group: sections with admin names; section: upcoming events, chapters count, open fixes, last newsletter), `resources/js/Pages/Admin/Analytics.vue`, `app/Services/Halls/HallClubs.php` (`landingUrl` for a group goes to `admin.sections.index`, and for a section to `admin.analytics`), the admin nav (hide lodge-only items by club type; show Sections, Chapters, Provincial officers, Circulars), `app/Services/DefaultSitePages.php` (a section's default pages link to Chapters and Officers)
- Test: `tests/Feature/Provinces/ProvinceDashboardTest.php`

- [ ] **Step 1: Write the failing tests:**
  - `test_group_dashboard_lists_sections`: the Inertia prop `sections` has each section's name.
  - `test_section_dashboard_counts`: `chapters_count`, `upcoming_events` and `open_fixes` match the fixtures.
  - `test_section_person_lands_on_section_admin_after_invitation`: accepting the invitation redirects to the section's `admin.analytics`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement it.** Then open the group and the section in the browser pane, signed in as the demo superadmin, and check the nav, dashboards, a members-only event and the public chapters and officers pages.
- [ ] **Step 4: Run everything:** `composer check`, `npm run lint`, `npm run check:templates`, `npm run check:components`, `npm run check:design`. Expected: all pass, with the baseline counts unchanged or lower.
- [ ] **Step 5: Commit:** `feat: province group and section dashboards and navigation`.
