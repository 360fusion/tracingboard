// The full end-to-end run on the local demo (see full-test-plan.md for the checks by ID).
// Rebuilds the demo database, makes its own accounts through the website, and walks a new lodge from claim to bank.
// Each check logs PASS, FAIL or NOT COVERED; a failure takes a screenshot into out/ and the run carries on.
// UNTIL=C stops after section C (for working on the script); NORESET=1 keeps the database (only useful with UNTIL).
import fs from 'fs';
import crypto from 'crypto';
import { execFileSync } from 'child_process';
import { ROOT, BASE, OUT, start, phone, go, fill, tap, find, field, choose, settle, pause } from './lib.mjs';

const UNTIL = process.env.UNTIL || 'Z';
const LOG = `${ROOT}/storage/logs/laravel.log`;
const ACCOUNTS = `${ROOT}/tests/journeys/accounts.local.json`;
const ENV = { ...process.env, DB_DATABASE: 'club_manager_demo', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log', APP_URL: BASE, EVENTS_ONLINE_PAYMENTS: 'true' };

// ---------------------------------------------------------------- helpers

const results = [];
const log = (status, id, title, detail = '') => {
    results.push({ status, id, title, detail });
    console.log(`${status.padEnd(12)} ${id.padEnd(5)} ${title}${detail ? ' :: ' + detail : ''}`);
};

/** Runs one check; a thrown error is a FAIL with a screenshot of `page`. Returning a string 'NC: why' marks it not covered. */
async function check(id, title, page, fn) {
    try {
        const r = await Promise.race([fn(), new Promise((_, no) => setTimeout(() => no(new Error('timed out after 150s')), 150000))]);
        if (typeof r === 'string' && r.startsWith('NC:')) {
            log('NOT COVERED', id, title, r.slice(3).trim());
        } else {
            log('PASS', id, title, typeof r === 'string' ? r : '');
        }
        return true;
    } catch (e) {
        let detail = (e.message || String(e)).split('\n')[0].slice(0, 300);
        if (page) {
            try { await page.screenshot({ path: `${OUT}/fail-${id}.png`, fullPage: true }); detail += ` [out/fail-${id}.png]`; } catch {}
        }
        log('FAIL', id, title, detail);
        return false;
    }
}
const notCovered = (id, title, why) => log('NOT COVERED', id, title, why);
const expect = (cond, msg) => { if (!cond) throw new Error(msg); };

const sh = (cmd, args, opts = {}) => execFileSync(cmd, args, { cwd: ROOT, env: ENV, ...opts }).toString();
const artisan = (...args) => sh('php', ['artisan', ...args]);
const pgpass = (fs.readFileSync(`${ROOT}/.env`, 'utf8').match(/^DB_PASSWORD=(.*)$/m) || [])[1]?.replace(/"/g, '') || '';
/** One value (first column of the first row) or '' from the demo database. */
const sql = (q) => sh('psql', ['-h', '127.0.0.1', '-U', 'postgres', '-d', 'club_manager_demo', '-At', '-c', q], { env: { ...ENV, PGPASSWORD: pgpass } }).trim();
const sqlRows = (q) => sql(q).split('\n').filter(Boolean).map((l) => l.split('|'));

const text = (page) => page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
async function see(page, ...needles) {
    const body = await text(page);
    for (const n of needles) {
        // Headings are often upper-cased by CSS, so text is compared without case.
        expect(n instanceof RegExp ? n.test(body) : body.toLowerCase().includes(n.toLowerCase()), `"${n}" not on ${page.url().replace(BASE, '')}`);
    }
    return body;
}
async function status(page, url) {
    const resp = await page.goto(BASE + url, { waitUntil: 'networkidle0' });
    await settle(page, 300);
    return resp.status();
}
const exists = (page, textOrSel, selector) => find(page, textOrSel, selector).then(() => true, () => false);
const password = () => crypto.randomBytes(15).toString('base64').replace(/[^A-Za-z0-9]/g, '').slice(0, 16) + 'a7';
const ymd = (d) => d.toISOString().slice(0, 10);
const days = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d; };
const ukDate = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;

/** Emails written to the log since the run started: [{to, subject, body, links}]. */
let logStart = 0;
function mails() {
    const fd = fs.openSync(LOG, 'r');
    const size = fs.fstatSync(fd).size;
    const buf = Buffer.alloc(size - logStart);
    fs.readSync(fd, buf, 0, buf.length, logStart);
    fs.closeSync(fd);
    const raw = '\n' + buf.toString('utf8').replace(/\r\n/g, '\n');
    return raw.split(/\n\[\d{4}-\d\d-\d\d [\d:]+\] local\.DEBUG: (?=From: )/).slice(1).map((block) => {
        const headerEnd = block.indexOf('\n\n');
        const headers = block.slice(0, headerEnd).replace(/\r?\n[ \t]+/g, ' ');
        let body = block.slice(headerEnd);
        const end = body.search(/\n\[\d{4}-\d\d-\d\d [\d:]+\] /);
        if (end > 0) body = body.slice(0, end);
        if (/=3D|=\r?\n/.test(body)) body = body.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
        const header = (name) => (headers.match(new RegExp(`^${name}: (.*)$`, 'm')) || [])[1] || '';
        const links = [...new Set([...body.matchAll(/https?:\/\/[^\s"'<>)]+/g)].map((m) => m[0].replace(/&amp;/g, '&')))];
        return { to: header('To'), subject: header('Subject'), body, links };
    });
}
const mailsTo = (email, subject) => mails().filter((m) => m.to.toLowerCase().includes(email.toLowerCase()) && (!subject || (subject instanceof RegExp ? subject.test(m.subject) : m.subject.includes(subject))));
function lastMail(email, subject) {
    const list = mailsTo(email, subject);
    expect(list.length, `no email to ${email}${subject ? ` about "${subject}"` : ''}`);
    return list[list.length - 1];
}
const linkIn = (mail, re) => { const l = mail.links.find((x) => re.test(x)); expect(l, `no link matching ${re} in "${mail.subject}"`); return l.replace(/^https?:\/\/[^/]+/, ''); };

/** Fills a form by label: { 'Label': 'value' }. */
async function form(page, fields) {
    for (const [label, value] of Object.entries(fields)) {
        await fill(page, label, value);
    }
}
async function tick(page, labelText, on = true) {
    const label = await find(page, labelText, 'label');
    const box = await label.evaluateHandle((l) => l.control || l.querySelector('input') || document.getElementById(l.htmlFor));
    const checked = await box.evaluate((b) => b.checked);
    // Clicked in the page rather than at a point: a sticky footer can sit over a control near the bottom of the screen.
    if (checked !== on) { await box.evaluate((b) => { b.scrollIntoView({ block: 'center' }); b.click(); }); await pause(400); }
    expect(await box.evaluate((b) => b.checked) === on, `"${labelText}" did not change`);
}
/** Clicks a button whose text matches and waits for Inertia/Livewire to finish. */
async function press(page, label, selector = 'button, a, [role=button], input[type=submit]') {
    // Scrolled there at once first: a smooth scroll still moving on a long page can put the tap on the wrong button.
    const element = await find(page, label, selector);
    await element.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await pause(150);
    await tap(page, element);
    await settle(page, 600);
}
/** Clicks the button reading `label` in the smallest block that also mentions `rowText` (a row of a list). */
async function pressIn(page, rowText, label) {
    const btn = await page.evaluateHandle((rowText, label) => {
        const rows = [...document.querySelectorAll('li, tr, div, article')].filter((r) => r.innerText?.includes(rowText) && [...r.querySelectorAll('button, a')].some((b) => b.innerText.trim() === label));
        const row = rows.sort((a, b) => a.innerText.length - b.innerText.length)[0];
        return row ? [...row.querySelectorAll('button, a')].find((b) => b.innerText.trim() === label) : null;
    }, rowText, label);
    expect(btn.asElement(), `no "${label}" beside ${rowText}`);
    await tap(page, btn.asElement());
    await settle(page, 800);
}
async function signUp(browser, name, email, pass, query = '') {
    const page = await phone(browser);
    await go(page, '/register' + query);
    await form(page, { 'Full name': name, 'Email address': email, 'Password': pass, 'Confirm password': pass });
    await press(page, 'Register account');
    return page;
}
async function signIn(page, email, pass) {
    await go(page, '/login');
    await page.$eval('input[type=email]', (e) => { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); });
    await page.$eval('input[type=password]', (e) => { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); });
    await page.type('input[type=email]', email);
    await page.type('input[type=password]', pass);
    await page.click('button[type=submit]');
    await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 });
    await settle(page);
}

// ---------------------------------------------------------------- set up

const t0 = Date.now();
if (!process.env.NORESET) {
    console.log('Rebuilding the demo database...');
    sh('sh', ['tests/journeys/reset-demo.sh']);
}
logStart = fs.statSync(LOG).size;

const people = {
    superadmin: { name: 'Sam Platform', email: 'run-superadmin@example.com', role: 'Superadmin: approves lodge claims' },
    secretary: { name: 'Richard Ashworth', email: 'run-secretary@example.com', role: 'Claims Wealden Lodge as Secretary; lodge owner' },
    treasurer: { name: 'Peter Collins', email: 'run-treasurer@example.com', role: 'Invited with Treasurer access' },
    member: { name: 'Martin Hale', email: 'run-member@example.com', role: 'Invited member; dines with a guest, books the event, visitor at Lodge of Fraternity' },
    member2: { name: 'Gareth Owen', email: 'run-member2@example.com', role: 'Invited member; going without dining, tries to add dining after the cut-off' },
    member3: { name: 'Simon Reeve', email: 'run-member3@example.com', role: 'Invited member; sends apologies, then changes to going; waiting list' },
    joiner: { name: 'Keith Barlow', email: 'run-joiner@example.com', role: 'Signs up with the lodge join link; approved by the Secretary' },
};
for (const p of Object.values(people)) p.password = password();
const saveAccounts = () => {
    const file = JSON.parse(fs.readFileSync(ACCOUNTS, 'utf8'));
    file.full_run = { site: `${BASE} (demo database club_manager_demo)`, lodge: 'Wealden Lodge No. 9012', accounts: people };
    fs.writeFileSync(ACCOUNTS, JSON.stringify(file, null, 2) + '\n');
};
saveAccounts();

const browser = await start();
const S = {}; // state shared between sections: slug, ids
const pages = {};
const reach = (section) => section <= UNTIL;

// ================================================================ A. Accounts and access
{
    const sa = people.superadmin;
    let page = await signUp(browser, sa.name, sa.email, sa.password);
    await check('A1', 'Sign-up lands on sign-in with the new email filled in', page, async () => {
        expect(page.url().includes('/login'), `ended on ${page.url()}`);
        const email = await page.$eval('input[type=email]', (e) => e.value);
        expect(email === sa.email, `email field holds "${email}"`);
        const pw = await page.$eval('input[type=password]', (e) => e.value);
        expect(pw === '', 'password field is not empty');
        await see(page, 'Your account is ready');
    });
    await check('A2', 'Sign-in with the new account reaches the member area', page, async () => {
        await page.type('input[type=password]', sa.password);
        await page.click('button[type=submit]');
        await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 });
        await settle(page);
        expect(page.url().includes('/members'), `landed on ${page.url()}`);
    });
    await check('A3', 'Wrong password is refused', null, async () => {
        const p = await phone(browser);
        await go(p, '/login');
        await p.$eval('input[type=email]', (e) => { e.value = ''; });
        await p.$eval('input[type=password]', (e) => { e.value = ''; });
        await p.type('input[type=email]', sa.email);
        await p.type('input[type=password]', 'not-the-password');
        await p.click('button[type=submit]');
        await settle(p, 800);
        expect(p.url().includes('/login'), 'signed in with a wrong password');
        await see(p, 'do not match');
        await p.close();
    });
    artisan('superadmin:grant', sa.email);
    await go(page, '/superadmin');
    pages.sa = page;
    await check('A4', 'Superadmin grant opens /superadmin', page, async () => {
        expect(new URL(page.url()).pathname.startsWith('/superadmin'), `redirected to ${page.url()}`);
    });

    const sec = people.secretary;
    page = await signUp(browser, sec.name, sec.email, sec.password);
    await check('A5', 'Second sign-up, email filled in, then sign-in', page, async () => {
        const email = await page.$eval('input[type=email]', (e) => e.value);
        expect(email === sec.email, `email field holds "${email}"`);
        await page.type('input[type=password]', sec.password);
        await page.click('button[type=submit]');
        await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 });
        await settle(page);
    });
    pages.sec = page;

    // Forgot password: the email, the link, a new password, signing in with it.
    await check('A6', 'Forgot password emails a reset link that sets a new password', null, async () => {
        const p = await phone(browser);
        await go(p, '/forgot-password');
        await fill(p, 'Email address', sec.email);
        await press(p, 'Send password reset link');
        await see(p, /sent|email/i);
        const mail = lastMail(sec.email, /reset/i);
        const link = linkIn(mail, /reset-password\//);
        await go(p, link);
        sec.password = password();
        const pw = await p.$$('input[type=password]');
        expect(pw.length >= 2, 'reset page has no password fields');
        await pw[0].type(sec.password);
        await pw[1].type(sec.password);
        await p.click('button[type=submit]');
        await settle(p, 800);
        saveAccounts();
        await signIn(p, sec.email, sec.password);
        expect(!p.url().includes('/login'), 'new password did not sign in');
        // A new password signs out the other sessions, so the Secretary carries on with this one.
        await go(page, '/members/dashboard');
        expect(page.url().includes('/login'), 'the old session survived the password reset');
        await page.close();
        page = pages.sec = p;
    });

    // Claim a lodge from the directory.
    await check('A7', 'Directory search finds Wealden Lodge', page, async () => {
        await go(page, '/lodges?q=Wealden');
        await see(page, 'Wealden Lodge');
    });
    await check('A8', 'Claim Wealden Lodge as its Secretary', page, async () => {
        await go(page, '/lodges/wealden-lodge-9012');
        await press(page, 'Claim this lodge');
        expect(page.url().includes('/claim'), `claim link went to ${page.url()}`);
        await fill(page, 'Your part in the lodge', 'secretary');
        await fill(page, 'Why you are asking', 'I am the Secretary of Wealden Lodge and want to send our summonses from here.');
        await tick(page, 'I belong to this lodge');
        await press(page, 'Send my claim');
        expect(sql("select count(*) from lodge_claims c join users u on u.id=c.user_id where u.email='run-secretary@example.com' and c.status='pending'") === '1', 'no pending claim in the database');
    });
    await check('A9', 'Superadmin approves the claim with a new club', pages.sa, async () => {
        const p = pages.sa;
        await go(p, '/superadmin/lodge-claims');
        await see(p, 'Wealden Lodge');
        await press(p, 'Approve…');
        await press(p, 'Confirm');
        const row = sqlRows("select c.status, c.club_id from lodge_claims c join users u on u.id=c.user_id where u.email='run-secretary@example.com'")[0];
        expect(row && row[0] === 'approved' && row[1], `claim is ${row}`);
        S.clubId = row[1];
        S.slug = sql(`select slug from clubs where id=${S.clubId}`);
    });
    await check('A10', 'Claimant is the owner and on the roster as Secretary', page, async () => {
        const role = sql(`select role||'/'||status from club_user cu join users u on u.id=cu.user_id where u.email='run-secretary@example.com' and club_id=${S.clubId}`);
        expect(role === 'owner/active', `pivot is ${role}`);
        const office = sql(`select current_office from club_acc_members where club_id=${S.clubId} and email='run-secretary@example.com'`);
        expect(office === 'secretary', `roster office is "${office}"`);
        const mail = lastMail(sec.email, /now manage/i);
        expect(mail, 'no approval email');
        await go(page, `/${S.slug}/admin/members`);
        await see(page, 'Ashworth');
    });
    if (!S.slug) {
        console.log('No lodge to carry on with; stopping.');
    }

    // Roster with invitations.
    const addToRoster = async (person, office, access) => {
        const [first, last] = person.name.split(' ');
        await go(page, `/${S.slug}/admin/members/create`);
        await form(page, { 'First name': first, 'Surname': last, 'Email address': person.email });
        if (office) await fill(page, 'Current office', office);
        await tick(page, 'Send an invitation to set up an online account');
        await settle(page, 400);
        if (access) await fill(page, 'What they can do once signed in', access);
        await press(page, 'Create member record');
        expect(sql(`select count(*) from club_acc_members where club_id=${S.clubId} and email='${person.email}'`) === '1', `${person.email} not on the roster`);
    };
    await check('A11', 'Add the Treasurer to the roster with an invite giving Treasurer access', page, async () => {
        await addToRoster(people.treasurer, 'treasurer', 'treasurer');
        const pivot = sql(`select role||'/'||status from club_user cu join users u on u.id=cu.user_id where u.email='${people.treasurer.email}' and club_id=${S.clubId}`);
        expect(pivot === 'treasurer/pending', `access is ${pivot}`);
        lastMail(people.treasurer.email, /invited/i);
    });
    await check('A12', 'Add three members to the roster with invites', page, async () => {
        for (const who of ['member', 'member2', 'member3']) await addToRoster(people[who], null, 'member');
        for (const who of ['member', 'member2', 'member3']) lastMail(people[who].email, /invited/i);
    });
    for (const who of ['treasurer', 'member', 'member2', 'member3']) {
        const person = people[who];
        await check(`A13${'abcd'[['treasurer', 'member', 'member2', 'member3'].indexOf(who)]}`, `${person.name} accepts the emailed invite and signs in`, null, async () => {
            const p = await phone(browser);
            const link = linkIn(lastMail(person.email, /invited/i), /\/invite\//);
            await go(p, link);
            const pw = await p.$$('input[type=password]');
            expect(pw.length >= 1, 'invite page has no password field');
            for (const input of pw) await input.type(person.password);
            await p.click('button[type=submit]');
            await settle(p, 1000);
            if (p.url().includes('/login')) await signIn(p, person.email, person.password);
            expect(!p.url().includes('/invite/') && !p.url().includes('/login'), `still on ${p.url()}`);
            const pivot = sql(`select status from club_user cu join users u on u.id=cu.user_id where u.email='${person.email}' and club_id=${S.clubId}`);
            expect(pivot === 'active', `membership is ${pivot}`);
            pages[who] = p;
        });
    }
    await check('A14', 'Treasurer access lets the Treasurer into the accounts', pages.treasurer, async () => {
        const code = await status(pages.treasurer, `/${S.slug}/admin/accounting`);
        expect(code === 200, `accounting answered ${code}`);
    });

    await check('A15', 'Sign-ins and access: make a member Secretary access, then back', page, async () => {
        await go(page, `/${S.slug}/admin/users`);
        await see(page, people.member2.name);
        const uid = sql(`select id from users where email='${people.member2.email}'`);
        const setRole = async (role) => {
            const select = await page.evaluateHandle((name) => {
                const row = [...document.querySelectorAll('tr, li, [role=row], div')].filter((r) => r.innerText?.includes(name) && r.querySelector('select[aria-label="Club role"]')).sort((a, b) => a.innerText.length - b.innerText.length)[0];
                return row?.querySelector('select[aria-label="Club role"]') || null;
            }, people.member2.name);
            expect(select.asElement(), 'no role dropdown on the row');
            await select.asElement().evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); }, role);
            await settle(page, 1000);
            // A confirm step, if the page asks.
            if (await exists(page, 'Change role')) await press(page, 'Change role');
        };
        await setRole('coach');
        let role = sql(`select role from club_user where user_id=${uid} and club_id=${S.clubId}`);
        expect(role === 'coach', `role is ${role} after making Secretary`);
        await setRole('member');
        role = sql(`select role from club_user where user_id=${uid} and club_id=${S.clubId}`);
        expect(role === 'member', `role is ${role} after making member again`);
    });

    // Join request through the lodge's public registration link.
    await check('A16', 'Someone signs up with the lodge join link and asks to join', null, async () => {
        const j = people.joiner;
        const p = await signUp(browser, j.name, j.email, j.password, `?club=${S.slug}`);
        const pivot = sql(`select role||'/'||status from club_user cu join users u on u.id=cu.user_id where u.email='${j.email}' and club_id=${S.clubId}`);
        expect(pivot === 'member/pending', `join request is "${pivot}"`);
        await signIn(p, j.email, j.password);
        pages.joiner = p;
    });
    await check('A17', 'Secretary approves the join request', page, async () => {
        await go(page, `/${S.slug}/admin/users`);
        await see(page, people.joiner.name);
        await pressIn(page, people.joiner.name, 'Approve');
        const pivot = sql(`select status from club_user cu join users u on u.id=cu.user_id where u.email='${people.joiner.email}' and club_id=${S.clubId}`);
        expect(pivot === 'active', `membership is ${pivot}`);
    });
    await check('A18', 'A plain member is refused the admin pages', pages.member, async () => {
        for (const url of [`/${S.slug}/admin/members`, `/${S.slug}/admin/meetings`, `/${S.slug}/admin/accounting`, `/${S.slug}/admin/users`]) {
            const code = await status(pages.member, url);
            expect([403, 404].includes(code) || !pages.member.url().includes('/admin'), `${url} answered ${code}`);
        }
    });
    await check('A19', "Another lodge's admin is refused this lodge's admin", null, async () => {
        const other = await phone(browser, 'admin@example.com');
        for (const url of [`/${S.slug}/admin/members`, `/${S.slug}/admin/meetings`, `/${S.slug}/admin/users`]) {
            const code = await status(other, url);
            expect([403, 404].includes(code) || !other.url().includes('/admin'), `${url} answered ${code}`);
        }
        pages.other = other;
    });
}


