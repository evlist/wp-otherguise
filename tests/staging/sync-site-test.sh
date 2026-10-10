#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Tests of tools/staging/sync-site.sh with stand-ins for docker and rsync: the guards, the dry run, the order of a full run and the file it
# writes. Nothing here touches a real container. Not part of PHPUnit nor of the CI. Run: tests/staging/sync-site-test.sh
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../../tools/staging/sync-site.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0 FAIL=0
check() { if [ "$2" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS  $1"; else FAIL=$((FAIL + 1)); echo "FAIL  $1"; fi; }

mkdir -p "$T/bin" "$T/prod/wp-content" "$T/stg/wp-content"
export LOG="$T/calls.log" T

# docker: answers the questions of the script and records the commands that change something.
cat >"$T/bin/docker" <<'STUB'
#!/usr/bin/env bash
args="$*"
case "$1" in
  inspect) case "$args" in *Mounts*) printf '%s\n' /var/www/html/wp-content/plugins/devplug /etc/elsewhere ;; *) echo true ;; esac; exit 0 ;;
  cp) echo "docker $args" >>"$LOG"; [ -n "${3:-}" ] && : >"$3" 2>/dev/null; exit 0 ;;
esac
# docker exec [options] container command...
shift
while [[ "${1:-}" == -* ]]; do case "$1" in -u | -e) shift 2 ;; *) shift ;; esac; done
container=$1; shift
cmd="$*"
case "$cmd" in
  *"command -v wp"*) [ "$container" = "${STAGING_HAS_WP:-no}" ] && exit 1; [ "$container" = prod ] && echo /usr/local/bin/wp; [ "$container" = stg ] && [ "${STAGING_HAS_WP:-no}" = no ] && exit 1; exit 0 ;;
  *"mariadb-dump || command -v mysqldump"*) echo /usr/bin/mysqldump; exit 0 ;;
  *"mariadb || command -v mysql"*) echo /usr/bin/mysql; exit 0 ;;
  "wp --info"*) exit 0 ;;
  "wp option get siteurl"*) if [ "$container" = prod ]; then echo "${PROD_SITEURL:-https://e.test}"; else echo "${STG_SITEURL:-https://sb.test}"; fi; exit 0 ;;
  "wp config get DB_NAME"*) [ "$container" = prod ] && echo prod_db || echo stg_db; exit 0 ;;
  "wp config get DB_USER"*) echo u; exit 0 ;;
  "wp config get DB_PASSWORD"*) echo 'p w'; exit 0 ;;
  "wp config get DB_HOST"*) [ "$container" = prod ] && echo db:3306 || echo db2; exit 0 ;;
  "/usr/bin/mysqldump"*) echo "docker-dump $cmd" >>"$LOG"; echo "-- Dump completed on 2026-10-10"; exit 0 ;;
  "/usr/bin/mysql"*) echo "docker-load $cmd" >>"$LOG"; cat >/dev/null; exit 0 ;;
  *"search-replace"*"--format=count"*) echo 3; exit 0 ;;
  *) echo "docker-exec $container $cmd" >>"$LOG"; exit 0 ;;
esac
STUB
cat >"$T/bin/rsync" <<'STUB'
#!/usr/bin/env bash
echo "rsync $*" >>"$LOG"
case "$*" in *--dry-run*)
  printf '%s\n' '>f+++++++++ wp-content/plugins/new-plugin/new.php' 'cd+++++++++ wp-content/plugins/new-plugin/' '>f.st...... wp-content/plugins/old-plugin/a.php' \
    '>f+++++++++ wp-content/uploads/2026/photo-150x150.jpg' '>f+++++++++ wp-config-sample.php' '*deleting   wp-content/plugins/gone-plugin/' \
    'Number of regular files transferred: 4' 'Total file size: 1,000 bytes' "Total transferred file size: ${STUB_TRANSFER:-1,000} bytes" ;;
  *--stats*) echo "Total transferred file size: ${STUB_TRANSFER:-1,000} bytes" ;;
esac
exit 0
STUB
chmod +x "$T/bin/docker" "$T/bin/rsync"
export PATH="$T/bin:$PATH"

run() { # args... -> runs the script with the stand-ins
  PROD_CONTAINER=prod STAGING_CONTAINER=stg PROD_VOLUME="$T/prod" STAGING_VOLUME="$T/stg" PROD_URL=https://e.test STAGING_URL=https://sb.test \
    STAGING_HAS_WP=${STAGING_HAS_WP:-yes} bash "$SCRIPT" "$@"
}

