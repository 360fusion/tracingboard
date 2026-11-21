// Runs the full end-to-end plan (full-run.mjs) against staging, staging.manager.360fusionhosting.co.uk.
// The local script is turned into a staging one on the fly, so the two never drift apart:
//   - the database and artisan are reached over SSH (forge@167.99.193.125), never reset;
//   - emails are read from Mailpit on the server (staging sends all mail there), waiting for the queue;
//   - every run makes fresh accounts (run-<who>.<stamp>@example.com) and its own made-up lodge to claim,
//     because staging's directory holds the real lodges (9012 is a real one there);
//   - checks that lean on the local demo's other lodge (Lodge of Fraternity is a real lodge's club on staging)
//     are reported NOT COVERED instead of touching it.
// Usage: node tests/journeys/staging-run.mjs   (UNTIL=C to stop after a section)
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const HOST = 'staging.manager.360fusionhosting.co.uk';
const SSH = ['-o', 'BatchMode=yes', '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/cm-journey-%r@%h-%p', '-o', 'ControlPersist=20m', 'forge@167.99.193.125'];
const STAMP = Date.now().toString(36).slice(-5);
const LODGE_NO = String(99000 + (Date.now() % 1000));

// Made-up lodges for this run, in a made-up hall: one to claim, two neighbours for the follow and search checks.
const setup = `
$craft = App\\Models\\ClubType::where('code', 'craft_lodge')->firstOrFail();
$hall = App\\Models\\MasonicHall::firstOrCreate(['slug' => 'riverside-masonic-hall-journey-test'], ['name' => 'Riverside Masonic Hall (journey test)', 'address_line_1' => '12 Mill Lane', 'town' => 'Ashford', 'postcode' => 'TN24 8AA', 'country' => 'England']);
foreach ([['Wealden Lodge', '${LODGE_NO}'], ['Lodge of Unity', '99421'], ["St Aidan's Lodge", '99310']] as [$name, $number]) {
    App\\Models\\Lodge::firstOrCreate(['slug' => Illuminate\\Support\\Str::slug($name.' '.$number)], ['club_type_id' => $craft->id, 'name' => $name, 'number' => $number, 'status' => 'active', 'masonic_hall_id' => $hall->id]);
}
echo 'ok';`;
const out = execFileSync('ssh', [...SSH, 'cd ~/manager.360fusionhosting.co.uk/current && php artisan tinker --no-interaction'], { input: setup }).toString();
if (!out.includes('ok')) throw new Error('setting up the test lodges failed: ' + out);

let src = fs.readFileSync(`${HERE}/full-run.mjs`, 'utf8');
const swap = (from, to, all = false) => {
    if (!src.includes(from)) throw new Error(`full-run.mjs changed: cannot find ${JSON.stringify(from.slice(0, 80))}`);
    src = all ? src.split(from).join(to) : src.replace(from, to);
};

// Accounts: fresh addresses each run; SQL that names the Secretary's address uses the variable.
src = src.replace(/run-([a-z0-9]+)@example\.com/g, `run-$1.${STAMP}@example.com`);