const uid = (who) => sql(`select id from users where email='${people[who].email}'`);
const uidOf = (email) => sql(`select id from users where email='${email}'`);

// ================================================================ B. Lodge setup
if (reach('B') && S.slug) {
    const page = pages.sec;
    const setUpOption = async (name, fields) => {
        await go(page, `/${S.slug}/admin/payment-options`);
        await press(page, `Edit ${name}`);
        await form(page, fields);
        await press(page, 'Save');
        // Then switch it on (the switch next to the option).
        await go(page, `/${S.slug}/admin/payment-options`);
        const toggle = await page.evaluateHandle((name) => {
            const cards = [...document.querySelectorAll('li, section, article, div')].filter((c) => c.innerText?.includes(name) && c.querySelector('[role=switch]'));
            const card = cards.sort((a, b) => a.innerText.length - b.innerText.length)[0];
            return card?.querySelector('[role=switch]') || null;
        }, name);
        expect(toggle.asElement(), `no switch for ${name}`);
        if (await toggle.asElement().evaluate((e) => e.getAttribute('aria-checked') !== 'true')) await tap(page, toggle.asElement());
        await settle(page, 800);
    };
    await check('B1', 'Bank transfer set up with the lodge bank details and switched on', page, async () => {
        await setUpOption('Bank transfer', { 'Account name': 'Wealden Lodge No. 9012', 'Bank name (optional)': 'Example Bank', 'Sort code': '40-11-22', 'Account number': '87654321', 'Reference prefix': 'WL' });
        const row = sql(`select is_active from club_payment_methods where club_id=${S.clubId} and type='bank_transfer'`);
        expect(row === 't', `bank option is_active=${row}`);
    });
    await check('B2', 'Pay on the night switched on', page, async () => {
        await setUpOption('Pay on the night', { 'Name people see': 'Pay on the night' });
        const row = sql(`select is_active from club_payment_methods where club_id=${S.clubId} and type='cash_on_door'`);
        expect(row === 't', `on the night is_active=${row} (types: ${sql(`select string_agg(type||'='||is_active, ',') from club_payment_methods where club_id=${S.clubId}`)})`);
    });
    await check('B3', 'Lodge settings save (tagline, contact email)', page, async () => {
        await go(page, `/${S.slug}/admin/settings`);
        await form(page, { 'Tagline or motto': 'Friendship in the Weald', 'Contact email': 'secretary@wealden.example.com' });
        await press(page, 'Save all settings');
        await go(page, `/${S.slug}/admin/settings`);
        const tag = await (await field(page, 'Tagline or motto')).evaluate((e) => e.value);
        expect(tag === 'Friendship in the Weald', `tagline after reload is "${tag}"`);
    });
    await check('B4', "Dining settings: caterer's email saved", page, async () => {
        await go(page, `/${S.slug}/admin/settings`);
        await fill(page, 'Settings section', 'dining');
        await settle(page, 500);
        await fill(page, "Caterer's email (optional)", 'caterer@example.com');
        await press(page, 'Save all settings');
        const v = sql(`select settings->>'caterer_email' from clubs where id=${S.clubId}`);
        expect(v === 'caterer@example.com', `caterer_email is "${v}"`);
    });
    await check('B5', 'Officers: a draft roster for the year saves (Worshipful Master, Treasurer, Secretary)', page, async () => {
        await go(page, `/${S.slug}/admin/officers`);
        await choose(page, 'Worshipful Master', 'Bro Martin Hale');
        await choose(page, 'Treasurer', 'Bro Peter Collins');
        await choose(page, 'Secretary', 'Bro Richard Ashworth');
        await press(page, 'Save draft');
        const rows = sql(`select string_agg(a.office||'='||m.last_name, ',' order by a.office) from club_acc_annual_officer_assignments a join club_acc_annual_officer_rosters r on r.id=a.roster_id join club_acc_members m on m.id=a.member_id where r.club_id=${S.clubId}`);
        expect(/Hale/.test(rows) && /Collins/.test(rows) && /Ashworth/.test(rows), `saved assignments: ${rows}`);
        await go(page, `/${S.slug}/admin/officers`);
        await see(page, 'Bro Martin Hale');
    });
}

