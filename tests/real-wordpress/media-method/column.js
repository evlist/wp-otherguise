// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 210: the column "Uploaded to" of the media library lists every post of a media item when the method of Otherguise is in use
// (Media Helper replaces its content), and the native one when it is not. Real Media Helper, Chromium. Environment: BASE, WPSH,
// PLAYWRIGHT; the admin account is admin/admin. It creates two posts and an attachment and removes them.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const WPSH = process.env.WPSH || './wp.sh';
const sh = (cmd) => execFileSync('sh', ['-c', cmd], { encoding: 'utf8' }).trim();
const wp = (args) => sh(WPSH + ' ' + args + ' 2>/dev/null');
let pass = 0, fail = 0;
const check = (label, ok, detail = '') => { (ok ? pass++ : fail++); console.log((ok ? 'PASS  ' : 'FAIL  ') + label + (detail ? '  [' + detail + ']' : '')); };

(async () => {
  const ids = wp('eval \'wp_set_current_user( 1 ); $a = wp_insert_post( array( "post_title" => "og210 alpha", "post_status" => "publish" ) ); $b = wp_insert_post( array( "post_title" => "og210 beta", "post_status" => "publish" ) ); $m = wp_insert_attachment( array( "post_title" => "og210 media", "post_mime_type" => "image/jpeg", "post_status" => "inherit" ), "og210/media.jpg" ); $x = \\WP_Media_Helper\\Attachment\\Methods::all()["otherguise"]; $x->attach( $m, $a, array( "modes" => array( "web" ) ) ); $x->attach( $m, $b, array( "modes" => array( "print" ) ) ); echo "$a $b $m";\'').split(' ');
  const [a, b, m] = ids;
  const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
  const page = await (await browser.newContext({ viewport: { width: 1300, height: 900 } })).newPage();
  const problems = [];
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('favicon') && !r.url().includes('og210/media.jpg')) problems.push(r.status() + ' ' + r.url()); });
  const rowText = async () => (await page.locator(`tr#post-${m}`).innerText());
  try {
    await page.goto(BASE + '/wp-login.php'); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

    wp('option update wp_media_helper_attachment_method native');
    await page.goto(BASE + '/wp-admin/upload.php?mode=list&s=og210');
    let t = await rowText();
    check('native method: the column shows the parent only', t.includes('og210 alpha') && !t.includes('og210 beta'), t.replace(/\s+/g, ' ').slice(0, 160));

    wp('option update wp_media_helper_attachment_method otherguise');
    await page.goto(BASE + '/wp-admin/upload.php?mode=list&s=og210');
    t = await rowText();
    check('method of Otherguise: the column lists every post', t.includes('og210 alpha') && t.includes('og210 beta'), t.replace(/\s+/g, ' ').slice(0, 160));
    check('no PHP error on the page', !/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>/.test(await page.content()));
    check('no page error and no failed request', problems.length === 0, problems.join(' | '));
  } finally {
    await browser.close();
    wp('option delete wp_media_helper_attachment_method');
    wp('eval \'foreach ( get_posts( array( "post_type" => array( "post", "attachment" ), "post_status" => "any", "numberposts" => -1 ) ) as $p ) { if ( 0 === strpos( $p->post_title, "og210" ) ) { wp_delete_post( $p->ID, true ); } }\'');
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
