# Province groups and order sections: design

## Intent

A province is only a directory record (`Province`) today. A superadmin edits it, and province admins (`province_admins`) look after its lodges' directory listings. Nothing lets a provincial body run anything on the platform.

Some provincial offices run everything themselves. Durham's Craft office, for example, has its own website. Other orders in the same province often have no website or tools of their own. A Rose Croix group for Durham mainly needs a website that covers every Rose Croix chapter in the Province of Durham.

So the platform gets **province groups**. A group (for example "Durham") is the provincial home. Inside it are **order sections** (Rose Croix, Mark, Royal Arch and so on), and each section is run by its own admin and editors. A section lists its order's lodges or chapters in that province automatically, and puts on that order's website, news, events and newsletters.

## Goals

- A superadmin creates a province group for a `Province` and invites its first owners and admins.
- Group owners and admins add order sections and appoint each section's admin.
- Each section has:
  - a **section admin**, who can do everything in the section and add or remove editors
  - **editors**, who can do everything in the section except manage people
- A section looks after its order only:
  - **website pages and information**
  - **news and events**: each item is either public or for members of that order's chapters in the province. Events have bookings and payments, recorded in the section's own accounts.
  - a **list of provincial officers**
  - its **chapters' directory listings**, including reviewing suggested fixes. These are today's province-admin screens, limited to one order.
  - **newsletters** to members of that order's chapters in the province, who can opt out
  - **pass-on**: sending circulars to its chapters' Secretaries so they can pass them on
- A section's list of lodges and chapters fills itself from the directory: every `Lodge` and `Club` of that order (club type) in the group's province. The list shows whether each one is on the platform, where and when it meets, and a link to it.
- A section can have its own name, logo and colours, and its own domain.
- The group's home page is a simple landing page that links to each order section. It can also link out to an external site, such as the Craft provincial office's own website.

## Non-goals

- No accounts for the group itself. Sections do have accounts (see Payments and accounts).
- No members, meetings, summonses, subscriptions, candidates or charity in a group or section.
- No section covering more than one province. A section is exactly one order in the group's province. (The user decided this.)
- The group does not need a full provincial website with its own news and events. Its home page is the landing page.
- Claiming a group from the directory. Only a superadmin creates groups.

## Anti-goals (a failure even if it works)

- A group or section role gives any access to a lodge's or chapter's private data or admin screens. The group only uses what is public in the directory or published. (The user decided this.)
- A section reaches outside its own order. A Rose Croix editor must not edit Mark chapters' listings, send to Mark members or edit the Mark pages.
- Group or section people show up as members of a lodge or chapter, or are counted in rosters, member lists or newsletters as members.
- Copies of the website builder, events or newsletters are made just for provinces.

## The decided shape