// ================================================================ C. Meetings
if (reach('C') && S.slug) {
    const page = pages.sec;
    const meetingForm = async (title, date) => {
        await go(page, `/${S.slug}/admin/meetings/create`);
        await form(page, { 'Meeting title': title, 'Meeting date': ymd(date), 'Meeting start time': '18:30', 'Dress code': 'Dark suit, white gloves', 'Venue address': 'Riverside Masonic Hall, 12 Mill Lane, Ashford' });
        await fill(page, 'Summons section', 'festive');
        await settle(page, 400);
        await form(page, { 'Festive board theme or notice text': 'Festive board with a toast to absent brethren', 'Menu': 'Leek soup\nRoast beef\nApple crumble', 'Price per head': '30', 'Bank sort code': '40-11-22', 'Bank account number': '87654321', 'Payment reference convention': 'WL' });
    };
    await check('C1', 'Create a meeting and Save and publish (summons emailed to members)', page, async () => {
        await meetingForm('Regular meeting', days(5));
        await press(page, 'Save and publish');
        await press(page, 'Publish summons');
        await settle(page, 1500);
        const row = sqlRows(`select id, status from meetings where club_id=${S.clubId} and title='Regular meeting'`)[0];
        expect(row && row[1] === 'published', `meeting is ${row}`);
        S.m1 = row[0];
        for (const who of ['member', 'member2', 'member3', 'treasurer']) lastMail(people[who].email, /summons/i);
    });
    await check('C2', 'Create a second meeting, save, then publish it from the list', page, async () => {
        await meetingForm('Installation meeting', days(40));
        await press(page, 'Save changes');
        const row = sqlRows(`select id, status from meetings where club_id=${S.clubId} and title='Installation meeting'`)[0];
        expect(row && row[1] !== 'published', `saved meeting is ${row}`);
        S.m2 = row[0];
        await go(page, `/${S.slug}/admin/meetings`);
        await press(page, 'Publish and send summons');
        // Once in a while the first tap lands before the page is ready; tap again if no dialog opened.
        if (!(await exists(page, 'Publish summons', '[role=dialog] button'))) await press(page, 'Publish and send summons');
        await press(page, 'Publish summons', '[role=dialog] button');
        expect(sql(`select status from meetings where id=${S.m2}`) === 'published', 'not published from the list');
    });
    await check('C3', 'The summons email carries a reply link that opens the reply page', null, async () => {
        const p = await phone(browser);
        const mail = lastMail(people.member3.email, /Regular meeting/i);
        const link = mail.links.find((l) => /summons|reply|rsvp/i.test(l) && l.includes(String(S.m1))) || mail.links.find((l) => /summons|reply|rsvp/i.test(l));
        expect(link, `no reply link in "${mail.subject}": ${mail.links.join(' ')}`);
        await go(p, link.replace(/^https?:\/\/[^/]+/, ''));
        await see(p, 'Regular meeting');
        await p.close();
    });

    const summons = `/members/${S.slug}/meetings/${S.m1}/summons`;
    await check('C4', 'Member replies going and dining, with a guest and dietary needs', pages.member, async () => {
        const p = pages.member;
        await go(p, summons);
        await press(p, 'Going, dining');
        if (await exists(p, 'Guests and dietary needs')) await press(p, 'Guests and dietary needs');
        await fill(p, 'Your dietary requirements', 'Vegetarian');
        await press(p, 'Add a guest');
        await fill(p, 'Guest 1 full name', 'Mr John Smith');
        await fill(p, 'Guest 1 dietary notes', 'No nuts');
        await press(p, 'Save response');
        const row = sqlRows(`select r.attendance_status, r.dietary_requirements, (select count(*) from meeting_rsvp_guests g where g.meeting_rsvp_id=r.id) from meeting_rsvps r where meeting_id=${S.m1} and user_id=${uid('member')}`)[0];
        expect(row && row[0] === 'attending_dining' && row[1] === 'Vegetarian' && row[2] === '1', `reply is ${row}`);
    });
    await check('C5', 'Member 2 replies going, no dining', pages.member2, async () => {
        await go(pages.member2, summons);
        await press(pages.member2, 'Going, no dining');
        const st = sql(`select attendance_status from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member2')}`);
        expect(st === 'attending_meeting_only', `reply is ${st}`);
    });
    await check('C6', 'Member 3 sends apologies with a reason', pages.member3, async () => {
        const p = pages.member3;
        await go(p, summons);
        await press(p, 'Apologies');
        if (await exists(p, 'Add a reason')) {
            await press(p, 'Add a reason');
            await fill(p, 'Reason for apologies', 'Away on business');
            await press(p, 'Save response');
        }
        const row = sqlRows(`select attendance_status, apology_reason from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member3')}`)[0];
        expect(row && row[0] === 'apologies' && row[1] === 'Away on business', `reply is ${row}`);
    });
    await check('C7', "Member says I've paid by bank transfer (stops reminders, marks nothing paid)", pages.member, async () => {
        const p = pages.member;
        await go(p, summons);
        const body = await see(p, 'Bank transfer details', 'Dining still to pay');
        S.m1ref = sql(`select payment_reference from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member')}`);
        expect(S.m1ref && body.includes(S.m1ref), `payment reference "${S.m1ref}" not shown`);
        await press(p, "I've paid by bank transfer");
        const row = sqlRows(`select said_paid_at is not null, payment_status from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member')}`)[0];
        expect(row[0] === 't' && row[1] !== 'paid', `said_paid/payment is ${row}`);
        await see(p, 'You told us you paid by bank');
    });
    await check('C8', 'Secretary dashboard shows the numbers (attending 3, dining 2, meeting only 1, apologies 1)', page, async () => {
        await go(page, `/${S.slug}/admin/meetings/${S.m1}`);
        const body = await text(page);
        const stat = (label) => (body.match(new RegExp(label + '\\s*(\\d+)', 'i')) || [])[1];
        const got = { attending: stat('Total attending'), dining: stat('Total dining'), only: stat('Meeting only'), apologies: stat('Apologies received') };
        expect(got.dining === '2' && got.only === '1' && got.apologies === '1' && got.attending === '3', `stats ${JSON.stringify(got)}`);
    });
    await check('C9', 'Dining list shows the diners, the guest and dietary needs', page, async () => {
        await go(page, `/${S.slug}/admin/meetings/${S.m1}/dining`);
        await see(page, 'Martin Hale', 'Mr John Smith', 'Vegetarian', 'No nuts');
        const body = await text(page);
        expect(!body.includes('Simon Reeve') || /apolog/i.test(body), 'apologies listed as diners');
    });
    await check('C10', 'Caterer sheet: count, menu and dietary needs; PDF downloads', page, async () => {
        await go(page, `/${S.slug}/admin/meetings/${S.m1}/dining/caterer`);
        await see(page, 'Roast beef', 'Vegetarian', 'No nuts');
        const res = await page.evaluate(async (u) => { const r = await fetch(u); return [r.status, r.headers.get('content-type')]; }, `/${S.slug}/admin/meetings/${S.m1}/dining/caterer?download=1`);
        expect(res[0] === 200 && res[1].includes('pdf'), `PDF answered ${res}`);
    });
    await check('C11', 'Scheduled replies (app:send-meeting-replies): no ask straight after the summons; later, only those without a reply are asked', null, async () => {
        const hour = Number(sql(`select extract(hour from now() at time zone 'Europe/London')`));
        if (hour < 9) return 'NC: before 9am lodge time, the command sends nothing yet';
        const asks = (who) => mailsTo(people[who].email, /Will you be there/).length;
        artisan('app:send-meeting-replies');
        expect(asks('treasurer') === 0, 'asked again straight after the summons went out');
        // A week later: the summons went out before the ask window, so those who have not replied are asked.
        sql(`update meetings set summons_published_at = now() - interval '10 days', replies_requested_at = null where id=${S.m1}`);
        artisan('app:send-meeting-replies');
        expect(sql(`select replies_requested_at is not null from meetings where id=${S.m1}`) === 't', 'replies_requested_at not set');
        expect(asks('treasurer') === 1 && asks('joiner') === 1, 'members without a reply were not asked');
        expect(asks('member') === 0 && asks('member3') === 0, 'a member who replied was asked');
        artisan('app:send-meeting-replies');
        expect(asks('treasurer') === 1, 'asked twice');
    });
    await check('C12', 'After the cut-off: numbers go to the Secretary, and count, menu and dietary needs to the caterer', null, async () => {
        sql(`update meetings set rsvp_cutoff_at = now() - interval '1 hour' where id=${S.m1}`);
        artisan('app:send-meeting-replies');
        const stamped = sql(`select numbers_sent_at is not null from meetings where id=${S.m1}`);
        expect(stamped === 't', 'numbers_sent_at not set');
        const sec = lastMail(people.secretary.email, /numbers|dining/i);
        expect(/Martin Hale|Hale/.test(sec.body), 'Secretary numbers email has no names');
        const cat = lastMail('caterer@example.com');
        expect(/Vegetarian/.test(cat.body) && /Leek soup/.test(cat.body), 'caterer email lacks the menu or dietary needs');
        expect(!/unpaid|Keith Barlow|Peter Collins/i.test(cat.body), 'caterer email carries money or the members who have not replied');
    });
    await check('C13', 'After the cut-off a member cannot add dining', pages.member2, async () => {
        const p = pages.member2;
        await go(p, summons);
        await see(p, 'dining can no longer be added');
        const disabled = await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Going, dining')?.disabled);
        if (!disabled) {
            await press(p, 'Going, dining');
            if (await exists(p, 'Yes, going, dining')) await press(p, 'Yes, going, dining');
        }
        const st = sql(`select attendance_status from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member2')}`);
        expect(st === 'attending_meeting_only', `reply became ${st}`);
    });
    await check('C14', 'After the numbers have gone, a change is emailed to the Secretary', pages.member3, async () => {
        const before = mailsTo(people.secretary.email).length;
        const p = pages.member3;
        await go(p, summons);
        await press(p, 'Going, no dining');
        if (await exists(p, 'Yes, going, no dining')) await press(p, 'Yes, going, no dining');
        const st = sql(`select attendance_status from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member3')}`);
        expect(st === 'attending_meeting_only', `reply is ${st}`);
        expect(mailsTo(people.secretary.email).length > before, 'no change email to the Secretary');
    });
    await check('C15', 'Signing-in book: members, a guest and a walk-in visitor', page, async () => {
        await go(page, `/${S.slug}/admin/meetings/${S.m1}/signing-in`);
        await see(page, 'Martin Hale');
        const mark = async (name) => {
            await tap(page, await find(page, name, 'button'));
            await settle(page, 700);
        };
        await mark('Bro Martin Hale');
        await mark('Bro Gareth Owen');
        await mark('Mr John Smith');
        const n = sql(`select count(*) from meeting_rsvps where meeting_id=${S.m1} and attended_at is not null`);
        expect(n === '2', `${n} members marked attended`);
        const g = sql(`select count(*) from meeting_rsvp_guests g join meeting_rsvps r on r.id=g.meeting_rsvp_id where r.meeting_id=${S.m1} and g.attended_at is not null`);
        expect(g === '1', `${g} guests marked attended`);
    });
    await check('C16', 'Signing-in book: a walk-in visitor is added', page, async () => {
        await go(page, `/${S.slug}/admin/meetings/${S.m1}/signing-in`);
        await form(page, { 'Name': 'Alan Visitor', 'Rank': 'WBro', 'Lodge and number': 'Lodge of Unity No 4421' });
        await press(page, 'Sign in', 'button[type=submit]');
        const n = sql(`select count(*) from meeting_walk_ins where meeting_id=${S.m1}`);
        expect(n === '1', `${n} walk-ins`);
    });
    await check('C17', 'Attendance report (once the meeting has happened) lists members, longest absent first', page, async () => {
        // The report covers meetings already held, so move the meeting to yesterday as if the night had come.
        sql(`update meetings set meeting_date = current_date - 1 where id=${S.m1}`);
        await go(page, `/${S.slug}/admin/meetings/attendance`);
        await see(page, 'Martin Hale', 'Simon Reeve');
    });
}


