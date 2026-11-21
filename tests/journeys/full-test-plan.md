# Full end-to-end test plan

Run against the local demo (`demo` in `.claude/launch.json`, http://127.0.0.1:8123, database `club_manager_demo`, mail to `storage/logs/laravel.log`). `node tests/journeys/full-run.mjs` rebuilds the demo, creates its own accounts through the website (saved under `full_run` in `tests/journeys/accounts.local.json`) and runs every check below in order; the report goes to `tests/journeys/out/full-run-report.md`.

The story: a Secretary claims **Wealden Lodge No. 9012** from the Directory, a superadmin approves it, the Secretary sets the lodge up and invites the Treasurer and members, then the lodge runs a meeting, an event, its books and its news.

People (all made by the run): Superadmin (Sam Platform), Secretary and owner (Richard Ashworth), Treasurer (Peter Collins), members Martin Hale, Gareth Owen and Simon Reeve (invited), Keith Barlow (joins with the lodge's link). Seeded `admin@example.com` (Lodge of Fraternity) plays "another lodge's admin".

Checks verify the screen first and then the outcome in the database or the mail log.

## A. Accounts and access

| ID | Who | Steps | Expected |
|---|---|---|---|
| A1 | Visitor | Sign up at /register | Lands on sign-in with "Your account is ready", the email filled in, the password empty |
| A2 | New user | Type the password, sign in | Reaches /members |
| A3 | Visitor | Sign in with a wrong password | Stays on sign-in, "do not match" |
| A4 | Superadmin | `superadmin:grant`, open /superadmin | The superadmin area opens |
| A5 | Secretary | Sign up, sign in | Email filled in; signed in |
| A6 | Secretary | Forgot password, open the emailed link, set a new password, sign in | Email logged with a reset link; new password works; the old session is signed out |
| A7 | Secretary | Directory search "Wealden" | Wealden Lodge listed |
| A8 | Secretary | Lodge page, Claim this lodge, role Secretary, reason, tick, send | A pending claim |
| A9 | Superadmin | Lodge claims, Approve…, Confirm (new club) | Claim approved, club created |
| A10 | Secretary | Check access and roster | Owner, active; on the roster with office Secretary; "You now manage" email |
| A11 | Secretary | Roster, add Peter Collins, office Treasurer, invite with Treasurer access | On the roster; access `treasurer`, pending; invite email |
| A12 | Secretary | Add three members with invites (Member access) | On the roster; invite emails |
| A13a-d | Each invitee | Open the emailed link, choose a password | Signed in; membership active |
| A14 | Treasurer | Open the accounting admin | 200 |
| A15 | Secretary | Sign-ins and access: change Gareth Owen to Secretary access, then back | Role changes saved each time |
| A16 | Visitor | Sign up with the lodge's join link (/register?club=slug) | A pending join request for the lodge |
| A17 | Secretary | Sign-ins and access, Approve the request | Membership active |
| A18 | Member | Open admin members, meetings, accounting, access | Refused |
| A19 | Another lodge's admin | Open this lodge's admin pages | Refused |

## B. Lodge setup

| ID | Who | Steps | Expected |
|---|---|---|---|
| B1 | Secretary | Payment options, Bank transfer: account name, bank, sort code, account number, prefix; switch on | Option saved and on |
| B2 | Secretary | Pay on the night, switch on | Option on |
| B3 | Secretary | Settings: tagline, contact email, save | Kept after reload |
| B4 | Secretary | Settings, Dining: caterer's email | Saved in the lodge settings |
| B5 | Secretary | Officers: draft roster with WM, Treasurer, Secretary; save draft | Kept after reload |

## C. Meetings

| ID | Who | Steps | Expected |
|---|---|---|---|
| C1 | Secretary | Create meeting (in 5 days) with festive board, price, bank details; Save and publish | Published; every member emailed the summons |
| C2 | Secretary | Create a second meeting, Save changes; publish from the meetings list | Draft, then published |
| C3 | Member | Open the reply link in the summons email | Reply page for that meeting |
| C4 | Member | Going, dining; own dietary needs; a guest with dietary notes | Reply saved with the guest |
| C5 | Member 2 | Going, no dining | Saved |
| C6 | Member 3 | Apologies with a reason | Saved with the reason |
| C7 | Member | Sees bank details and own reference; taps "I've paid by bank transfer" | `said_paid_at` set, not marked paid; message shown |
| C8 | Secretary | Meeting dashboard | Attending 3, dining 2, meeting only 1, apologies 1 |
| C9 | Secretary | Dining list | Diner, guest, dietary needs |
| C10 | Secretary | Caterer sheet and its PDF | Menu and dietary needs; PDF downloads |
| C11 | System | `app:send-meeting-replies` right after publishing; then with the summons a week old | Nobody asked straight after the summons; later only those without a reply are asked, once (after 9am lodge time) |
| C12 | System | Cut-off passes, run the command again | Numbers to the Secretary; the caterer gets count, menu and dietary needs, no money and no list of non-repliers |
| C13 | Member 2 | Try to add dining after the cut-off | Explained; reply stays meeting only |
| C14 | Member 3 | Change apologies to going after the numbers went | Saved; Secretary emailed the change |
| C15 | Secretary | Signing-in book: tick two members and the guest | `attended_at` set on each |
| C16 | Secretary | Signing-in book: add a walk-in visitor | Walk-in recorded |
| C17 | Secretary | Meeting moved to yesterday (it has happened); attendance report | Members listed |

## D. Treasurer

| ID | Who | Steps | Expected |
|---|---|---|---|
| D1 | Treasurer | Bank accounts, add the lodge account | Saved |
| D2 | Treasurer | Import a statement CSV with the member's dining reference and a hall rent line | Two lines imported |
| D3 | Treasurer | Matching: Confirm the dining line | Member's and guest's dining paid, bank line kept |
| D4 | Secretary | Dining list | "Seen in the bank" |
| D5 | Member | Summons | Paid, nothing owed |
| D6 | Treasurer | Treasurer's report for the next meeting (it covers the year up to the day before that meeting) | Meeting and the dining money received today |
| D7 | Treasurer | Accounts overview, dues, accounting, reports, reconciliation | All load without errors |
| D8 | Treasurer | Dues: add a fee tier, run annual billing | Dues raised for the members |
| D9 | Member, Treasurer | Member sees dues on the lodge's Dues tab; Treasurer imports the payment and confirms | Dues paid |

## E. Events

| ID | Who | Steps | Expected |
|---|---|---|---|
| E1 | Secretary | New event form | Bank transfer and Pay on the night offered |
| E2 | Secretary | Event with 5 places, 2 ticket types, dinner with 6 dishes; Published | Saved with tiers, dishes and payment options |
| E3 | Member | Inbox | Told about the new event |
| E4 | Member | Book with a guest, tickets, dishes, bank transfer | Booking with two attendees, tiers and dishes |
| E5 | Member | "I've paid by bank transfer" | `said_paid_at` set |
| E6 | Organiser | Add a booking for Gareth Owen, pay on the night | Booking made |
| E6b | Organiser | Add a booking for a visitor with an email, pay on the night | Booking made; visitor emailed a private booking link |
| E7 | Members | Keith books the last place; Simon joins the waiting list | Simon waitlisted |
| E8 | Organiser | Check-in: tick the member and his guest | Both checked in |
| E9 | Organiser | Take payment at the door for Gareth | Paid, logged in the payment log |
| E10 | Organiser | Catering summary | Dish counts |
| E11 | Organiser | Guest list CSV | CSV with the names |
| E12 | Treasurer | Statement with the booking reference, Confirm | Booking paid, bank line kept |
| E13 | Member | Card or PayPal | Not covered: needs Stripe/PayPal |

## F. Communication

| ID | Who | Steps | Expected |
|---|---|---|---|
| F1 | Secretary | News post, Publish | Members notified; on the member news page |
| F2 | Secretary, system | Post with "Show from" later; time passes; `app:announce-scheduled-posts` | Nobody told early; members told once live |
| F3 | Secretary | Newsletter on the optional General channel, default roles, Send now | Sent to all six (owner and Treasurer included); deliveries recorded; emails logged |
| F4 | Member | Browser copy link from the email | Opens signed out |
| F5 | Member 3 | One-click unsubscribe link | Unsubscribed and recorded |
| F6 | Secretary | Send a notice | Recorded; members notified |
| F7 | Member | Inbox, then the News and Newsletters filters | All kinds listed; filters narrow the list |

## G. Member area

| ID | Who | Steps | Expected |
|---|---|---|---|
| G1 | Member | Dashboard, lodge home | Lodge shown |
| G2 | Member | Calendar; fetch the private .ics link | Meetings and event in both |
| G3 | Member | Bookings (/members/events) | The event booking |
| G4 | Member | Ticket | Both people on it |
| G5 | Member | Follow Lodge of Unity; My lodges | Followed; listed with suggestions from the same hall |
| G6 | Member | Directory search, members directory | Results; page loads |
| G7 | Member | Dues (all lodges, this lodge) | Shown, paid |
| G8 | Member | Masonic record | Loads |
| G9 | Member | Lodge profile: phone number | Saved to the roster; picture initials MH |
| G10 | Member, Fraternity admin | Visitor request to Lodge of Fraternity; its admin approves; switch lodge from the header | Active in both; switcher goes to the other lodge; header in the order colour |

## H. Security spot checks

| ID | Who | Steps | Expected |
|---|---|---|---|
| H1 | Secretary | Another lodge's admin and member pages | Refused, or sent to that lodge's public website |
| H2 | Member | POST publish a meeting, change a role | 403, nothing changed |
| H3 | Visitor | Made-up summons token; tampered signed reply link | "Invalid or has expired" page with nothing of the meeting, or refused |
| H4 | Member 2 | Event ticket page | Only their own booking |
| H5 | Secretary | /superadmin | Refused |
| H6 | Visitor | Member and admin pages signed out | Sent to sign in |
| H7 | Visitor | The private booking link from E6b; a made-up token | Opens with the booking; made-up one shows nothing |
