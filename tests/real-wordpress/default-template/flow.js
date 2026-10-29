// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 209: the template that new posts start with, on a real WordPress site, in Chromium. The modes stay DISABLED throughout: the
// feature does not depend on them. Environment: BASE, WPSH, PLAYWRIGHT; the admin account is admin/admin. It creates a template and some
// posts and removes them.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const WPSH = process.env.WPSH || './wp.sh';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const wp = (args) => sh(WPSH + ' ' + args + ' 2>/dev/null');
const meta = (id) => wp(`eval 'echo get_post_meta( ${id}, "_wp_page_template", true );'`);
const opt = (name) => wp(`eval 'echo json_encode( get_option( "${name}", null ) );'`);
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };

(async () => {
  wp('option delete modes_settings');
  wp('option delete modes_default_templates');
  const tpl = wp('eval \'$t = wp_insert_post( array( "post_type" => "wp_template", "post_name" => "og209-rando", "post_title" => "OG209 rando", "post_status" => "publish", "post_content" => "<!-- wp:post-title /--><!-- wp:post-content /-->" ) ); wp_set_object_terms( $t, get_stylesheet(), "wp_theme" ); echo $t;\'');
  const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const page = await (await browser.newContext({ viewport: { width: 1300, height: 900 } })).newPage();
  const problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(r.status() + ' ' + r.url()); });
  const body = () => page.locator('body').innerText();
  const form = () => page.locator('form:has(select[name="modes_default_templates[post]"])');
  const newPost = async (type) => {
    await page.goto(BASE + '/wp-admin/post-new.php' + (type === 'page' ? '?post_type=page' : ''));
    await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId(), null, { timeout: 60000 });
    await page.waitForTimeout(2500);
    return page.evaluate(() => { const s = wp.data.select('core/editor'); return { id: s.getCurrentPostId(), template: s.getEditedPostAttribute('template') }; });
  };
  try {
    await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    check('the modes are disabled for the whole run', opt('modes_settings') === 'null');

    // Before: a new post starts with the default template.
    let np = await newPost('post');
    check('before any setting: a new post has no template', np.template === '', JSON.stringify(np));

    // The setting on the screen.
    await page.goto(BASE + '/wp-admin/options-general.php?page=modes');
    check('the section is on the screen, with one list per type', (await body()).includes('Template of new posts') && (await form().locator('select').count()) >= 2);
    check('the lists start on the default template', (await form().locator('select[name="modes_default_templates[post]"]').inputValue()) === '');
    await form().locator('select[name="modes_default_templates[post]"]').selectOption('og209-rando');
    await Promise.all([page.waitForNavigation(), form().locator('input[type=submit]').click()]);
    check('saved through options.php: the option holds the slug for the type post only', opt('modes_default_templates') === '{"post":"og209-rando"}', opt('modes_default_templates'));
    check('the chosen template is selected again', (await form().locator('select[name="modes_default_templates[post]"]').inputValue()) === 'og209-rando');
    check('no PHP error on the screen', !/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>/.test(await page.content()));

    // New posts.
    np = await newPost('post');
    check('a new post opens with the template selected', np.template === 'og209-rando', JSON.stringify(np));
    await page.evaluate(() => wp.data.dispatch('core/editor').editPost({ title: 'og209 keep', status: 'publish' }));
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost()); await page.waitForTimeout(4000);
    check('publishing keeps it', meta(np.id) === 'og209-rando');

    await page.evaluate(() => wp.data.dispatch('core/editor').editPost({ template: '' }));
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost()); await page.waitForTimeout(4000);
    check('the author choosing "Default template" keeps that choice', meta(np.id) === '');

    const pg = await newPost('page');
    check('a page is not affected', pg.template === '', JSON.stringify(pg));
    const cli = wp('post create --post_title="og209 cli" --post_status=draft --porcelain');
    check('a post created by WP-CLI is not affected', meta(cli) === '');

    // A template that goes away is ignored.
    wp(`post delete ${tpl} --force`);
    np = await newPost('post');
    check('a template that is gone is ignored', np.template === '', JSON.stringify(np));

    // Removing the setting from the screen.
    await page.goto(BASE + '/wp-admin/options-general.php?page=modes');
    await form().locator('select[name="modes_default_templates[post]"]').selectOption('');
    await Promise.all([page.waitForNavigation(), form().locator('input[type=submit]').click()]);
    check('choosing the default template removes the setting', opt('modes_default_templates') === '[]');
    check('the modes were still disabled', opt('modes_settings') === 'null');
    check('no page error and no failed request', problems.length === 0, problems.join(' | '));
  } finally {
    await browser.close();
    wp('eval \'foreach ( get_posts( array( "post_type" => array( "post", "page", "wp_template" ), "post_status" => "any", "numberposts" => -1 ) ) as $p ) { if ( 0 === strpos( $p->post_title, "og209" ) || 0 === strpos( $p->post_name, "og209" ) || "Auto Draft" === $p->post_title ) { wp_delete_post( $p->ID, true ); } }\'');
    wp('option delete modes_default_templates');
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