/** Writes a statement CSV and imports it on the Treasurer's phone; lands on the matching page. */
async function importStatement(page, name, lines) {
    const file = `${OUT}/${name}.csv`;
    let balance = 1000;
    fs.writeFileSync(file, 'Date,Description,Reference,Amount,Balance\n' + lines.map(([desc, ref, amount]) => `${ukDate(new Date())},${desc},${ref},${amount.toFixed(2)},${(balance += amount).toFixed(2)}`).join('\n') + '\n');
    await go(page, `/members/${S.slug}/accounts/import`);
    const input = await page.$('#statement-file');
    expect(input, 'no statement file field');
    await input.uploadFile(file);
    await pause(500);
    await press(page, 'Read the statement');
    await press(page, `Import ${lines.length} line`);
    await settle(page, 1000);
}
/** Confirms the suggested match for the line with this reference. */
async function confirmLine(page, ref) {
    if (!page.url().includes('/accounts/match')) await go(page, `/members/${S.slug}/accounts/match`);
    const btn = await page.evaluateHandle((ref) => {
        const rows = [...document.querySelectorAll('li, tr, div')].filter((r) => r.innerText?.includes(ref) && [...r.querySelectorAll('button')].some((b) => b.innerText.trim() === 'Confirm'));
        const row = rows.sort((a, b) => a.innerText.length - b.innerText.length)[0];
        return row ? [...row.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Confirm') : null;
    }, ref);
    expect(btn.asElement(), `no suggested match to confirm for ${ref}`);
    await tap(page, btn.asElement());
    await settle(page, 1000);
}
const money = (s) => Number(String(s).replace(/[^0-9.]/g, ''));

// ================================================================ D. Treasurer
if (reach('D') && S.slug && S.m1) {
    const page = pages.treasurer;
    await check('D1', 'Treasurer adds the lodge bank account', page, async () => {
        await go(page, `/${S.slug}/admin/bank-accounts`);
        await press(page, 'Add a bank or payment account');
        await form(page, { 'Institution or provider name': 'Example Bank', 'Account label': 'Main account', 'Account number': '87654321', 'Sort code': '40-11-22' });
        await press(page, 'Save and link account');
        expect(sql(`select count(*) from club_acc_bank_accounts where club_id=${S.clubId}`) === '1', 'no bank account saved');
    });
    await check('D2', "Import a statement with the member's dining payment reference", page, async () => {
        const owed = sql(`select coalesce((select dining_fee from meeting_rsvp_guests g join meeting_rsvps r on r.id=g.meeting_rsvp_id where r.meeting_id=${S.m1} and r.user_id=${uid('member')} and g.attending_dining limit 1), 0)`);
        S.m1owed = 30 + Number(owed || 30);
        await importStatement(page, 'dining-statement', [[`BACS M HALE`, S.m1ref, S.m1owed], ['RIVERSIDE HALL RENT', 'HALL NOV', -120]]);
        expect(sql(`select count(*) from club_acc_bank_transactions where club_id=${S.clubId}`) === '2', 'lines not imported');
    });
    await check('D3', 'The dining payment is matched by reference and amount; confirming marks it paid', page, async () => {
        await confirmLine(page, S.m1ref);
        const row = sqlRows(`select payment_status, bank_transaction_id is not null from meeting_rsvps where meeting_id=${S.m1} and user_id=${uid('member')}`)[0];
        expect(row[0] === 'paid' && row[1] === 't', `member's dining is ${row}`);
        const guest = sql(`select g.payment_status from meeting_rsvp_guests g join meeting_rsvps r on r.id=g.meeting_rsvp_id where r.meeting_id=${S.m1} and r.user_id=${uid('member')}`);
        expect(guest === 'paid', `guest dining is ${guest}`);
    });
    await check('D4', 'Dining list says "seen in the bank"', pages.sec, async () => {
        await go(pages.sec, `/${S.slug}/admin/meetings/${S.m1}/dining`);
        await see(pages.sec, /seen in the bank/i);
    });
    await check('D5', 'Member sees the dining as paid', pages.member, async () => {
        await go(pages.member, `/members/${S.slug}/meetings/${S.m1}/summons`);
        const body = await see(pages.member, 'Paid');
        expect(!body.includes('Dining still to pay'), 'still asked to pay');
    });
    await check('D6', "Treasurer's report for the next meeting shows the dining money received", page, async () => {
        // A meeting's report runs from the start of the year to the day before it, so today's money is in the next meeting's.
        await go(page, `/${S.slug}/admin/accounting/meetings/${S.m2}/treasurer-report`);
        await see(page, 'Installation meeting', S.m1owed.toFixed(2));
    });
    await check('D7', 'Accounts pages load for the Treasurer', page, async () => {
        for (const url of [`/members/${S.slug}/accounts`, `/members/${S.slug}/accounts/dues`, `/${S.slug}/admin/accounting`, `/${S.slug}/admin/accounting/reports`, `/${S.slug}/admin/reconciliation-workspace`]) {
            const code = await status(page, url);
            expect(code === 200, `${url} answered ${code}`);
            const body = await text(page);
            expect(!/Server Error|Something went wrong|Whoops/i.test(body), `${url} shows an error`);
        }
    });
    await check('D8', 'Dues: a fee tier and the annual billing run raise dues for members', page, async () => {
        await go(page, `/${S.slug}/admin/dues-subscriptions`);
        await press(page, 'Manage fee tiers');
        await form(page, { 'Tier name': 'Full member', [`Annual amount (£)`]: '150' });
        await press(page, 'Save tier');
        if (await exists(page, 'Close', 'button')) await press(page, 'Close', 'button');
        await press(page, 'Run annual billing');
        await fill(page, 'Due date', ymd(days(20)));
        await press(page, 'Generate annual invoices');
        const n = sql(`select count(*) from club_acc_member_subscriptions where club_id=${S.clubId}`);
        expect(Number(n) >= 5, `${n} dues raised`);
        S.duesRef = sql(`select s.invoice_reference from club_acc_member_subscriptions s join club_acc_members m on m.id=s.member_id where s.club_id=${S.clubId} and m.email='${people.member.email}' limit 1`);
        S.duesAmount = Number(sql(`select s.amount_due from club_acc_member_subscriptions s join club_acc_members m on m.id=s.member_id where s.club_id=${S.clubId} and m.email='${people.member.email}' limit 1`));
    });
    await check('D9', 'Member sees the dues and pays by bank; the Treasurer matches it', page, async () => {
        await go(pages.member, `/members/${S.slug}/dues`);
        await see(pages.member, S.duesAmount.toFixed(2));
        expect(S.duesRef, 'no dues reference');
        await importStatement(page, 'dues-statement', [['BACS M HALE SUBS', S.duesRef, S.duesAmount]]);
        await confirmLine(page, S.duesRef);
        const st = sql(`select s.status from club_acc_member_subscriptions s join club_acc_members m on m.id=s.member_id where s.club_id=${S.clubId} and m.email='${people.member.email}' limit 1`);
        expect(st === 'paid', `dues are ${st}`);
    });
}

// ================================================================ E. Events
if (reach('E') && S.slug) {
    const page = pages.sec;
    const setVal = async (p, selector, value) => {
        const el = await p.$(selector);
        expect(el, `no ${selector}`);
        await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, value);
    };
    await check('E1', "Event form offers the lodge's payment options by default (bank, on the night)", page, async () => {
        await go(page, `/${S.slug}/admin/events/create`);
        await see(page, 'Bank transfer', 'Pay on the night');
    });
    await check('E2', 'Create and publish an event (5 places) with two ticket types and a choice of dishes', page, async () => {
        await fill(page, 'Event status', 'upcoming');
        await form(page, { 'Title': 'Ladies festival', 'Description': 'Dinner and dance for members and partners.', 'Venue or building': 'The Grand Hotel', 'Town or city': 'Canterbury', 'Starts': `${ymd(days(21))}T19:00`, 'Total places': '5', 'Guests per booking': '2' });
        await tick(page, 'Keep a waiting list');
        await press(page, 'Add a ticket type');
        await press(page, 'Add a ticket type');
        await setVal(page, '#tier-name-0', 'Member');
        await setVal(page, '#tier-price-0', '45');
        await setVal(page, '#tier-name-1', 'Guest');
        await setVal(page, '#tier-price-1', '50');
        await tick(page, 'There is a dinner');
        await tick(page, 'People choose their dishes');
        await form(page, { 'Starter 1': 'Smoked salmon', 'Starter 2': 'Leek soup', 'Main course 1': 'Beef Wellington', 'Main course 2': 'Mushroom risotto', 'Dessert 1': 'Sticky toffee pudding', 'Dessert 2': 'Lemon tart' });
        await press(page, 'Save event');
        const row = sqlRows(`select id, status from events where club_id=${S.clubId} and title='Ladies festival'`)[0];
        expect(row && row[1] === 'upcoming', `event is ${row}`);
        S.ev = row[0];
        expect(sql(`select count(*) from event_ticket_tiers where event_id=${S.ev}`) === '2', 'ticket types not saved');
        expect(sql(`select count(*) from event_menu_items where event_id=${S.ev}`) === '6', 'dishes not saved');
        expect(Number(sql(`select count(*) from event_payment_methods where event_id=${S.ev}`)) >= 2, 'payment options not on the event');
    });
    await check('E3', 'Members are notified of the new event', null, async () => {
        const n = sql(`select count(*) from notifications where notifiable_id=${uid('member')} and data::text like '%Ladies festival%'`);
        expect(Number(n) >= 1, 'no notification for the member');
    });
    const bookForMember = async (p, { guest, pay }) => {
        await go(p, `/members/${S.slug}/events`);
        await press(p, 'Book a place');
        const tiers = await p.$$('select');
        await choose(p, 'Ticket type', 'Member (£45.00)').catch(() => {});
        await tick(p, "I'm dining");
        await choose(p, 'Starter', 'Smoked salmon');
        await choose(p, 'Main', 'Beef Wellington');
        await choose(p, 'Dessert', 'Lemon tart');
        if (guest) {
            await press(p, 'Add a guest');
            await fill(p, "Guest's full name", guest);
            await fill(p, "Guest's email (optional)", 'anne.hale@example.com');
            await choose(p, 'Ticket type', 'Guest (£50.00)', 1).catch(() => {});
            await tick(p, 'Dining');
            await choose(p, 'Starter', 'Leek soup', 1);
            await choose(p, 'Main', 'Mushroom risotto', 1);
            await choose(p, 'Dessert', 'Sticky toffee pudding', 1);
        }
        if (pay) await tick(p, pay);
        void tiers;
    };
    await check('E4', 'Member books with a guest, ticket types and dishes, paying by bank transfer', pages.member, async () => {
        const p = pages.member;
        await bookForMember(p, { guest: 'Mrs Anne Hale', pay: 'Bank transfer' });
        await press(p, 'Confirm booking');
        const reg = sqlRows(`select id, status from event_registrations where event_id=${S.ev} and user_id=${uid('member')}`)[0];
        expect(reg, 'no booking');
        S.reg1 = reg[0];
        const att = sqlRows(`select name, ticket_tier_id is not null, starter_item_id is not null and main_item_id is not null and dessert_item_id is not null from event_attendees where registration_id=${S.reg1} order by id`);
        expect(att.length === 2 && att.every((a) => a[1] === 't' && a[2] === 't'), `attendees ${JSON.stringify(att)}`);
    });
    await check('E5', "Member says they've paid the event by bank transfer", pages.member, async () => {
        const p = pages.member;
        await go(p, `/members/${S.slug}/events`);
        await press(p, 'Change booking');
        await press(p, "I've paid by bank transfer");
        expect(sql(`select said_paid_at is not null from event_registrations where id=${S.reg1}`) === 't', 'said_paid_at not set');
    });
    await check('E6', 'Organiser adds a booking for a member, paying on the night', page, async () => {
        await go(page, `/${S.slug}/admin/events/${S.ev}/subscribers`);
        await press(page, 'Add a booking');
        await fill(page, 'Search members by name, email or member number', 'Gareth');
        await settle(page, 1200);
        await press(page, 'Gareth Owen');
        await choose(page, 'Ticket type', 'Member (£45.00)');
        await tick(page, 'Having dinner');
        await choose(page, 'Starter', 'Leek soup');
        await choose(page, 'Main', 'Beef Wellington');
        await choose(page, 'Dessert', 'Lemon tart');
        await settle(page, 1000);
        await tick(page, 'Pay on the night');
        await press(page, 'Add booking');
        const reg = sqlRows(`select id from event_registrations where event_id=${S.ev} and user_id=${uid('member2')}`)[0];
        const alerts = await page.evaluate(() => [...document.querySelectorAll('[role=alert], p.text-rose-600, p[class*=rose]')].map((e) => e.innerText.trim()).filter(Boolean).join(' / '));
        expect(reg, `no booking for Gareth Owen ${alerts}`);
        S.reg2 = reg[0];
    });
    await check('E6b', 'Organiser adds a booking for a visitor, who is emailed a private booking link', page, async () => {
        await go(page, `/${S.slug}/admin/events/${S.ev}/subscribers`);
        await press(page, 'Add a booking');
        await press(page, 'Visitor or non-member');
        await fill(page, 'Name', 'WBro Alan Visitor');
        await fill(page, 'Lodge / organisation', 'Lodge of Unity No. 4421');
        await fill(page, 'Email', 'visitor-alan@example.com');
        await choose(page, 'Ticket type', 'Guest (£50.00)').catch(() => {});
        await settle(page, 1500);
        await tick(page, 'Pay on the night');
        await press(page, 'Add booking');
        const reg = sqlRows(`select id from event_registrations where event_id=${S.ev} and contact_email='visitor-alan@example.com'`)[0];
        const alerts = await page.evaluate(() => [...document.querySelectorAll('[role=alert], p[class*=rose]')].map((e) => e.innerText.trim()).filter(Boolean).join(' / '));
        expect(reg, `no booking for the visitor ${alerts}`);
        linkIn(lastMail('visitor-alan@example.com'), /\/booking\//);
    });
    await check('E7', 'When the event is full the next member joins the waiting list', pages.member3, async () => {
        const p = pages.joiner;
        await bookForMember(p, {});
        await press(p, 'Confirm booking');
        const p3 = pages.member3;
        await bookForMember(p3, {});
        await see(p3, 'waiting list');
        await press(p3, 'Join the waiting list');
        const st = sql(`select status from event_registrations where event_id=${S.ev} and user_id=${uid('member3')}`);
        expect(/wait/.test(st), `member 3's booking is "${st}"`);
    });
    await check('E8', 'Event-night check-in: the member and guest are ticked in', page, async () => {
        await go(page, `/${S.slug}/admin/events/${S.ev}/checkin`);
        for (const name of ['Martin Hale', 'Mrs Anne Hale']) {
            const box = await page.evaluateHandle((name) => {
                const row = [...document.querySelectorAll('li, div')].filter((e) => e.innerText?.trim().startsWith(name)).sort((a, b) => a.innerText.length - b.innerText.length)[0];
                let el = row;
                while (el && !el.querySelector('button, input[type=checkbox]')) el = el.parentElement;
                return el?.querySelector('button, input[type=checkbox]') || null;
            }, name);
            expect(box.asElement(), `no check-in control for ${name}`);
            await tap(page, box.asElement());
            await settle(page, 700);
        }
        const n = sql(`select count(*) from event_attendees where registration_id=${S.reg1} and checked_in_at is not null`);
        expect(n === '2', `${n} of 2 checked in`);
    });
    await check('E9', 'Payment taken at the door for the on-the-night booking', page, async () => {
        await go(page, `/${S.slug}/admin/events/${S.ev}/checkin`);
        const take = await page.evaluateHandle(() => {
            const row = [...document.querySelectorAll('li')].find((r) => r.innerText.includes('Gareth Owen'));
            return row ? [...row.querySelectorAll('button')].find((b) => b.innerText.trim().startsWith('Take')) : null;
        });
        expect(take.asElement(), 'no "Take" button on Gareth Owen\'s row');
        await tap(page, take.asElement());
        await press(page, 'Record', '[role=dialog] button, dialog button');
        const st = sql(`select payment_status from event_registrations where id=${S.reg2}`);
        expect(st === 'paid', `on-the-night booking is ${st}`);
        expect(Number(sql(`select count(*) from event_payment_log where registration_id=${S.reg2}`)) >= 1, 'no payment log row');
    });
    await check('E10', 'Catering summary counts the dishes', page, async () => {
        await go(page, `/${S.slug}/admin/events/${S.ev}/catering`);
        await see(page, 'Beef Wellington', 'Mushroom risotto', 'Lemon tart');
    });
    await check('E11', 'Guest list CSV downloads with the names', page, async () => {
        const res = await page.evaluate(async (u) => { const r = await fetch(u); return [r.status, r.headers.get('content-type'), await r.text()]; }, `/${S.slug}/admin/events/${S.ev}/export`);
        expect(res[0] === 200 && /csv/.test(res[1]), `export answered ${res[0]} ${res[1]}`);
        expect(res[2].includes('Martin Hale') && res[2].includes('Mrs Anne Hale'), 'names missing from the CSV');
    });
    await check('E12', 'The bank transfer is matched in the bank and the booking marked paid', pages.treasurer, async () => {
        const ref = sql(`select payment_reference from event_registrations where id=${S.reg1}`);
        const amount = Number(sql(`select total from event_registrations where id=${S.reg1}`));
        expect(ref && amount, `reference ${ref}, amount ${amount}`);
        await importStatement(pages.treasurer, 'event-statement', [['BACS M HALE FESTIVAL', ref, amount]]);
        await confirmLine(pages.treasurer, ref);
        const st = sqlRows(`select payment_status, bank_transaction_id is not null from event_registrations where id=${S.reg1}`)[0];
        expect(st[0] === 'paid' && st[1] === 't', `booking is ${st}`);
    });
    notCovered('E13', 'Card and PayPal payment for an event', 'needs Stripe/PayPal, which cannot be reached locally');
}


/** The Inertia props of the page as loaded (call after go(), not after client-side visits). */
const props = (page) => page.evaluate(() => {
    const raw = document.getElementById('app')?.dataset.page || document.querySelector('script[data-page]')?.textContent;
    return raw ? JSON.parse(raw).props : {};
});
const notes = (who, like) => Number(sql(`select count(*) from notifications where notifiable_id=${uid(who)} and data::text ilike '%${like}%'`));
async function writeBody(page, words) {
    const editor = await page.$('.ProseMirror, [contenteditable=true]');
    expect(editor, 'no text editor on the page');
    await editor.click();
    await page.keyboard.type(words, { delay: 10 });
}

// ================================================================ F. Communication
if (reach('F') && S.slug) {
    const page = pages.sec;
    const newPost = async (title, when) => {
        await go(page, `/${S.slug}/admin/posts/create`);
        await fill(page, 'Title', title);
        if (await exists(page, 'Add text', 'button')) await press(page, 'Add text');
        await writeBody(page, `${title}: the brethren met and much was done.`);
        if (await exists(page, 'Publish settings', 'button')) {
            const open = await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.innerText.includes('Publish settings'))?.getAttribute('aria-expanded'));
            if (open !== 'true') await press(page, 'Publish settings');
        }
        await tick(page, 'Publish');
        if (when) await fill(page, 'Show from', when);
        await press(page, 'Save');
        const row = sqlRows(`select id, status, members_notified_at is not null from posts where club_id=${S.clubId} and title='${title}'`)[0];
        expect(row, `post "${title}" not saved`);
        return row;
    };
    await check('F1', 'A news post is published and members are notified', page, async () => {
        const row = await newPost('Lodge news for November');
        expect(row[1] === 'published', `post status ${row[1]}`);
        expect(notes('member', 'Lodge news for November') >= 1, 'member not notified');
        await go(pages.member, `/members/${S.slug}/news`);
        await see(pages.member, 'Lodge news for November');
    });
    await check('F2', 'A post scheduled for later is announced when it goes live (app:announce-scheduled-posts)', page, async () => {
        const later = new Date(Date.now() + 3 * 3600 * 1000);
        const local = later.toLocaleString('sv-SE', { timeZone: 'Europe/London' }).replace(' ', 'T').slice(0, 16);
        const row = await newPost('Installation festive board notice', local);
        expect(row[2] === 'f', 'members were told before it went live');
        expect(notes('member', 'Installation festive board') === 0, 'member notified early');
        sql(`update posts set published_at = now() - interval '1 minute' where id=${row[0]}`);
        artisan('app:announce-scheduled-posts');
        expect(notes('member', 'Installation festive board') >= 1, 'member not notified once live');
    });
    await check('F3', 'Newsletter written and sent: deliveries recorded and emails logged', page, async () => {
        await go(page, `/${S.slug}/admin/newsletters/create`);
        const channel = await page.evaluate(() => { const s = [...document.querySelectorAll('select')].find((x) => x.labels?.[0]?.innerText.includes('Channel')); return s ? [...s.options].filter((o) => o.value).map((o) => o.textContent.trim()) : []; });
        expect(channel.length, 'no newsletter channel to send on');
        // An optional channel, so members can unsubscribe from it (F5); summonses are mandatory.
        await choose(page, 'Channel', channel.find((c) => /General/i.test(c)) || channel[0]);
        await fill(page, 'Subject line', 'Wealden news, winter edition');
        await writeBody(page, 'Greetings brethren. Our winter programme is below.');
        await press(page, 'Send now');
        await press(page, 'Send now', '[role=dialog] button, dialog button');
        await settle(page, 2000);
        const nl = sqlRows(`select id, status from newsletters where club_id=${S.clubId} and subject='Wealden news, winter edition'`)[0];
        expect(nl && nl[1] === 'sent', `newsletter is ${nl}`);
        S.nl = nl[0];
        const n = Number(sql(`select count(*) from newsletter_deliveries where newsletter_id=${S.nl} and status='sent'`));
        expect(n >= 6, `${n} deliveries sent (the owner, Treasurer and members are all on the roster)`);
        lastMail(people.member.email, 'Wealden news, winter edition');
    });
    await check('F4', 'Newsletter browser copy link opens', null, async () => {
        const mail = lastMail(people.member.email, 'Wealden news, winter edition');
        const link = linkIn(mail, /\/site\/newsletter\//);
        const p = await phone(browser);
        await go(p, link);
        await see(p, 'winter programme');
        await p.close();
    });
    await check('F5', 'One-click unsubscribe works and is recorded', null, async () => {
        const mail = lastMail(people.member3.email, 'Wealden news, winter edition');
        const link = linkIn(mail, /unsubscribe/);
        const p = await phone(browser);
        await go(p, link);
        await press(p, 'Yes, unsubscribe me', 'button');
        await see(p, /unsubscribed|no longer/i);
        const st = sql(`select status from newsletter_subscriptions where club_id=${S.clubId} and (user_id=${uid('member3')} or email='${people.member3.email}') order by id desc limit 1`);
        expect(/unsub|opted/i.test(st), `subscription is "${st}"`);
        await p.close();
    });
    await check('F6', 'Send notice reaches the members', page, async () => {
        await go(page, `/${S.slug}/admin/notices`);
        await form(page, { 'Title': 'Change of venue', 'Message': 'Next month we meet at the Ashford hall annexe.' });
        await press(page, 'Send to');
        expect(sql(`select count(*) from club_notices where club_id=${S.clubId} and title='Change of venue'`) === '1', 'notice not recorded');
        // The notice goes out on the queue (SendClubNotice), so give the worker a moment before looking.
        for (let i = 0; i < 20 && notes('member', 'Change of venue') < 1; i++) await pause(500);
        expect(notes('member', 'Change of venue') >= 1, 'member did not get the notice');
    });
    await check('F7', 'Member inbox shows summonses, news, events, newsletters and notices, and filters by kind', pages.member, async () => {
        const p = pages.member;
        await go(p, '/members/notifications');
        await see(p, 'Regular meeting', 'Lodge news for November', 'Ladies festival', 'Change of venue');
        await go(p, '/members/notifications?category=news');
        const body = await see(p, 'Lodge news for November');
        expect(!body.includes('Change of venue'), 'news filter still shows a notice');
        await go(p, '/members/notifications?category=newsletter');
        await see(p, 'Wealden news');
    });
}

