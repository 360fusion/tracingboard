# Hall Board: design

## Intent

A masonic hall is the building where lodges and chapters meet. Today a hall is only a directory record (`MasonicHall`) that the superadmin edits. Halls are run by a board (Chairman, Secretary, Treasurer and others) who keep the building's details correct, put on events, run the hall's website and keep its accounts. The Hall Board login gives that board its own admin area.

The first job a board member will do is fix the hall's details.

## Goals

- A hall board can sign in and edit its hall's details (address, contact details, website, photos, directory listing). Changes go live on the public directory page straight away.
- Each office decides what its holder can do (for example, the Treasurer handles money and the Secretary handles details and events).
- The hall puts on events with the same bookings, guests, meals, ticket types and card/PayPal payments that lodges have, paid through the hall's own payment options.
- The hall has a website from the existing website builder (`/site/{slug}`, own domain, static publish).
- The hall keeps full accounts (ledger, bank, reconciliation, reports), like a lodge Treasurer.
- The hall sees its lodges and chapters: every `Club` and directory `Lodge` whose `masonic_hall_id` is this hall, with when they meet. No step is needed from the hall or the lodge.

## Non-goals

- Halls have no members, meetings, summonses, subscriptions, candidates, signing-in book or Relief Chest/festival giving.
- Room bookings and a hall diary for lodges are not part of this.
- Lodges don't have to accept being linked to a hall. The link is the hall set in the directory or the lodge's settings.

## Anti-goals (a failure even if it works)

- A hall board role gives any access at all to a lodge or chapter that meets there. Someone manages a lodge only if they are an admin of that lodge's own club.
- Hall board members show up as lodge members, or are counted in lodge rosters, newsletters or member lists.
- Copies of events, accounts or the website builder are made just for halls.

## The decided shape

**A hall is a club of a new "Hall" club type, linked to its `MasonicHall` record.** (The user agreed to this.)

- New `ClubType` with code `hall`. Its `available_modules` leave out members, meetings, subscriptions, candidates, committee and charity, and keep events, newsletters, website and accounting. Terminology says "hall" and "board" where the club type allows it.
- The hall club points at its hall record. `clubs.masonic_hall_id` already means "the hall this lodge meets in", so the builder decides whether to reuse that column for a hall club (the club *is* that hall) or add a separate link, such as `masonic_halls.club_id`. Whichever is chosen, there must be at most one hall club per `MasonicHall`, and "the hall's lodges" must not include the hall club itself.
- Board members are `club_user` rows on the hall club. Offices map to the existing `ClubRole` cases, and the hall's `settings.permission_matrix` decides what each can do. Suggested defaults (my call): Chairman → `admin`, Secretary → `secretary`, Treasurer → `treasurer`, the person who claimed or was invited first → `owner`. Office titles shown next to names use the existing `OfficerRole` / `OfficerAssignment` per club.
- `config/club_permissions.php` `route_map` gets any new admin section (for example `hall` for the details screen).

**Becoming a board member: both routes.** (The user decided this.)
- *Claim:* from the hall's public directory page (`/lodges/halls/{slug}`), a signed-in user asks to run the hall, and the superadmin approves. This follows the `LodgeClaimService` flow (submit, request info, respond, withdraw, reject, approve with a new club). Approving creates the hall club and makes the claimant `owner`.
- *Invite:* the superadmin creates the hall club from the superadmin halls screen and invites people by email. After that, the hall's owner adds more board members through the hall's admin, the same way lodges add admins.

**Hall details screen.** In the hall's admin area, the board edits the `MasonicHall` fields that `SuperAdminHallController::validateMasonicHall` validates today, plus photos. The same validation is shared by both screens. Saving updates the directory straight away, and the existing postcode-change rule still clears the coordinates for `halls:geocode`. The superadmin screen keeps working as a fallback. The board cannot change `province_id`, the slug, `ugle_url` or `source_url` (my call: those come from the directory scrape).