// Database and artisan over SSH; no demo reset.
swap("const artisan = (...args) => sh('php', ['artisan', ...args]);", `
const SSH = ${JSON.stringify(SSH)};
const remote = (cmd, input) => execFileSync('ssh', [...SSH, cmd], { input, maxBuffer: 64e6 }).toString();
const shq = (s) => "'" + String(s).replace(/'/g, "'\\\\''") + "'";
const artisan = (...args) => remote('cd ~/manager.360fusionhosting.co.uk/current && php artisan ' + args.map(shq).join(' ') + ' --no-interaction');
const sleepSync = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
`);
swap("const pgpass = (fs.readFileSync(`${ROOT}/.env`, 'utf8').match(/^DB_PASSWORD=(.*)$/m) || [])[1]?.replace(/\"/g, '') || '';", '');
src = src.replace(/const sql = \(q\) => sh\('psql'.*\n/, `const sql = (q) => remote(\`cd ~/manager.360fusionhosting.co.uk && e(){ grep "^$1=" .env | cut -d= -f2- | tr -d '"'; } && PGPASSWORD="$(e DB_PASSWORD)" psql -h 127.0.0.1 -U "$(e DB_USERNAME)" -d "$(e DB_DATABASE)" -qAt -v ON_ERROR_STOP=1 -f -\`, q).trim();\n`);
swap("    console.log('Rebuilding the demo database...');\n    sh('sh', ['tests/journeys/reset-demo.sh']);", "    console.log('Staging: nothing is reset.');");
swap('logStart = fs.statSync(LOG).size;', 'logStart = Date.now() - 5000;');

// Emails from Mailpit, once the queue has sent them.
const mailsStart = src.indexOf('function mails() {');
const mailsEnd = src.indexOf('const mailsTo =');
if (mailsStart < 0 || mailsEnd < 0) throw new Error('full-run.mjs changed: mails() not found');
src = src.slice(0, mailsStart) + `const bodies = new Map();
/** Waits (up to a minute) until the queue has no mail or notification jobs left. */
function drain() {
    for (let i = 0; i < 30; i++) {
        if (sql("select count(*) from jobs where queue in ('transactional','default','bulk') and available_at <= extract(epoch from now())") === '0') return;
        sleepSync(2000);
    }
}
function mails() {
    drain();
    const list = JSON.parse(remote("curl -s 'http://127.0.0.1:8025/api/v1/messages?limit=2000'")).messages || [];
    return list.filter((m) => Date.parse(m.Created) >= logStart).reverse().map((m) => {
        if (!bodies.has(m.ID)) bodies.set(m.ID, JSON.parse(remote('curl -s http://127.0.0.1:8025/api/v1/message/' + m.ID)));
        const d = bodies.get(m.ID);
        const body = (d.Text || '') + '\\n' + (d.HTML || '');
        const links = [...new Set([...body.matchAll(/https?:\\/\\/[^\\s"'<>)]+/g)].map((x) => x[0].replace(/&amp;/g, '&')))];
        return { to: [...(m.To || []), ...(m.Cc || []), ...(m.Bcc || [])].map((t) => t.Address).join(', '), subject: m.Subject, body, links };
    });
}
` + src.slice(mailsEnd);
swap('function lastMail(email, subject) {\n    const list = mailsTo(email, subject);', `function lastMail(email, subject) {
    let list = mailsTo(email, subject);
    for (let i = 0; i < 8 && !list.length; i++) { sleepSync(3000); list = mailsTo(email, subject); }`);

// Staging asks new accounts to confirm their email: open the link in a tab of its own, so the sign-up page stays as it was.
swap("    await press(page, 'Register account');\n    return page;", `    await press(page, 'Register account');
    const confirm = lastMail(email, /verif|confirm/i);
    const link = confirm.links.find((l) => /\\/email\\/verify\\//.test(l));
    if (!link) throw new Error('no confirm link emailed to ' + email);
    const tab = await browser.newPage();
    await tab.goto(link.replace(/^https?:\\/\\/[^/]+/, BASE), { waitUntil: 'networkidle0' });
    await tab.close();
    return page;`);

// Staging's sign-in page asks a new account to confirm its email instead of saying it is ready.
swap("await see(page, 'Your account is ready');", "await see(page, /Your account is ready|Check your email for a link to confirm/);");

// Outside local, /superadmin needs two-factor: the superadmin turns it on through the app's own routes,
// working out the six-digit code from the key the app gives (RFC 6238). The key is kept with the logins.
swap("    artisan('superadmin:grant', sa.email);", `    artisan('superadmin:grant', sa.email);
    {
        const call = (url, method = 'POST', body = {}) => page.evaluate(async ([url, method, body]) => {
            const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: method === 'GET' ? undefined : JSON.stringify(body) });
            return { status: r.status, body: await r.text() };
        }, [url, method, body]);
        const totp = (secret) => {
            const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
            let bits = '';
            for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
            const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
            const counter = Buffer.alloc(8);
            counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
            const h = crypto.createHmac('sha1', key).update(counter).digest();
            const o = h[h.length - 1] & 15;
            return String(((h.readUInt32BE(o) & 0x7fffffff) % 1000000)).padStart(6, '0');
        };
        await go(page, '/members/security');
        const steps = [await call('/user/confirm-password', 'POST', { password: sa.password }), await call('/user/two-factor-authentication')];
        const key = await call('/user/two-factor-secret-key', 'GET');
        sa.two_factor_secret = JSON.parse(key.body).secretKey;
        const done = await call('/user/confirmed-two-factor-authentication', 'POST', { code: totp(sa.two_factor_secret) });
        saveAccounts();
        if (done.status >= 300) console.log('two-factor set-up answered', steps.map((s) => s.status), key.status, done.status, done.body.slice(0, 200));
    }`);

// The lodge to claim is this run's made-up one.
swap("'/lodges/wealden-lodge-9012'", `'/lodges/wealden-lodge-${LODGE_NO}'`, true);
src = src.replace(/lodge-of-unity-4421/g, 'lodge-of-unity-99421');
swap("site: `${BASE} (demo database club_manager_demo)`, lodge: 'Wealden Lodge No. 9012'", `site: 'https://${HOST} (staging)', lodge: 'Wealden Lodge No. ${LODGE_NO} (made up for this run)'`);
// Staging keeps one set of logins per run under its own key, beside the local ones.
swap('file.full_run = {', `file.staging_runs = file.staging_runs || {}; file.staging_runs['${STAMP}'] = {`);

// Checks that need the local demo's second lodge and its admin (admin@example.com) are not run here.
const skip = (id, why) => {
    const re = new RegExp(`await check\\('${id}', `);
    if (!re.test(src)) throw new Error(`full-run.mjs changed: check ${id} not found`);
    src = src.replace(re, `notCovered('${id}', 'staging', '${why}'); if (false) await check('${id}', `);
};
skip('A19', 'needs a second lodge run by a test account; Lodge of Fraternity is a real lodge club on staging');
skip('G10', 'would make a test member a visitor at Lodge of Fraternity, a real lodge club on staging');

// The report goes beside the local one, not over it.
swap('full-run-report.md', 'staging-run-report.md', true);
swap("'# Full run report'", "'# Staging run report'");
swap('key "full_run"', `key "staging_runs" → "${STAMP}"`);

const target = `${HERE}/out/staging-run.generated.mjs`;
fs.mkdirSync(`${HERE}/out`, { recursive: true });
fs.writeFileSync(target, src.replace("from './lib.mjs'", "from '../lib.mjs'"));
process.env.DEMO_URL = `https://${HOST}`;
console.log(`Staging run ${STAMP}: accounts run-<who>.${STAMP}@example.com, lodge Wealden Lodge No. ${LODGE_NO}`);
await import(target);