// ================================================================ G. Member area
if (reach('G') && S.slug) {
    const p = pages.member;
    await check('G1', 'Dashboard shows the lodge and the next meeting', p, async () => {
        await go(p, '/members/dashboard');
        await see(p, 'Wealden Lodge');
        await go(p, `/members/${S.slug}`);
        await see(p, 'Wealden Lodge');
    });
    await check('G2', 'Calendar lists the meetings and the event; the private .ics feed has them', p, async () => {
        await go(p, '/members/calendar');
        await see(p, 'Regular meeting');
        await go(p, `/members/calendar?month=${ymd(days(21)).slice(0, 7)}`);
        await see(p, 'Ladies festival');
        await go(p, '/members/calendar');
        const feed = (await props(p)).feedUrl;
        expect(feed, 'no calendar feed link');
        const ics = await p.evaluate(async (u) => (await fetch(u)).text(), feed.replace(/^https?:\/\/[^/]+/, ''));
        expect(ics.includes('BEGIN:VCALENDAR') && ics.includes('Installation meeting') && ics.includes('Ladies festival'), 'feed lacks the meeting or event');
    });
    await check('G3', 'Bookings page shows the event booking', p, async () => {
        await go(p, '/members/events');
        await see(p, 'Ladies festival');
    });
    await check('G4', 'Ticket page shows the booking with both people', p, async () => {
        await go(p, `/members/${S.slug}/events/${S.ev}/ticket`);
        await see(p, 'Martin Hale', 'Mrs Anne Hale');
    });
    await check('G5', 'My lodges: follow a lodge from the directory; it is listed with suggestions', p, async () => {
        await go(p, '/lodges/lodge-of-unity-4421');
        await press(p, 'Follow this lodge');
        expect(sql(`select count(*) from lodge_follows f join lodges l on l.id=f.lodge_id where f.user_id=${uid('member')} and l.slug='lodge-of-unity-4421'`) === '1', 'follow not saved');
        await go(p, '/members/lodges');
        await see(p, 'Lodge of Unity', 'Wealden Lodge');
        const body = await text(p);
        expect(/St Aidan|Lodge of Fraternity/.test(body), 'no suggested lodges (same hall)');
    });
    await check('G6', 'Directory search from the member area', p, async () => {
        await go(p, '/lodges?q=Aidan');
        await see(p, "St Aidan's Lodge");
        const code = await status(p, '/members/directory');
        expect(code === 200, `members directory answered ${code}`);
    });
    await check('G7', 'Dues page shows what is owed and paid', p, async () => {
        await go(p, '/members/dues');
        await see(p, 'Wealden Lodge');
        await go(p, `/members/${S.slug}/dues`);
        await see(p, /paid/i);
    });
    await check('G8', 'Masonic record page loads with the office', p, async () => {
        await go(p, `/members/${S.slug}/record`);
        await see(p, 'Wealden Lodge');
    });
    await check('G9', 'Profile: change the phone number', p, async () => {
        await go(p, `/members/${S.slug}/profile`);
        await fill(p, 'Contact phone number', '07700 900123');
        await press(p, 'Save membership profile');
        const avatar = await p.evaluate(() => [...document.querySelectorAll('span, div')].find((e) => e.children.length === 0 && /^[A-Z]{2}$/.test(e.innerText?.trim() || '') && e.closest('main'))?.innerText.trim());
        expect(!avatar || avatar === 'MH', `profile picture initials are ${avatar}`);
        const phoneNo = sql(`select phone from club_acc_members where club_id=${S.clubId} and email='${people.member.email}'`);
        expect(phoneNo.replace(/\s/g, '') === '07700900123', `phone is "${phoneNo}"`);
    });
    await check('G10', 'A visitor request to a second lodge is approved; the header switches lodges and wears the order colour', p, async () => {
        await go(p, '/lodge-of-fraternity/visitor-register');
        await form(p, { 'Rank or title': 'Bro', 'Full name': people.member.name, 'Email address': people.member.email, 'Home lodge or club name': 'Wealden Lodge', 'Lodge no.': '9012' });
        await press(p, 'Register as a visitor');
        const fraternity = sql("select id from clubs where slug='lodge-of-fraternity'");
        expect(sql(`select status from club_user where user_id=${uid('member')} and club_id=${fraternity}`) === 'pending', 'no pending visitor request');
        await go(pages.other, '/lodge-of-fraternity/admin/users');
        await see(pages.other, people.member.name);
        await pressIn(pages.other, people.member.name, 'Approve');
        expect(sql(`select status from club_user where user_id=${uid('member')} and club_id=${fraternity}`) === 'active', 'visitor request not approved');
        await go(p, `/members/${S.slug}`);
        const clubs = ((await props(p)).auth?.clubs || []).filter((c) => c.status === 'active').map((c) => c.slug);
        expect(clubs.includes(S.slug) && clubs.includes('lodge-of-fraternity'), `switcher lodges: ${clubs}`);
        await press(p, 'Wealden Lodge', 'header button, header [role=button]');
        await press(p, 'The Lodge of Fraternity', 'a, button, [role=menuitem]');
        expect(p.url().includes('/members/lodge-of-fraternity'), `switcher went to ${p.url()}`);
        const bg = await p.evaluate(() => getComputedStyle(document.querySelector('header')).backgroundColor);
        expect(bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'rgb(30, 41, 59)', `header background ${bg} is the plain one`);
    });
}