# Guards.
: >"$LOG"; PROD_CONTAINER=prod STAGING_CONTAINER=prod PROD_VOLUME="$T/prod" STAGING_VOLUME="$T/stg" bash "$SCRIPT" --yes >/dev/null 2>&1
check "refuses identical containers" $((! $?)); 
: >"$LOG"; PROD_CONTAINER=prod STAGING_CONTAINER=stg PROD_VOLUME="$T/prod" STAGING_VOLUME="$T/prod" bash "$SCRIPT" --yes >/dev/null 2>&1
check "refuses identical volumes" $((! $?))
: >"$LOG"; PROD_URL=https://x STAGING_URL=https://x PROD_CONTAINER=prod STAGING_CONTAINER=stg PROD_VOLUME="$T/prod" STAGING_VOLUME="$T/stg" bash "$SCRIPT" --yes >/dev/null 2>&1
check "refuses identical addresses" $((! $?))
: >"$LOG"; PROD_SITEURL=https://other.test run --yes >/dev/null 2>&1
check "refuses when production says another address" $((! $?))
: >"$LOG"; STG_SITEURL=https://somewhere-else.test run --yes >/dev/null 2>&1
rc=$?; check "refuses a test site with an unexpected address" $((! rc)); ! grep -q "^rsync" "$LOG"; check "...and copied nothing" $?
: >"$LOG"; run --yes --bogus >/dev/null 2>&1
check "refuses an unknown option" $((! $?))
: >"$LOG"; echo wrong | run >/dev/null 2>&1
check "refuses without the confirmation" $((! $?))

# Dry run: rsync -n, no dump, no load.
: >"$LOG"; run --dry-run >/dev/null 2>&1; rc=$?
check "dry run succeeds" $rc
grep -q "^rsync .*--dry-run" "$LOG"; check "dry run: rsync --dry-run" $?
! grep -q "docker-dump\|docker-load" "$LOG"; check "dry run: no dump, no load" $?
out=$(run --dry-run 2>&1)
grep -q "wp-content/plugins: 4 entries" <<<"$out"; check "dry run: summary by place (plugins)" $?
grep -q "wp-content/uploads: 1 entries" <<<"$out"; check "dry run: summary by place (uploads)" $?
grep -q " 2 wp-content/plugins/new-plugin" <<<"$out" && grep -q "gone-plugin" <<<"$out"; check "dry run: lists the plugin directories, with deletions" $?
grep -q "Number of regular files transferred" <<<"$out"; check "dry run: shows the statistics" $?

grep -q "Space: about 1000 bytes" <<<"$out"; check "dry run: reports the space needed and free" $?
grep -q "left alone by rsync: /wp-content/plugins/devplug" <<<"$out"; check "dry run: reports the mounts of the test container" $?

# Full run.
: >"$LOG"; run --yes >"$T/out.txt" 2>&1; rc=$?
check "full run succeeds" $rc
[ $rc -eq 0 ] || sed -n 1,30p "$T/out.txt"
grep -q "^rsync -a --delete --numeric-ids --exclude /wp-config.php" "$LOG"; check "rsync keeps wp-config.php" $?
grep -q "^rsync .*--exclude /wp-content/plugins/devplug" "$LOG"; check "rsync leaves the mounts of the test container alone" $?
! grep -q "^rsync .*--exclude /etc/elsewhere" "$LOG"; check "mounts outside /var/www/html are ignored" $?
: >"$LOG"; STUB_TRANSFER=999999999999999999 run --yes >/dev/null 2>&1; rc=$?; check "refuses when there is not enough free space" $((! rc)); ! grep -q "docker-dump" "$LOG"; check "...and dumped nothing" $?
: >"$LOG"; run --yes >/dev/null 2>&1
grep -q "docker-dump .*-h db -P 3306 -u u prod_db" "$LOG"; check "dump: host and port split, production database" $?
grep -q "docker-load .*-h db2 -u u stg_db" "$LOG"; check "load: test database" $?
a=$(grep -n "^rsync" "$LOG" | head -1 | cut -d: -f1); b=$(grep -n "docker-dump" "$LOG" | head -1 | cut -d: -f1); c=$(grep -n "docker-load" "$LOG" | head -1 | cut -d: -f1)
[ "$a" -lt "$b" ] && [ "$b" -lt "$c" ]; check "order: files, dump, load" $?
grep -q "search-replace https://e.test https://sb.test --all-tables --skip-columns=guid" "$LOG"; check "addresses replaced, guid skipped" $?
grep -q "search-replace //e.test //sb.test" "$LOG"; check "scheme-less addresses replaced" $?
grep -q "config set DISABLE_WP_CRON true" "$LOG"; check "cron disabled" $?
grep -q "option update blog_public 0" "$LOG"; check "search engines discouraged" $?
php -l "$T/stg/wp-content/mu-plugins/staging-safety.php" >/dev/null 2>&1; check "the mu-plugin is valid PHP" $?
grep -q "https://e.test" "$T/stg/wp-content/mu-plugins/staging-safety.php"; check "the mu-plugin names the production address" $?
[ -z "$(ls "$T"/tmp.* 2>/dev/null)" ]; check "no dump left behind" $?
: >"$LOG"; run --yes --keep-cron >/dev/null 2>&1; ! grep -q "DISABLE_WP_CRON" "$LOG"; check "--keep-cron leaves cron alone" $?
: >"$LOG"; STAGING_HAS_WP=no run --yes >/dev/null 2>&1; grep -q "docker cp .*stg:/usr/local/bin/wp" "$LOG"; check "WP-CLI copied into the test container when missing" $?
: >"$LOG"; run --yes --skip-uploads >/dev/null 2>&1; grep -q "^rsync .*--exclude /wp-content/uploads/" "$LOG"; check "--skip-uploads excludes uploads" $?

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
