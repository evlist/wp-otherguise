// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 204: drives the screen Settings > Otherguise modes in a real browser, on a scratch WordPress site (see ../README.md). Environment: BASE, WPSH,
// PLAYWRIGHT, CHROMIUM (optional); the admin account is admin/admin. It creates the users `og204editor` and `og204sub`.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8090', SHOTS = process.argv[2] || '.';
const WPSH = process.env.WPSH || './wp.sh';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const wp = (args) => sh(WPSH + ' ' + args);
const count = () => parseInt(wp('eval \'echo count(triples_statements()->match(null, "modes/has-variant")) + count(triples_statements()->match(null, "modes/has-part-variant"));\''), 10);
const front = (q) => sh(`curl -sS -m 30 "${BASE}/?${q}"`);
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };

(async () => {
  wp('eval-file ' + (process.env.SETUP || 'setup.php') + ' create');
  const theme = wp('theme list --status=active --field=name');
  const post = wp('post list --post_type=post --field=ID').split('\n')[0];
  const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(r.status() + ' ' + r.url()); });
  const shot = (n) => page.screenshot({ path: SHOTS + '/' + n + '.png', fullPage: true });
  const body = () => page.locator('body').innerText();
  const phpErrors = async (label) => { const h = await page.content(); const m = h.match(/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>:[^<]*/g); check(label + ': no PHP error on the page', !m, m ? m[0] : ''); };
  const addForm = (kind) => page.locator(`form:has(input[value=modes_declare]):has(input[name=kind][value=${kind}])`);
  const add = async (kind, source, variant, mode) => {
    const f = addForm(kind);
    await f.locator('select[name=source]').selectOption(source);
    await f.locator('select[name=variant]').selectOption(variant);
    await f.locator('select[name=mode]').selectOption(mode);
    await Promise.all([page.waitForNavigation(), f.locator('input[type=submit]').click()]);
  };

  await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  // 1. The screen
  const r = await page.goto(BASE + '/wp-admin/options-general.php?page=modes');
  check('the screen opens under Settings (HTTP ' + r.status() + ')', r.status() === 200 && (await body()).includes('Modes'));
  await phpErrors('screen'); await shot('20-modes');
  const t = await body();
  check('the modes and how to reach them are listed', t.includes('?mode=print') && t.includes('?print') && t.includes('?mode=web'));
  check('the menu entry is under Settings', (await page.locator('#adminmenu a[href*="page=modes"]').count()) === 1);
  check('nothing declared yet', t.includes('No variant declared.'));

  // The modes are disabled until the administrator enables them: the screen says so, and the box enables them.
  const firstForm = page.locator('form:has(input[name="modes_settings[enabled]"])');
  check('disabled by default: the box is unchecked and the warning is shown', !(await firstForm.locator('input[name="modes_settings[enabled]"]').isChecked()) && t.includes('The modes are disabled (they are until you enable them)') && t.includes('have no effect until you enable the modes'));
  await firstForm.locator('input[name="modes_settings[enabled]"]').check();
  await Promise.all([page.waitForNavigation(), firstForm.locator('input[type=submit]').click()]);
  check('enabled from the screen: the warning is gone', !(await body()).includes('The modes are disabled') && wp('option get modes_settings --format=json').includes('"enabled":true'));

  // 2. Declare a variant of a template, in print mode
  await add('template', `${theme}//single`, `${theme}//og204-single-print`, 'print');
  await phpErrors('after declare'); await shot('21-declared');
  check('declare: notice and the relation in the table', (await body()).includes('Variant declared.') && (await page.locator('table tbody tr:has(code:text("' + theme + '//og204-single-print"))').count()) >= 1);
  check('declare: the front end shows the variant in print mode and not otherwise', front(`p=${post}&print`).includes('OG204-SINGLE-PRINT') && !front(`p=${post}`).includes('OG204-SINGLE-PRINT'));

  // 3. A second mode on the same relation, then withdraw it
  await add('template', `${theme}//single`, `${theme}//og204-single-print`, 'web');
  check('a second mode on the same relation: one row, two withdraw buttons', (await page.locator('input[value="Withdraw from Print"]').count()) === 1 && (await page.locator('input[value="Withdraw from Web"]').count()) === 1);
  check('the variant of the default mode applies to a request without a mode', front(`p=${post}`).includes('OG204-SINGLE-PRINT'));
  await Promise.all([page.waitForNavigation(), page.click('input[value="Withdraw from Web"]')]);
  check('withdraw: notice, the other mode stays', (await body()).includes('Variant withdrawn from the mode.') && (await page.locator('input[value="Withdraw from Print"]').count()) === 1 && (await page.locator('input[value="Withdraw from Web"]').count()) === 0);
  check('withdraw: the default mode shows the normal template again', !front(`p=${post}`).includes('OG204-SINGLE-PRINT'));

  // 4. Refusals are notices
  await add('template', `${theme}//single`, `${theme}//og204-other`, 'print');
  check('another variant in the same mode: refused with a message', (await body()).includes('already has another variant in this mode') && count() === 1);
  await add('template', `${theme}//single`, `${theme}//single`, 'web');
  check('a template as its own variant: refused with a message', (await body()).includes('cannot be its own variant') && count() === 1);

  // 5. Template parts
  await add('template_part', `${theme}//header`, `${theme}//og204-header-print`, 'print');
  check('template part: declared', (await body()).includes('Variant declared.') && count() === 2);
  const cat = wp('term list category --field=term_id').split('\n')[0];
  check('template part: the front end shows the part variant in print mode only (an archive, whose template has the header part and no variant)', front(`cat=${cat}&print`).includes('OG204-HEADER-PRINT') && !front(`cat=${cat}`).includes('OG204-HEADER-PRINT'));
  await shot('22-parts');

  // 5b. Disable and enable the modes
  const settingsForm = page.locator('form:has(input[name="modes_settings[enabled]"])');
  await page.goto(BASE + '/wp-admin/options-general.php?page=modes');
  check('the setting is on the page, checked once enabled', (await settingsForm.locator('input[name="modes_settings[enabled]"]').isChecked()));
  await settingsForm.locator('input[name="modes_settings[enabled]"]').uncheck();
  await Promise.all([page.waitForNavigation(), settingsForm.locator('input[type=submit]').click()]);
  await phpErrors('after saving the setting'); await shot('25-disabled');
  check('disabled: saved through options.php and the page says so', wp('option get modes_settings --format=json').includes('"enabled":false') && (await body()).includes('The modes are disabled') && !(await settingsForm.locator('input[name="modes_settings[enabled]"]').isChecked()), page.url().split('?')[1]);
  const off = front(`p=${post}&print`);
  check('disabled: ?print shows the normal template and the body has no mode class', !off.includes('OG204-SINGLE-PRINT') && !off.includes('modes-mode-'));
  check('disabled: ?mode=print is ignored too, and parts are the normal ones', !front(`p=${post}&mode=print`).includes('OG204-SINGLE-PRINT') && !front(`cat=${cat}&print`).includes('OG204-HEADER-PRINT'));
  check('disabled: the variants are kept and can still be edited', (await page.locator('input[value="Withdraw from Print"]').count()) >= 1 && (await addForm('template').count()) === 1 && count() === 2);
  check('disabled: the Modes functions answer with the default mode', wp('eval \'echo modes_active_mode()->slug();\'') === 'web');
  await settingsForm.locator('input[name="modes_settings[enabled]"]').check();
  await Promise.all([page.waitForNavigation(), settingsForm.locator('input[type=submit]').click()]);
  check('enabled again: saved and the warning is gone', wp('option get modes_settings --format=json').includes('"enabled":true') && !(await body()).includes('The modes are disabled'));
  const on = front(`p=${post}&print`);
  check('enabled again: the variant and the class are back at once', on.includes('OG204-SINGLE-PRINT') && on.includes('modes-mode-print'));

  // 6. A template that disappears
  wp('eval \'foreach ( get_posts( array( "post_type" => "wp_template", "name" => "og204-single-print", "numberposts" => 1 ) ) as $p ) { wp_delete_post( $p->ID, true ); }\'');
  await page.goto(BASE + '/wp-admin/options-general.php?page=modes');
  check('a template that was deleted is marked missing', (await body()).includes('(missing)'));
  await shot('23-missing');
  const code = sh(`curl -sS -o /dev/null -w "%{http_code}" "${BASE}/?p=${post}&print"`);
  check('the front end still works with the missing variant (HTTP ' + code + ')', code === '200');

  // 7. Remove a relation
  await Promise.all([page.waitForNavigation(), page.locator('input[value="Remove"]').first().click()]);
  check('remove: notice and one relation less', (await body()).includes('Variant removed.') && count() === 1);

  // 8. Attacks
  const before = count();
  const post403 = async (data, c = ctx) => (await c.request.post(BASE + '/wp-admin/admin-post.php', { form: data, maxRedirects: 0 })).status();
  const form = { kind: 'template', source: `${theme}//single`, variant: `${theme}//og204-other`, mode: 'print' };
  check('bad nonce: refused', [400, 403].includes(await post403({ action: 'modes_declare', _wpnonce: 'deadbeef00', ...form })));
  check('no nonce: refused', [400, 403].includes(await post403({ action: 'modes_declare', ...form })));
  check('nothing declared by the attacks', count() === before);

  // 9. Users without the capability
  wp('user create og204editor og204editor@example.test --role=editor --user_pass=ed >/dev/null 2>&1; true');
  wp('user create og204sub og204sub@example.test --role=subscriber --user_pass=sub >/dev/null 2>&1; true');
  for (const [user, pass_] of [['og204editor', 'ed'], ['og204sub', 'sub']]) {
    const c = await browser.newContext(); const p = await c.newPage();
    await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', user); await p.fill('#user_pass', pass_); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    await p.goto(BASE + '/wp-admin/options-general.php?page=modes');
    check(user + ': the page is refused', /not allowed to access this page|Sorry/.test(await p.locator('body').innerText()) && !(await p.locator('body').innerText()).includes('Add a variant'));
    await p.goto(BASE + '/wp-admin/profile.php');
    const nonce = (await p.locator('#_wpnonce').first().getAttribute('value')) || '';
    check(user + ': a valid nonce of another action is refused', [400, 403].includes(await post403({ action: 'modes_declare', _wpnonce: nonce, ...form }, c)));
    await c.close();
  }
  check('nothing declared by the users without the capability', count() === before);

  // 10. The other screen still works
  const r2 = await page.goto(BASE + '/wp-admin/tools.php?page=triples&tab=registered');
  const reg = await body();
  check('the Triples screen lists the new types and predicates', r2.status() === 200 && reg.includes('modes/has-variant') && reg.includes('template_part'));
  await shot('24-triples-registered');

  await browser.close();
  wp('user delete og204editor --yes >/dev/null 2>&1; true');
  wp('user delete og204sub --yes >/dev/null 2>&1; true');
  wp('eval-file ' + (process.env.SETUP || 'setup.php') + ' cleanup');
  console.log('problems:', JSON.stringify(problems));
  console.log(`${pass} passed, ${fail} failed`);
})();