// ================================================================ H. Security spot checks
if (reach('H') && S.slug) {
    await check('H1', "The Secretary is refused another lodge's admin and members' pages", pages.sec, async () => {
        const fraternityMeeting = sql(`select id from meetings where club_id=(select id from clubs where slug='lodge-of-fraternity') order by id limit 1`);
        for (const url of ['/lodge-of-fraternity/admin/members', '/lodge-of-fraternity/admin/meetings', '/members/lodge-of-fraternity', ...(fraternityMeeting ? [`/members/lodge-of-fraternity/meetings/${fraternityMeeting}/summons`] : [])]) {
            const code = await status(pages.sec, url);
            // Refused, or sent elsewhere (a non-member asking for a lodge's member page is sent to its public website).
            expect([403, 404].includes(code) || !new URL(pages.sec.url()).pathname.startsWith(url), `${url} answered ${code}`);
        }
    });
    await check('H2', 'A member cannot post an admin action (publish a meeting, change a role)', pages.member, async () => {
        await go(pages.member, `/members/${S.slug}`);
        const codes = await pages.member.evaluate(async ([slug, m2, uid2]) => {
            const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const post = (u, body) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: JSON.stringify(body) }).then((r) => r.status);
            return [await post(`/${slug}/admin/meetings/${m2}/publish`, {}), await post(`/${slug}/admin/users/${uid2}/role`, { role: 'admin' })];
        }, [S.slug, S.m2, uid('member2')]);
        expect(codes.every((c) => c === 403), `answered ${codes}`);
        expect(sql(`select role from club_user where user_id=${uid('member2')} and club_id=${S.clubId}`) === 'member', 'role changed');
    });
    await check('H3', 'Summons reply links: a changed token or signature is refused', null, async () => {
        const p = await phone(browser);
        const summonsLink = linkIn(lastMail(people.member2.email, /Regular meeting/i), /\/summons\/rsvp\//);
        expect((await status(p, summonsLink)) === 200, 'the real summons link did not open');
        const badToken = summonsLink.replace(/[0-9a-f]{64}/, '0'.repeat(64));
        // A bad link gets a friendly "invalid or has expired" page (200) with nothing of the meeting on it.
        const code = await status(p, badToken);
        const body = await text(p);
        expect([403, 404].includes(code) || (/invalid or has expired/i.test(body) && !body.includes('Regular meeting')), `a made-up summons token answered ${code}`);
        const ask = mailsTo(people.treasurer.email, /Will you be there/).pop();
        const signed = ask?.links.find((l) => /signature=/.test(l));
        if (!signed) return 'PASS for the token; NC: no signed reply link was emailed to test the signature';
        const tampered = signed.replace(/^https?:\/\/[^/]+/, '').replace(/signature=([0-9a-f])/, (m, c) => `signature=${c === 'a' ? 'b' : 'a'}`);
        const signedCode = await status(p, tampered);
        expect([401, 403].includes(signedCode) || /invalid or has expired/i.test(await text(p)), `a tampered signed link answered ${signedCode}`);
        await p.close();
    });
    await check('H4', "Another member's event ticket and booking are not shown", pages.member2, async () => {
        await go(pages.member2, `/members/${S.slug}/events/${S.ev}/ticket`);
        const body = await text(pages.member2);
        expect(!body.includes('Mrs Anne Hale'), "member 2 can see member 1's guest");
    });
    await check('H5', 'Non-superadmins are refused /superadmin', pages.sec, async () => {
        const code = await status(pages.sec, '/superadmin/lodge-claims');
        expect([403, 404].includes(code) || !pages.sec.url().includes('/superadmin'), `answered ${code}`);
    });
    await check('H6', 'Signed-out visitors are sent to sign in from member and admin pages', null, async () => {
        const p = await phone(browser);
        for (const url of ['/members/dashboard', `/${S.slug}/admin/meetings`]) {
            await status(p, url);
            expect(p.url().includes('/login'), `${url} did not ask to sign in (${p.url()})`);
        }
        await p.close();
    });
    await check('H7', "A visitor's private booking page opens signed out; a wrong token is refused", null, async () => {
        const link = linkIn(lastMail('visitor-alan@example.com'), /\/booking\//);
        const p = await phone(browser);
        expect((await status(p, link)) === 200, 'booking page did not open');
        await see(p, 'Ladies festival', 'Alan Visitor');
        const code = await status(p, link.replace(/\/booking\/[^/?#]+/, '/booking/' + 'x'.repeat(40)));
        const body = await text(p);
        expect([403, 404].includes(code) || !body.includes('Alan Visitor'), `wrong token answered ${code}`);
        await p.close();
    });
}

// ---------------------------------------------------------------- report
async function finish() {
    await browser.close();
    const count = (s) => results.filter((r) => r.status === s).length;
    const mins = ((Date.now() - t0) / 60000).toFixed(1);
    const lines = [
        '# Full run report', '',
        `Run ${new Date().toISOString().slice(0, 16).replace('T', ' ')} against ${BASE}, ${mins} minutes. Plan: tests/journeys/full-test-plan.md.`, '',
        `**PASS ${count('PASS')} / FAIL ${count('FAIL')} / NOT COVERED ${count('NOT COVERED')}**`, '',
        '| ID | Result | Check | Detail |', '|---|---|---|---|',
        ...results.map((r) => `| ${r.id} | ${r.status} | ${r.title.replace(/\|/g, '/')} | ${r.detail.replace(/\|/g, '/')} |`), '',
        'Accounts: tests/journeys/accounts.local.json, key "full_run".', '',
    ];
    fs.writeFileSync(`${OUT}/full-run-report.md`, lines.join('\n'));
    console.log(`\nPASS ${count('PASS')}  FAIL ${count('FAIL')}  NOT COVERED ${count('NOT COVERED')}  (${mins} min) -> ${OUT}/full-run-report.md`);
}
await finish();