**Events: public or "for the hall's lodges", chosen per event.** (The user decided this.)
- *Public:* the same as a lodge's public event (hall website, anyone can book).
- *For the hall's lodges:* for a hall club, the `Visibility::Club` audience means "active members of any club whose `masonic_hall_id` is this hall" instead of "members of this club". `Visibility::includes` and every query that lists club-audience events for a member (member area, calendar, `MemberScope`-based lists, `EventPayload`, `EventRegistrationService` audience checks) must respect this, so members of those lodges see the hall's events in their dashboard and calendar and can book. The form label for that option on a hall should read "Members of lodges that meet here".
- Bookings go only through `EventRegistrationService`, and payments through `EventPaymentService` / the hall's own `ClubPaymentMethod`s, as the rules in CLAUDE.md say.

**Board membership is not membership.** (My call, from the anti-goals.) A `club_user` row on a hall club does not count as being a member of a club. It must not add the hall to `User::activeClubIds()` for `Visibility::Network`, the member area / `MemberScope`, or any lodge-style member list. A board member who belongs to no lodge gets the hall admin and nothing in the member area. Filter by club type (or an equivalent single rule), not screen by screen.

**Which link decides "the hall's lodges".** (My call.) For audiences and newsletters it is `clubs.masonic_hall_id` on platform clubs, which a lodge's admin sets in its settings. Self-selection is acceptable. Directory `lodges.masonic_hall_id` is used only for the read-only "the hall's lodges" list (directory lodges have no platform members). When the two disagree, the club's own setting wins.

**What the hall sees of lodge people.** (The user decided this.) The board sees names and contact details only for people who have booked a hall event (bookers and their guests, as `GuestListBuilder` gives them). It cannot search or browse the members of the hall's lodges. For a hall, the organiser member search (`admin.events.member_search`) and `saveByOrganiser` look only at people who have booked one of the hall's events before, plus new visitors typed in by hand.

**Newsletters to the hall's lodges.** (The user decided this.) The newsletters module is on for halls. Active members of the hall's lodges are signed up to the hall's channels automatically and can unsubscribe, through the usual one-click link. `NewsletterAudience` gets a hall case: the recipients are the active members of clubs whose `masonic_hall_id` is this hall, less opt-outs, plus visitors with an active subscription. Everything goes through `NewsletterSender` as usual. Making a hall channel mandatory is not allowed. The board never sees the recipient list beyond counts and delivery status (keeps the rule above).

**Managing the board.** (My call.) Halls get a cut-down "Board" screen in place of members/users: add or invite a person by email, set their access role and office title, and remove them. Owners only may grant `owner`, as `ClubAccess` already enforces. It gets its own `route_map` entry (for example `board`, under a capability owners and admins hold by default). Hall offices have no year or meeting: a title on the board row (an `OfficerRole` with an assignment that has no `meeting_id` and the current year is acceptable, or a plain title field; the builder chooses).

**Website and accounts:** used as they are for any club. Accounts that only make sense for lodges (subscriptions, charity, festival) stay off through the modules.

**The hall's lodges list:** a read-only screen in the hall's admin listing linked `Club`s and directory `Lodge`s at this hall, with their meeting pattern (`LodgeSchedule`). It gives no links into the lodges' admin.

## Constraints

- Everything in CLAUDE.md applies: `findClub`, `ClubAccess`, `club_permissions` route map, `AdminRouteSweepTest` must cover the new routes, `Club::factory()` / `ClubType::factory()` in tests, no per-item queries, currency via `Currencies`, PostgreSQL-first migrations.
- Screens and labels assume a lodge in many places (dashboard cards, nav, "members", rank names). Wherever a hall would see something that only fits a lodge, it should be hidden by module, not copied.

## Left to the builder

- How the hall club links to its `MasonicHall` (see above), and the migration.
- Exactly which modules and nav items a hall gets, as long as members, meetings, subscriptions, candidates and charity are off.
- What the hall dashboard shows (suggestion: details completeness, upcoming events, takings, the hall's lodges).
- Claim screens and emails, following the lodge-claim ones.
- Whether photos use MediaLibrary on the hall record or on the hall club.
