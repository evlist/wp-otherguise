// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 207: the stylesheets of the modes on a real WordPress site, in Chromium: upload from the screen, the front end in each mode, the
// refusals, a file of the Media Library, removal, disabled modes, deletion of the file. Environment: BASE, WPSH, PLAYWRIGHT; the admin
// account is admin/admin. It creates a post and some CSS attachments and removes them.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const WPSH = process.env.WPSH || './wp.sh';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const wp = (args) => sh(WPSH + ' ' + args + ' 2>/dev/null');
const front = (q) => sh(`curl -sS -m 30 "${BASE}/?${q}"`);
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };
const links = (html) => (html.match(/<link [^>]*id='modes-[^']*-css'[^>]*>/g) || []);
const cssCount = () => wp('post list --post_type=attachment --post_mime_type=text/css --format=count');
const statements = () => parseInt(wp('eval \'echo count(triples_statements()->match(null, "modes/stylesheet"));\''), 10);

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'og207-'));
  const good = path.join(dir, 'og207-print.css');
  fs.writeFileSync(good, 'body{outline:5px solid #c00}\n@media print{.no-print{display:none}}\n');
  const bad = path.join(dir, 'og207-bad.php');
  fs.writeFileSync(bad, '<?php echo "x";\n');
  const big = path.join(dir, 'og207-big.css');
  fs.writeFileSync(big, '/*' + 'x'.repeat(600 * 1024) + '*/\n');

  const post = wp('post create --post_status=publish --post_title="og207 post" --post_content=x --porcelain');
  wp('option delete modes_settings');
  const before = parseInt(cssCount(), 10);
  const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(r.status() + ' ' + r.url()); });
  const body = () => page.locator('body').innerText();
  const addForm = () => page.locator('form:has(input[value=modes_add_stylesheet])');
  const open = () => page.goto(BASE + '/wp-admin/options-general.php?page=modes');
  try {
    await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await open();
    check('the section Stylesheets is on the screen', (await body()).includes('Add a stylesheet'));
    check('the form starts on the first mode that is not the default one', (await addForm().locator('select[name=mode]').inputValue()) === 'print');

    // Upload.
    await addForm().locator('input[name=stylesheet_file]').setInputFiles(good);
    await Promise.all([page.waitForNavigation(), addForm().locator('input[type=submit]').click()]);
    check('upload: the notice says it was added', (await body()).includes('Stylesheet added to the mode.'));
    check('upload: one more CSS file in the Media Library', parseInt(cssCount(), 10) === before + 1);
    const id = wp('post list --post_type=attachment --post_mime_type=text/css --orderby=ID --order=DESC --posts_per_page=1 --field=ID');
    check('upload: the section lists it for print', (await body()).includes('og207-print'));

    // Front end.
    let h = front(`p=${post}&print`);
    let l = links(h);
    check('print: the stylesheet is linked once', l.length === 1 && l[0].includes(`id='modes-print-${id}-css'`) && l[0].includes('og207-print.css?ver='), l.join('|'));
    const theme = h.indexOf("id='twentytwentyfive-style-css'") >= 0 ? h.indexOf("id='twentytwentyfive-style-css'") : h.indexOf('global-styles-inline-css');
    check('print: after the styles of the theme', theme >= 0 && h.indexOf(`modes-print-${id}-css`) > theme, `theme at ${theme}, stylesheet at ${h.indexOf('modes-print-' + id + '-css')}`);
    h = front(`p=${post}&mode=print`); check('print through ?mode=print too', links(h).length === 1);
    h = front(`p=${post}`); check('web: no stylesheet of a mode', links(h).length === 0);
    const url = (links(front(`p=${post}&print`))[0].match(/href='([^']+)'/) || [])[1];
    const served = sh(`curl -sS -m 20 -D - -o /dev/null "${url}" | tr -d '\\r'`);
    check('the file is served as text/css', /HTTP\/[\d.]+ 200/.test(served) && /content-type: text\/css/i.test(served), served.split('\n').slice(0, 3).join(' / '));

    // Refusals.
    await open();
    await addForm().locator('input[name=stylesheet_file]').setInputFiles(bad);
    await Promise.all([page.waitForNavigation(), addForm().locator('input[type=submit]').click()]);
    check('a .php file is refused with a message', (await body()).includes('The file could not be uploaded'));
    check('...and nothing was added', parseInt(cssCount(), 10) === before + 1 && statements() === 1);
    await open();
    await addForm().locator('input[name=stylesheet_file]').setInputFiles(big);
    await Promise.all([page.waitForNavigation(), addForm().locator('input[type=submit]').click()]);
    check('a file over the limit is refused', (await body()).includes('The stylesheet is too large.'));
    check('...and not kept in the Media Library', parseInt(cssCount(), 10) === before + 1 && statements() === 1);
    await open();
    await Promise.all([page.waitForNavigation(), addForm().locator('input[type=submit]').click()]);
    check('no file at all is refused', (await body()).includes('The file could not be uploaded') && statements() === 1);

    // A file of the Media Library, for the default mode.
    await open();
    await addForm().locator('select[name=mode]').selectOption('web');
    await addForm().locator('input[name=how][value=existing]').check();
    await addForm().locator('select[name=attachment]').selectOption(id);
    await Promise.all([page.waitForNavigation(), addForm().locator('input[type=submit]').click()]);
    check('existing file: added to the default mode', (await body()).includes('Stylesheet added to the mode.') && statements() === 2);
    check('web: now has the stylesheet', links(front(`p=${post}`)).length === 1);
    check('print: still has it', links(front(`p=${post}&print`)).length === 1);

    // Removal.
    await open();
    const removeWeb = page.locator('tr:has(code:text-is("web")) form:has(input[value=modes_remove_stylesheet]) input[type=submit]');
    await Promise.all([page.waitForNavigation(), removeWeb.click()]);
    check('removed from web: the notice and the page', (await body()).includes('Stylesheet removed from the mode.') && links(front(`p=${post}`)).length === 0);
    check('...the file stays in the Media Library and in print', parseInt(cssCount(), 10) === before + 1 && links(front(`p=${post}&print`)).length === 1);

    // Disabled modes.
    wp('option update modes_settings \'{"enabled":0}\' --format=json');
    check('modes disabled: no stylesheet', links(front(`p=${post}&print`)).length === 0);
    wp('option delete modes_settings');
    check('modes enabled again: back', links(front(`p=${post}&print`)).length === 1);

    // The file deleted behind the screen's back.
    await open();
    wp(`post delete ${id} --force`);
    check('the file deleted: the relation is cleaned up', statements() === 0);
    h = front(`p=${post}&print`); check('...and nothing is linked', links(h).length === 0 && h.includes('og207 post'));
    await open();
    check('...the screen still opens', (await body()).includes('Add a stylesheet'));
    check('no PHP error on the screen', !/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>/.test(await page.content()));
    check('no page error and no failed request', problems.length === 0, problems.join(' | '));
  } finally {
    await browser.close();
    wp(`post delete ${post} --force`);
    for (const f of wp('post list --post_type=attachment --post_mime_type=text/css --format=ids').split(/\s+/).filter(Boolean)) {
      if (wp(`post get ${f} --field=post_title`).startsWith('og207')) wp(`post delete ${f} --force`);
    }
    wp('option delete modes_settings');
    fs.rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