**Reuse the Hall Board pattern.** (My call, following the user's agreement to model this on halls.) A hall is a `Club` of type `hall`, which gives it a login, website, events and newsletters without members. Province groups work the same way. See `docs/superpowers/specs/2026-11-21-hall-board-design.md` and `app/Services/Halls`.

**Each order section is its own club, under the group's club.** (My call; the user approved it.) This is what lets a section have its own admins, editors, domain, logo, colours, website, events, newsletters and payment options with no new section-level permission system:
- **Group:** a `Club` of a new club type (suggested code `province_group`), with `clubs.province_id` set to its province. At most one group per province.
- **Section:** a `Club` of a new club type (suggested code `province_section`), with a link to its group club (a new `parent_club_id`, or a separate table if that fits better) and a link to the order it covers: a `ClubType` such as `rose_croix`. It takes its `province_id` from the group, and that cannot be changed. At most one section per order per group.
- Section admin = `admin` (or `owner`) on the section club. Editors = a staff role on the section club whose permission matrix allows everything except managing people. (My call.) Reuse an existing `ClubRole` case shown as "Editor" on sections, as halls show `coach` as "Secretary", rather than adding an enum case. `ClubAccess` and `config/club_permissions.php` already handle this per club.
- Group owners and admins can create sections and appoint, change or remove a section's admin. They don't need to be staff on the section to do that, and they don't get section editing rights from it.
- The user agreed to "one site, with a page per order". With this shape, each section's site is its own club site, `/site/{section-slug}`, or its own domain. The group's landing page (`/site/{group-slug}`) links to each section. `/site/{group-slug}/{order}` (for example `/rose-croix`) redirects to the section's site. To a visitor it reads as one provincial site with a part per order, and a section with its own domain stands alone.

**"The section's chapters".** (The user confirmed listing by Craft province is fine for now, even though some orders organise by their own areas that span several Craft provinces. An order-specific area model is a later step.) For the read-only list: directory `Lodge` rows where `province_id` is the group's province and `club_type_id` is the section's order, plus any platform `Club` with the same province and type. The list joins those two, so a chapter that is on the platform appears once. For audiences (events, news, newsletters): active members of platform `Club`s with that province and order club type. Self-selection through a lodge's own province and type settings is acceptable, as it was for halls.

**Audiences.** (The user decided this: public or order members, per item.) On a section club, the "members only" audience (`Visibility::Club`) means "active members of the section's chapters" instead of "members of this club". Every place that checks or lists club-audience events and news for a member must follow this, as the hall work did: the member area, calendar, `MemberScope` lists, `EventPayload` and `EventRegistrationService` audience checks. On a section, the form label for that option should read something like "Members of Rose Croix chapters in Durham".

**Payments and accounts.** (The user decided this.) Sections get the accounting module, as halls do, because `EventPaymentService` posts a ledger entry for every payment. Card and PayPal takings and refunds are recorded in the section's accounts. A section may appoint a Treasurer (`treasurer` role).

**Members-only news.** (The user decided this.) A section's members-only posts show in the member area (dashboard and news) for active members of the section's chapters, like their own lodge's news. This is a new path: today `MemberScope` gives halls no member posts.

**Which orders.** (My call.) Any order club type can be a section, Craft included. A Craft section's chapter list overlaps the existing `province_admins` screens for the same lodges, which is acceptable: both may edit the listing.

**Group and section people are not members.** (From the anti-goals; same rule as halls.) A `club_user` row on a group or section club must not add that club to `User::activeClubIds()`, the member area, `MemberScope` or member lists. Extend the existing hall rule by club type. Don't filter screen by screen.

**What a section sees of people.** (My call, same as halls.) Only people who booked its events, through `GuestListBuilder`. It cannot browse or search its chapters' members. Organiser booking search is limited to earlier bookers and new visitors typed in by hand.

**Newsletters.** (The user decided this.) Members of the section's chapters are signed up to the section's channels automatically and can unsubscribe with the usual link. `NewsletterAudience` gets a section case like its hall case, everything goes through `NewsletterSender`, and section channels cannot be mandatory. The section sees only counts and delivery status. Section channels should be listed on `/members/subscriptions` the same way hall channels now are.

**Pass-on to chapters.** (The user decided the feature. The mechanism is my call.) A section sends a circular to its chapters that are on the platform. It arrives with each chapter's Secretary and admins as something to pass on, using the existing pass-on screens (`PassOnController`, `PassedOnItem` with a source that says it came from the section). The chapter's Secretary decides whether to send it to their members. The section never emails the chapter's members through pass-on itself. Its direct route to members is newsletters.

**Officers list.** (The user decided the feature. The shape is my call.) Each section keeps an ordered list of provincial officers: office, name, and rank as free text (for example "Inspector General", "Ill. Bro. J Smith 33°"). These people may not be users. The list shows on the section's site. Reuse an existing officer structure if one fits without meetings or years. Otherwise a simple table is fine.

**Chapters' directory listings and suggested fixes.** (The user decided this.) The existing province-admin screens (`ProvinceLodgeController`, `ProvinceLodgeCorrectionController`) are scoped by province. Section admins and editors get the same screens inside their section's admin, scoped to province and order. Existing `province_admins` keep working as they do.

**Setup.** (The user decided this.) Superadmin screens: create a group for a province, invite its first owners and admins by email (as halls do through `HallClubs` / `HallBoard`), and see its sections. Group admins add sections in the group's admin, choosing the order from club types that are orders, and invite the section admin by email.

**Branding and domain.** (The user decided this.) These work as they do for any club. The section's name, logo, colours and `ClubDomain` live on the section club.

## Constraints

- Everything in CLAUDE.md applies: `findClub`, `ClubAccess`, `club_permissions` `route_map` entries for any new admin section, `AdminRouteSweepTest` covering the new routes, `Club::factory()` / `ClubType::factory()` in tests, no per-item queries (the chapters list across directory and platform in one query each), PostgreSQL-first migrations, `ReservedClubSlugs` for group and section slugs.
- Club type modules turn off what doesn't fit (members, meetings, subscriptions, candidates, charity; accounting only on sections). Lodge wording on the dashboard and nav is hidden or reworded by club type, not copied.
- Hiding the members-of-this-club meaning of audiences and newsletters must stay a single rule by club type, as it is for halls.

## Left to the builder

- Club type codes, and how a section links to its group and its order (column vs table).
- Exactly which modules and nav items the group and the sections get.
- The group admin screen for sections: list, add, appoint admin, remove a section. What removing a section does to its site and events. Suggestion: only allow it with no upcoming events.
- What the group's landing page shows besides section links, and how its external links are entered.
- The editor role's default permissions, as long as editors cannot manage people.
- Dashboards for group and section. Suggestion for a section: upcoming events, chapters count, open suggested fixes, last newsletter.
