// Click-through of every screen on the throwaway site (run by bin/e2e.sh after the CLI scenarios).
// Env: BASE_URL, WP_CLI, SHOTS (optional folder for screenshots). Needs Playwright (NODE_PATH to the global install).
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BASE_URL;
const shots = process.env.SHOTS || '';
if (shots) mkdirSync(shots, { recursive: true });
let pass = 0;
let fails = 0;
const ok = (cond, msg) => {
	if (cond) pass++;
	else {
		fails++;
		console.log('FAIL: ui ' + msg);
	}
};
const wp = (code) => execSync(`${process.env.WP_CLI} eval '${code}' 2>/dev/null`, { encoding: 'utf8' }).trim().split('\n').pop();

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const problems = [];
page.on('pageerror', (e) => problems.push('pageerror ' + e.message));
page.on('console', (m) => {
	if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) problems.push('console ' + m.text());
});

const shot = async (name) => {
	if (shots) await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true });
};
const visit = async (path, name) => {
	let res = await page.goto(base + path, { waitUntil: 'load' }).catch(async () => {
		await page.waitForLoadState('networkidle').catch(() => {});
		return page.goto(base + path, { waitUntil: 'load' });
	});
	const text = await page.locator('body').innerText();
	ok(res && res.status() === 200, `${name} answers 200`);
	ok(!/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace)/.test(text), `${name} shows no PHP errors`);
	await shot(name);
	return text;
};

await page.goto(base + '/wp-login.php');
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

const root = '/wp-admin/tools.php?page=update-airlock';
let text = await visit(root, 'overview');
ok(/Update Airlock/.test(text), 'overview heading');
ok(/Recent activity/.test(text), 'overview lists recent activity');
ok(/FX Fatal/.test(text), 'overview names a rolled-back plugin');
ok((await page.locator('.updlock-badge, [class*="updlock-"]').count()) > 0, 'overview uses plugin styles');

text = await visit(root + '&tab=welcome', 'welcome');
ok(/Set up in three steps/.test(text), 'welcome steps');
await page.click('#updlock-baseline-start');
await page.waitForFunction(() => /Done|captured|pages/i.test(document.querySelector('#updlock-baseline-start')?.closest('div,section,li')?.innerText || ''), null, { timeout: 60000 }).catch(() => {});
await shot('welcome-after-baseline');
ok(wp('echo count( Updlock\\Check\\Runner::baseline()["urls"] ) >= 3 ? "yes" : "no";') === 'yes', 'baseline button captured pages');

text = await visit(root + '&tab=history', 'history');
ok(/FX Evil/.test(text) && /FX Fatal/.test(text), 'history lists events');
await page.selectOption('select >> nth=0', { index: 1 }).catch(() => {});
await page.click('button:has-text("Filter")').catch(() => {});
await shot('history-filtered');

// Event report for the rolled-back plugin: findings, files, checks, side-by-side diff.
const fatalId = wp('foreach ( Updlock\\Core\\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "fx-fatal" === $r["slug"] ) { echo $r["id"]; break; } }');
text = await visit(`${root}&view=event&id=${fatalId}`, 'event-rolled-back');
ok(/Rolled back/i.test(text), 'event shows rolled back');
ok(/Site checks/.test(text), 'event shows site checks');

const evilId = wp('foreach ( Updlock\\Core\\Events::list( "", "", "", 100, 0 ) as $r ) { if ( "fx-evil" === $r["slug"] ) { echo $r["id"]; break; } }');
text = await visit(`${root}&view=event&id=${evilId}`, 'event-high');
ok(/Findings/.test(text), 'high event lists findings');
ok(/analytics\.fixture-cdn\.test/.test(text), 'high event names the new host');
const diffLink = page.locator('a[href*="view=diff"]').first();
if (await diffLink.count()) {
	await diffLink.click();
	await page.waitForLoadState('load');
	ok(/fx-evil/.test(await page.locator('body').innerText()), 'diff view opens');
	await shot('diff');
} else {
	ok(false, 'event has a link to the diff');
}

text = await visit(root + '&tab=snapshots', 'snapshots');
ok(/Snapshots|snapshot/i.test(text), 'snapshots screen');

text = await visit(root + '&tab=settings', 'settings');
ok(/Hold|hold/.test(text), 'settings mention holds');
await page.check('input[name*="keep_data"]').catch(() => {});
await page.click('#submit, input[type=submit]').catch(() => {});
await page.waitForLoadState('load');
ok(wp('echo ! empty( get_option( "updlock_settings" )["keep_data"] ) ? "on" : "off";') === 'on', 'settings save');
await page.goto(base + root + '&tab=settings');
await page.uncheck('input[name*="keep_data"]').catch(() => {});
await page.click('#submit, input[type=submit]').catch(() => {});
await page.waitForLoadState('load');

// Export downloads.
const [download] = await Promise.all([page.waitForEvent('download'), page.goto(base + root + '&tab=history').then(() => page.click('text=/Download CSV|CSV/i').catch(() => {}))]).catch(() => [null]);
ok(!!download, 'CSV export downloads');

// Core screens that carry our additions.
text = await visit('/wp-admin/plugins.php', 'plugins');
ok(/Update Airlock/.test(text), 'plugins screen lists the plugin');
await visit('/wp-admin/update-core.php', 'update-core');
text = await visit('/wp-admin/index.php', 'dashboard');
ok(/Update Airlock/.test(text), 'dashboard widget');
text = await visit('/wp-admin/site-health.php', 'site-health');

// Inspect an available update from the screen (chunked, in the browser).
await page.goto(base + root);
const inspect = page.locator('a:has-text("Inspect this update"), a:has-text("Inspect again")').first();
if (await inspect.count()) {
	await inspect.click();
	await page.waitForLoadState('load');
	await page.waitForFunction(() => /event|Done|Findings|rated/i.test(document.body.innerText), null, { timeout: 60000 }).catch(() => {});
	await shot('inspect-progress');
	ok(!/(Fatal error|Warning:)/.test(await page.locator('body').innerText()), 'inspect screen shows no PHP errors');
}

// Phone width.
await page.setViewportSize({ width: 390, height: 800 });
await visit(root, 'overview-mobile');
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
ok(!overflow, 'no horizontal scroll at 390 px');
await visit(`${root}&view=event&id=${evilId}`, 'event-mobile');
ok(!(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)), 'event has no horizontal scroll at 390 px');

ok(problems.length === 0, 'no browser errors: ' + problems.slice(0, 3).join(' | '));
await browser.close();
console.log(`== ui: ${pass} passed, ${fails} failed`);
process.exit(fails ? 1 : 0);
