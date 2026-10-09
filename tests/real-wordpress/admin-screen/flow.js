// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 105: drives the administration screen (Tools > Relations) of the Triples module in a real browser, on a scratch WordPress site.
// See README.md. Environment: BASE (site URL), WPSH (command that runs WP-CLI on the site), PLAYWRIGHT (path of the playwright module),
// CHROMIUM (path of the browser, optional), and the admin account admin/admin.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8090', SHOTS = process.argv[2] || '.';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const WPSH = process.env.WPSH || './wp.sh';
const wp = (args) => sh(WPSH + ' ' + args);
const count = (where = '1=1') => parseInt(wp(`db query "SELECT COUNT(*) FROM wp_triples_statements WHERE ${where}" --skip-column-names`), 10);
const SEED = JSON.parse(wp('eval-file ' + (process.env.SEED || 'seed.php')).split('\n').filter(l => l.startsWith('{'))[0]);
console.log('seed', JSON.stringify(SEED));
wp('user meta delete 1 triples_per_page >/dev/null 2>&1; true');
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };
(async () => {
  const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(r.status() + ' ' + r.url()); });
  const shot = (n) => page.screenshot({ path: SHOTS + '/' + n + '.png', fullPage: true });
  const body = () => page.locator('body').innerText();
  const phpErrors = async (label) => { const h = await page.content(); const m = h.match(/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>:[^<]*/g); check(label + ': no PHP error on the page', !m, m ? m[0] : ''); };

  await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  // 1. The list
  await page.goto(BASE + '/wp-admin/tools.php?page=triples'); await phpErrors('list'); await shot('10-list');
  check('list: 6 top-level statements (the 5 about statements are hidden)', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 6);
  check('list: predicates are shown by label, registered', (await body()).includes('Illustrated by') && !(await body()).includes('(not registered)'));
  check('list: the position qualifier is shown in "About it"', (await body()).includes('Position'));
  check('list: titles have their dash and apostrophe', (await body()).includes('Day 2 — Col d’Aubisque'));

  // 2. Filter through the form
  await page.selectOption('select[name=predicate]', 'books/contains'); await Promise.all([page.waitForNavigation(), page.click('.triples-filters input[type=submit]')]);
  check('filter by predicate: 2 rows, URL keeps the filter', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 2 && page.url().includes('predicate=books%2Fcontains'), page.url().split('?')[1]);
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&with_qualifiers=1'); check('statements about statements shown on request: 11 rows', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 11);
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&entity=attachment:' + SEED.photos[0]); check('filter by entity: 1 row', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 1);
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&entity=post%3A1%22%20onfocus%3D%22alert(1)'); check('a malformed entity is dropped: no element carries an injected attribute', (await page.locator('[onfocus]').count()) === 0 && (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 6);

  // 3. Screen option: statements per page
  await page.goto(BASE + '/wp-admin/tools.php?page=triples');
  await page.click('#show-settings-link'); await page.fill('#triples_per_page', '2'); await Promise.all([page.waitForNavigation(), page.click('#screen-options-apply')]);
  check('screen option: 2 rows per page and pagination', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 2 && (await page.locator('.tablenav-pages .displaying-num').first().innerText()).includes('6'), await page.locator('.tablenav-pages').first().innerText().catch(() => ''));
  await shot('11-paged');
  console.log('per-page meta key stored by WordPress:', wp('user meta list 1 --keys=triples_per_page --format=csv').replace(/\n/g, ' | '));
  wp('user meta delete 1 triples_per_page');

  // 4. Bulk deletion with confirmation
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&predicate=media%2Fillustrated-by');
  const first = page.locator('tbody#the-list input[type=checkbox]').first(); await first.check();
  await page.selectOption('select[name=action]', 'delete'); await Promise.all([page.waitForNavigation(), page.click('#doaction')]);
  await phpErrors('confirmation'); await shot('12-confirm');
  const t = await body();
  check('confirmation: says how many statements go in all', /1 statement is selected; \d+ statements will be deleted in all/.test(t), (t.match(/\d+ statement[^.]*\./) || [''])[0]);
  const before = count();
  await Promise.all([page.waitForNavigation(), page.click('input[value=Delete].button-primary')]);
  await phpErrors('after delete'); await shot('13-deleted');
  check('deletion: notice shown', /statements? deleted/.test(await body()), (await body()).match(/\d+ statements? deleted\./)?.[0]);
  check('deletion: the statement and what is said about it are gone', count() < before && count() === before - 4 || count() === before - 3, `${before} -> ${count()}`);
  check('deletion: URL carries only the result code', page.url().includes('triples_notice=deleted'));

  // 5. Orphans: remove a media item behind the back of WordPress, then scan and delete
  wp('db query "DELETE FROM wp_posts WHERE ID=' + SEED.photos[2] + '"');
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&tab=maintenance'); await page.click('text=Scan the first 200 statements'); await page.waitForLoadState(); await shot('14-orphans');
  check('orphans: the statement about the vanished media item is found', /1 orphan found/.test(await body()), (await body()).match(/Statements up to[^.]*\./)?.[0]);
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&orphans=1'); check('orphans-only list shows exactly it', (await page.locator('tbody#the-list tr:not(.no-items)').count()) === 1 && (await body()).includes('attachment:' + SEED.photos[2])); await shot('15-orphans-list');
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&tab=maintenance&after=0');
  const b2 = count(); await Promise.all([page.waitForNavigation(), page.click('text=Delete the orphans of this batch')]);
  check('orphans: deleted, with what is said about it', count() < b2 && /statements? deleted/.test(await body()), `${b2} -> ${count()}`);

  // 6. A predicate that is no longer registered
  wp(`db query "INSERT INTO wp_triples_statements (subject_type,subject_id,predicate,object_type,object_id,created_gmt,updated_gmt) VALUES ('post','${wp('post list --post_type=post --meta_key=_og_seed --field=ID | head -1')}','old/relation','post','1',NOW(),NOW())"`);
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&tab=registered'); await shot('16-unregistered');
  check('unregistered predicate listed with a link, not a button that deletes', (await page.locator('a:has-text("Delete these statements")').count()) === 1 && (await page.locator('input[value*="Delete these"]').count()) === 0);
  await Promise.all([page.waitForNavigation(), page.click('a:has-text("Delete these statements")')]); await shot('17-confirm-predicate');
  check('confirmation page for the predicate', /1 statement of the predicate old\/relation/.test(await body()));
  await Promise.all([page.waitForNavigation(), page.click('input[value=Delete].button-primary')]);
  check('predicate statements deleted', count("predicate='old/relation'") === 0 && /1 statement deleted/.test(await body()));

  // 7. Settings
  await page.goto(BASE + '/wp-admin/tools.php?page=triples&tab=maintenance');
  await page.check('input[name="triples_settings[delete_data_on_uninstall]"]'); await Promise.all([page.waitForNavigation(), page.click('input[value=Save]')]);
  const opt = wp('option get triples_settings --format=json');
  check('settings: saved through options.php', opt.includes('"delete_data_on_uninstall":true'), opt);
  check('settings: back on the maintenance tab, box checked', page.url().includes('tab=maintenance') && await page.isChecked('input[name="triples_settings[delete_data_on_uninstall]"]'), page.url().split('?')[1]);
  await page.uncheck('input[name="triples_settings[delete_data_on_uninstall]"]'); await Promise.all([page.waitForNavigation(), page.click('input[value=Save]')]);
  check('settings: unchecked again', wp('option get triples_settings --format=json').includes('"delete_data_on_uninstall":false'));

  // 8. Attacks on the handlers
  const post = (data, ctxx = ctx) => ctxx.request.post(BASE + '/wp-admin/admin-post.php', { form: data, maxRedirects: 0 });
  const stBefore = count();
  let r = await post({ action: 'triples_delete', 'statement[]': '1', _wpnonce: 'deadbeef00' }); check('bad nonce: refused', r.status() === 403 || r.status() === 400, String(r.status()));
  r = await post({ action: 'triples_delete', 'statement[]': '1' }); check('no nonce: refused', r.status() === 403 || r.status() === 400, String(r.status()));
  r = await post({ action: 'triples_delete_predicate', predicate: 'media/illustrated-by', _wpnonce: 'deadbeef00' }); check('registered predicate with bad nonce: refused', r.status() === 403 || r.status() === 400);
  check('nothing deleted by the attacks', count() === stBefore);

  // 9. A subscriber
  sh(WPSH + ' user create sub sub@example.test --role=subscriber --user_pass=sub >/dev/null 2>&1; true');
  const sctx = await browser.newContext(); const sp = await sctx.newPage();
  await sp.goto(BASE + '/wp-login.php'); await sp.fill('#user_login', 'sub'); await sp.fill('#user_pass', 'sub'); await Promise.all([sp.waitForNavigation(), sp.click('#wp-submit')]);
  const resp = await sp.goto(BASE + '/wp-admin/tools.php?page=triples');
  check('subscriber: the page is refused', /not allowed to access this page|Sorry/.test(await sp.locator('body').innerText()) && !(await sp.locator('body').innerText()).includes('Illustrated by'), String(resp.status()));
  const nonce = await (async () => { await sp.goto(BASE + '/wp-admin/profile.php'); return (await sp.locator('#_wpnonce').first().getAttribute('value')) || ''; })();
  r = await sctx.request.post(BASE + '/wp-admin/admin-post.php', { form: { action: 'triples_delete', 'statement[]': '1', _wpnonce: nonce }, maxRedirects: 0 });
  check('subscriber with a valid nonce of another action: refused', r.status() === 403 || r.status() === 400, String(r.status()));
  check('nothing deleted by the subscriber', count() === stBefore);

  await browser.close();
  console.log('problems:', JSON.stringify(problems));
  console.log(`${pass} passed, ${fail} failed`);
})();
