# Invitation-only public site: request access, contact and updates

Status: plan, waiting for approval. Nothing here is built yet.

## Why

Tracing Board is not open for anyone to sign up yet. The public site should explain what it does and turn interest
into conversations: a lodge asks for access, we check who they are, and only then do they get an account. Members of
a lodge already on the platform keep joining the way they do now, by invitation from their lodge.

## What changes for a visitor

**Pages a signed-out visitor can reach**

| Page | Today | After |
| --- | --- | --- |
| `/` homepage | Marketing page, "Find your lodge" | Same page, calls to action become "Request access" and "Get updates" |
| `/request-access` | none | Form: ask for access for your lodge (this is the waitlist) |
| `/contact` | none | Form: any other question |
| `/updates` | none | Email sign-up for product news (also a small box on the homepage) |
| `/login` | Sign in | Unchanged; the only way in |
| `/register` | Anyone can make an account | Off. Redirects to `/request-access` (kept behind a setting, see below) |
| `/lodges`, `/lodges/{slug}`, halls, claim | Public directory of 9,774 lodges | Signed-in members only. Guests are sent to `/request-access` |

**Header:** What it does · Contact · Sign in. **Footer:** Request access · Contact · Get updates · Sign in.
No "Find a lodge", no "Create an account".

Unchanged: lodge websites (`/site/{slug}` and own domains), event booking for visitors, visitor registration on a
lodge's own site, invitation links (`/{slug}/invite/{token}`), signed reply links from summonses.

## The three forms

All three: rate-limited with the existing `throttle:public-forms` (6 a minute per IP), a hidden honeypot field and a
"filled in too fast" check (under 3 seconds), British English, sentence case, a one-line privacy note.

### 1. Request access (the waitlist)

Fields:
- Your name, email, phone (optional)
- Your lodge or chapter: type to search the directory (at least 3 characters, up to 10 matches by name or number;
  never a browsable list). "My lodge isn't listed" opens free-text name, number and province.
- Your office in it: Worshipful Master (or the principal of the body), Secretary, Treasurer, Another officer, A member
  (the same list as lodge claims, `LodgeClaim::ROLES`)
- What you'd like to use it for (optional, free text)
- Consent: "We'll use these details to look at your request and contact you about it." Optional tick: "Also send me
  occasional product updates."

After sending: "Check your email to confirm your address." The request is not seen by anyone until the link in that
email is opened (see Verification, step 1). The page always says the same thing, whether or not the address or lodge
is already known, so the form cannot be used to find out who is registered.

### 2. Contact us

Name, email, topic (General question · Pricing · Press · Something's not working · Other), message. Stored and
emailed to the support address (`OPS_ALERT_EMAILS`, else superadmins). Reply happens by email from your own inbox;
the superadmin screen shows the messages and lets you mark each one handled. No email confirmation needed: a
contact message gives nobody access to anything.

### 3. Get updates

Email only. Double opt-in: the address is added once the link in the confirmation email is opened. Every update email
carries a one-click unsubscribe link (same pattern as newsletters). Version one stores and exports the list (CSV
through `Csv::safe()`); sending updates from the app comes later if wanted.

## Verifying people (how an access request becomes an account)

A lodge's data includes member names, addresses and money, so access goes only to someone we have good reason to
believe holds an office in that lodge. Each request moves through these states:

`unconfirmed` → `waiting` → (`more_info_needed` ⇄ `waiting`) → `approved` / `declined` / `waitlisted`

**Step 1. The email address is real (automatic).** The confirmation link (signed, valid 7 days) moves the request
from `unconfirmed` to `waiting` and only then tells the reviewers. Unconfirmed requests are deleted after 7 days. This
filters out almost all spam without an outside CAPTCHA service, which the site's privacy line (no third-party
requests) rules out.

