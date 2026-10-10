// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 205: the block modes/link on a real WordPress site, on the front end and in the block editor (Chromium). Environment: BASE, WPSH,
// PLAYWRIGHT, CHROMIUM (optional), SETUP (path of setup.php); the admin account is admin/admin.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const WPSH = process.env.WPSH || './wp.sh';
const SETUP = process.env.SETUP || 'setup.php';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const wp = (args) => sh(WPSH + ' ' + args);
const front = (q) => sh(`curl -sS -m 30 "${BASE}/${q}"`);
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };

(async () => {
  const out = wp('eval-file ' + SETUP + ' create');
  const id = out.match(/post (\d+)/)[1];
  const links = (html) => (html.match(/<a [^>]*class="[^"]*wp-block-modes-link[^"]*"[^>]*>/g) || []);
  try {
    // Front end, web mode: the link to print, none to web (the mode of the request).
    let h = front('?p=' + id);
    let l = links(h);
    check('web: exactly one link, to the print version', l.length === 1 && l[0].includes(`href="${BASE}/?p=${id}&#038;mode=print"`), l.join('|'));
    check('web: rel nofollow and accessible name', l[0] && l[0].includes('rel="nofollow"') && l[0].includes('aria-label="Print version"') && l[0].includes('title="Print version"'));
    check('web: the icon is inside the link', /<a [^>]*modes-link[^>]*>\s*<svg/.test(h));
    check('web: body of the post still there', h.includes('Body of the post.'));
    // Print mode through the alias and through ?mode=: the link to print disappears, the link to web has no mode.
    for (const q of ['&print', '&print=print', '&mode=print']) {
      h = front('?p=' + id + q); l = links(h);
      check('print (' + q + '): one link, to the web version without mode', l.length === 1 && l[0].includes(`href="${BASE}/?p=${id}"`) && !l[0].includes('mode='), l.join('|'));
      check('print (' + q + '): its text is the label of the mode', />Web<\/a>/.test(h));
    }
    // Unknown mode: ignored, as the web mode.
    h = front('?p=' + id + '&mode=nope'); check('unknown mode behaves as web', links(h).length === 1);
    // Modes disabled: no link at all.
    wp('option update modes_settings \'{"enabled":0}\' --format=json');
    h = front('?p=' + id); check('modes disabled: no link', links(h).length === 0);
    wp('option delete modes_settings');
    h = front('?p=' + id); check('modes enabled again: the link is back', links(h).length === 1);
    check('modes_url() on the site', wp('eval \'echo modes_url("print", "https://e.org/p/?print=1#a") . " " . modes_url("web", "https://e.org/p/?mode=print") . " [" . modes_url("nope", "https://e.org/") . "]";\'') === 'https://e.org/p/?mode=print#a https://e.org/p/ []');

    // Editor.
    const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
    const page = await (await browser.newContext({ viewport: { width: 1400, height: 900 } })).newPage();
    const problems = [];
    page.on('pageerror', e => problems.push('pageerror: ' + e.message));
    page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(r.status() + ' ' + r.url()); });
    await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(BASE + '/wp-admin/post.php?post=' + id + '&action=edit');
    await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor') && wp.blocks.getBlockType('modes/link'), null, { timeout: 60000 });
    try { await page.locator('button[aria-label="Close"]').first().click({ timeout: 3000 }); } catch (e) { /* no welcome guide */ }
    check('editor: the block is registered', true);
    const info = await page.evaluate(() => ({ title: wp.blocks.getBlockType('modes/link').title, variations: wp.blocks.getBlockVariations('modes/link').map(v => v.name), modes: window.modesLink && window.modesLink.modes.map(m => m.value) }));
    check('editor: print variation and the list of modes', info.variations.includes('print') && JSON.stringify(info.modes) === '["web","print"]', JSON.stringify(info));
    // Insert the variation, select the block, set the mode with the inspector.
    await page.evaluate(() => {
      const v = wp.blocks.getBlockVariations('modes/link').find(x => x.name === 'print');
      const b = wp.blocks.createBlocksFromInnerBlocksTemplate ? wp.blocks.createBlock('modes/link', v.attributes, wp.blocks.createBlocksFromInnerBlocksTemplate(v.innerBlocks)) : null;
      wp.data.dispatch('core/block-editor').insertBlocks(b);
      wp.data.dispatch('core/block-editor').selectBlock(b.clientId);
    });
    // The canvas is an iframe in recent releases, the page itself in older ones.
    const canvas = (await page.locator('iframe[name="editor-canvas"]').count()) ? page.frameLocator('iframe[name="editor-canvas"]') : page;
    await canvas.locator('[data-type="modes/link"]').waitFor({ timeout: 15000 });
    check('editor: the block is displayed with its icon', await canvas.locator('[data-type="modes/link"] svg').count() > 0);
    const select = page.locator('select').filter({ has: page.locator('option[value="print"]') }).first();
    await select.waitFor({ timeout: 15000 });
    check('editor: the inspector shows the mode select with print selected', (await select.inputValue()) === 'print');
    await select.selectOption('web');
    check('editor: the attribute follows the select', await page.evaluate(() => wp.data.select('core/block-editor').getBlocks().find(b => b.name === 'modes/link').attributes.mode) === 'web');
    const markup = await page.evaluate(() => wp.blocks.serialize(wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'modes/link')));
    check('editor: serialized as a block comment around its inner blocks', /^<!-- wp:modes\/link \{"mode":"web"/.test(markup) && markup.includes('<!-- wp:html -->'), markup.slice(0, 160));
    await page.screenshot({ path: (process.argv[2] || '.') + '/205-link-block-editor.png' });
    check('editor: no page error and no failed request', problems.length === 0, problems.join(' | '));
    await browser.close();
  } finally {
    wp('eval-file ' + SETUP + ' remove');
    wp('option delete modes_settings');
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