**Step 2. Automatic checks, shown to the reviewer as a checklist (nothing is decided by them).**
- Lodge found in the directory, and whether anyone already manages it on Tracing Board.
- Email domain matches the lodge's own website or its province's domain (from the lodge's source links). A match is
  strong evidence; no match is normal (most secretaries use personal email).
- Earlier requests or claims from the same address or for the same lodge, and their outcome.
- Whether the address already has an account (shown to the reviewer only).

**Step 3. Reviewer checks (manual, a superadmin).** The review screen gives one-click links to do these:
- The lodge's page on the province's site and UGLE (already stored as lodge sources): is the named person listed as
  Secretary or another officer?
- If the lodge publishes a contact address or the province lists the Secretary's email, use **"Ask the lodge to
  confirm"**: the app emails that published address (not the requester's) with a yes/no link, "Does <name> hold the
  office of <office> in <lodge>?". A yes is recorded on the request. This is the strongest check, because only the
  real lodge can answer it.
- **"Ask for more information"**: emails the requester a question (for example a photo of the summons showing their
  name as Secretary, or a Provincial reference). Their reply goes onto the request. Same flow as lodge claims today.

**Step 4. Decision (a superadmin, with a reason that is recorded).**
- **Lodge not on Tracing Board yet → Approve:** creates the lodge from the directory entry (the same code as
  approving a claim, `LodgeClaimService::approveWithNewClub`), creates the requester's account with no password, makes
  them the lodge's owner, and emails an invitation link to set their password. That link is the only way to the
  account; nobody types a password for them.
- **Lodge already managed by someone → Pass to the lodge:** the request is emailed to that lodge's owners and admins,
  who invite the person themselves if they want to. We never add someone to a managed lodge over its officers' heads.
- **A member, not an officer:** "Ask your Secretary" reply, with an option to tell the lodge's Secretary (if the
  lodge is on Tracing Board) that a member asked.
- **Waitlist:** we're not taking on more lodges right now (or not in that province). They're emailed, keep their
  place, and get approved later from the same screen.
- **Decline:** with a reason. The requester gets a polite email, without the reviewer's notes.

**Afterwards.** Every step writes a line of history on the request (who, when, what), like lodge claims, and every
superadmin action is already in the superadmin audit log. The new owner can be removed by a superadmin if a lodge
later says they shouldn't have access.

**Retention.** Declined and withdrawn requests are deleted after 12 months, contact messages after 24 months,
unconfirmed requests and update sign-ups after 7 days.

## Superadmin screens

- `/superadmin/access-requests`: tabs Waiting · More info needed · Waitlist · Decided. Each request shows the
  details, the automatic checks, the source links, the history and the actions above. Count badge in the superadmin
  menu.
- `/superadmin/contact-messages`: list, mark handled.
- `/superadmin/update-subscribers`: count, CSV export.

## How it will be built

- **Tables:** `access_requests` (with `lodge_id` nullable and free-text lodge fields, `office`, `status`,
  `confirmed_at`, `lodge_confirmed_at`, `decided_by`, `decided_at`, `decision_reason`), `access_request_events`
  (history), `contact_messages`, `update_subscribers` (`email`, `confirmed_at`, `unsubscribe_token`).
- **Code:** one `AccessRequestService` that owns every state change (the same shape as `LodgeClaimService`, reusing
  its approve-with-new-club and roster code instead of copying it); controllers on `routes/public.php`
  (`throttle:public-forms`) and `routes/superadmin.php`; Inertia pages using `PublicHeader`/`PublicFooter` and the
  `Components/Ui` kit.
- **Email:** queued mailables on `transactional`; their wording added as superadmin email templates with a migration
  (`access_request_confirm`, `_received`, `_more_info`, `_approved`, `_declined`, `_waitlisted`,
  `lodge_confirm_officer`, `updates_confirm`).
- **Switches:** `PUBLIC_REGISTRATION` (default off) keeps `/register` reachable when wanted;
  `PUBLIC_DIRECTORY` (default off) puts the directory back for guests. `app:doctor` warns if either is on in production.
- **Existing lodge claims** stay for signed-in members (a member of one lodge claiming another); new people use
  request access.
- **Tests:** each form (honeypot, rate limit, same answer for known and unknown addresses), confirmation links (expiry,
  replay), every decision path including "already managed" and "already has an account", guests sent away from the
  directory, `/register` off, and the clean-up command. `AdminRouteSweepTest` covers the new superadmin routes.

## Order of work

1. Site changes: header, footer, homepage calls to action, `/register` off, directory members-only. Small and
   shippable on its own.
2. Request access form, email confirmation, review screen with automatic checks, approve/decline/waitlist, emails.
3. "Ask the lodge to confirm" and "Ask for more information".
4. Contact form and updates sign-up, with export.
5. Retention clean-up command (scheduled daily).

## Not in this plan

Paid plans and billing, sending product updates from the app, a referral or vouching scheme, a CAPTCHA, and public
lodge pages for search engines.
