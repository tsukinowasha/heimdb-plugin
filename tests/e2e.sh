#!/usr/bin/env bash
# 開発環境（dev/compose.yaml）の HeimDB と WordPress で、プラグインを通しで確かめる。
#
#   docker compose -f dev/compose.yaml up -d
#   tests/e2e.sh
#
# ブランチ e2e を作り直して使う（本番の記事 1 のタイトルと、オプション blogdescription を書き換える）。
set -euo pipefail

cd "$(dirname "$0")/.."
WP_URL=${WP_URL:-http://localhost:8090}
BRANCH=e2e
# HeimDB はバージョンを SQL で返さないので、compose に渡すバージョン（なければ compose の既定）で、バージョンごとの確かめを選ぶ
HEIMDB_VERSION=${HEIMDB_VERSION:-$(sed -n 's/.*HEIMDB_VERSION:-\([0-9.]*\)}.*/\1/p' dev/compose.yaml)}
failures=0

wp() { dev/wp "$@" 2>/dev/null; }
wp_branch() { HEIMDB_SITE_DB="wordpress@$BRANCH" dev/wp "$@" 2>/dev/null; }

pass() { echo "ok   $1"; }
fail() { echo "FAIL $1"; failures=$((failures + 1)); }
expect() { # expect <name> <got> <want>
	if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (got '$2', want '$3')"; fi
}
version_at_least() { php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$HEIMDB_VERSION" "$1"; }

jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
curl_admin() { curl -s -b "$jar" "$WP_URL/wp-admin/admin.php?$1"; }
# admin_post <curl の -d など>: admin-post.php に POST して、リダイレクト先を出す
admin_post() { curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' -d action=heimdb "$@" "$WP_URL/wp-admin/admin-post.php"; }
form_field() { php tests/form-field.php "$@"; } # form_field <op> <field> < HTML
# 印の時刻は、直前の書き込みと同じ秒にならないよう、前後に 1 秒おく
mark_utc() { sleep 1; date -u '+%Y-%m-%d %H:%M:%S.000000+00:00'; sleep 1; }

# ---------------------------------------------------------------- 準備

echo "--- waiting for WordPress and HeimDB"
for i in $(seq 1 60); do
	if wp core is-installed; then break; fi
	if wp core install --url="$WP_URL" --title=Dev --admin_user=admin --admin_password=admin \
		--admin_email=dev@example.com --skip-email >/dev/null; then break; fi
	[ "$i" = 60 ] && { echo "WordPress did not come up"; exit 1; }
	sleep 2
done
wp plugin activate heimdb >/dev/null
wp option update timezone_string Asia/Tokyo >/dev/null
wp heimdb branch delete "$BRANCH" --yes >/dev/null 2>&1 || true
curl -s -c "$jar" -b "$jar" -o /dev/null "$WP_URL/wp-login.php"
curl -s -c "$jar" -b "$jar" -o /dev/null -d 'log=admin&pwd=admin&testcookie=1' "$WP_URL/wp-login.php"
echo "--- HeimDB $HEIMDB_VERSION"

# ---------------------------------------------------------------- ブランチ

echo "--- branch"
wp post update 1 --post_title='E2E base' >/dev/null
wp option update blogdescription 'E2E tagline' >/dev/null
database=$(wp heimdb branch create "$BRANCH" --max-size=1G --max-time=30s --porcelain)
expect "branch create prints the database" "$database" "wordpress@$BRANCH"
expect "branch list has the branch" "$(wp heimdb branch list --format=ids | tr ' ' '\n' | grep -cx "$BRANCH")" 1
expect "branch limit" "$(wp heimdb branch list --format=json | php -r '$b = array_column(json_decode(stream_get_contents(STDIN), true), null, "branch"); echo $b["e2e"]["max_size_bytes"], "/", $b["e2e"]["max_execution_ms"];')" "1073741824/30000"

# ---------------------------------------------------------------- 差分、競合、マージ

echo "--- diff, conflict, merge"
wp_branch post update 1 --post_title='E2E from branch' >/dev/null
new_post=$(wp_branch post create --post_title='E2E new post' --post_status=publish --porcelain)
wp post update 1 --post_title='E2E from main' >/dev/null
wp option update blogdescription 'E2E tagline on main' >/dev/null

expect "one conflict" "$(wp heimdb diff "$BRANCH" --conflicts --format=count)" 1
expect "the conflict is post 1" "$(wp heimdb diff "$BRANCH" --conflicts --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)[0]["id"];')" "ID=1"

if out=$(dev/wp heimdb merge "$BRANCH" --yes 2>&1); then
	fail "merge with a conflict is refused"
else
	case "$out" in *unresolved_conflicts*51004*) pass "merge with a conflict is refused (51004)" ;; *) fail "merge refusal message: $out" ;; esac
fi

wp heimdb resolve "$BRANCH" wp_posts ID=1 branch >/dev/null
expect "resolution recorded" "$(wp heimdb diff "$BRANCH" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID=1") echo $r["resolution"];')" branch
wp heimdb merge "$BRANCH" --dry-run >/dev/null
expect "dry run writes nothing" "$(wp post get 1 --field=post_title)" "E2E from main"

# HeimDB 0.2.13 から: 親にいない投稿者の投稿は、親の側でしか解決できない（ブランチの側は拒否される）
if version_at_least 0.2.13; then
	author=$(wp_branch user create e2e_author e2e_author@example.com --role=author --porcelain)
	orphan=$(wp_branch post create --post_title='E2E orphan' --post_author="$author" --post_status=publish --porcelain)
	expect "orphan post is parent-only" "$(wp heimdb diff "$BRANCH" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID='"$orphan"'") echo str_contains((string) $r["detail"], "only '\''parent'\'' can resolve this") ? "yes" : "no";')" yes
	if dev/wp heimdb resolve "$BRANCH" wp_posts "ID=$orphan" branch >/dev/null 2>&1; then fail "branch side is refused for the orphan"; else pass "branch side is refused for the orphan"; fi
	html=$(curl_admin "page=heimdb&branch=$BRANCH&table=wp_posts")
	if grep -q 'Only the parent value can be kept\|親の値しか残せません' <<<"$html"; then pass "admin offers only the parent side"; else fail "admin offers only the parent side"; fi
	out=$(dev/wp heimdb resolve "$BRANCH" '*' '*' branch 2>&1)
	case "$out" in *"can keep only the parent value"*"wp_posts ID=$orphan"*) pass "wildcard branch warns about the parent-only conflict" ;; *) fail "wildcard branch warns about the parent-only conflict: $out" ;; esac
	wp heimdb resolve "$BRANCH" wp_posts "ID=$orphan" parent >/dev/null
fi

merge_out=$(wp heimdb merge "$BRANCH" --yes)
expect "merge takes the branch title" "$(wp post get 1 --field=post_title)" "E2E from branch"
expect "merge brings the new post" "$(wp post get "$new_post" --field=post_title)" "E2E new post"
expect "merge keeps the main-only change" "$(wp option get blogdescription)" "E2E tagline on main"

# ---------------------------------------------------------------- 取り消しとタイムトラベル

echo "--- undo and time travel"
undo=$(grep '^To undo: wp heimdb restore ' <<<"$merge_out" | sed 's/^To undo: wp //')
[ -n "$undo" ] && pass "merge prints the undo command" || fail "merge prints the undo command"
eval "wp $undo --yes" >/dev/null
expect "undo restores the title" "$(wp post get 1 --field=post_title)" "E2E from main"
if wp post get "$new_post" --field=ID >/dev/null 2>&1; then fail "undo removes the merged post"; else pass "undo removes the merged post"; fi

before=$(mark_utc)
wp post update 1 --post_title='E2E after the mark' >/dev/null
expect "changes lists post 1" "$(wp heimdb changes main "$before" --tables=wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID=1") echo $r["change"];')" update
expect "row --at reads the past value" "$(wp heimdb row main wp_posts ID=1 --at="$before" --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["field"] === "post_title") echo $r["value"];')" "E2E from main"
expect "restore preview counts one update" "$(wp heimdb restore main "$before" --tables=wp_posts --preview --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)[0]["updates"];')" 1
wp heimdb restore main "$before" --tables=wp_posts --yes >/dev/null
expect "restore puts the title back" "$(wp post get 1 --field=post_title)" "E2E from main"

# HeimDB 0.2.14 から: 組の片方（wp_posts だけ）を戻すなら、まとめて戻すコマンドを勧める
if version_at_least 0.2.14; then
	before=$(mark_utc)
	wp post update 1 --post_title='E2E pair' >/dev/null
	wp post meta update 1 e2e_color blue >/dev/null
	out=$(dev/wp heimdb restore main "$before" --tables=wp_posts --preview 2>&1)
	case "$out" in *"To restore them together: wp heimdb restore 'main' "*"--tables='wp_posts,wp_postmeta' --preview"*) pass "restore preview suggests the paired table" ;; *) fail "restore preview suggests the paired table: $out" ;; esac
	wp heimdb restore main "$before" --tables=wp_posts,wp_postmeta --yes >/dev/null
	expect "restoring the pair puts the meta back" "$(wp post meta get 1 e2e_color)" ""
fi

# ---------------------------------------------------------------- 管理画面

echo "--- admin pages"
at=$(TZ=Asia/Tokyo date '+%Y-%m-%dT%H:%M:%S' -d '-1 hour' 2>/dev/null || TZ=Asia/Tokyo date -v-1H '+%Y-%m-%dT%H:%M:%S')
for page in \
	"page=heimdb" \
	"page=heimdb&branch=$BRANCH" \
	"page=heimdb&branch=$BRANCH&table=*" \
	"page=heimdb&branch=$BRANCH&table=wp_posts&id=ID%3D1" \
	"page=heimdb-time&branch=main&at=$at&do=changes" \
	"page=heimdb-time&branch=main&at=$at&do=preview" \
	"page=heimdb-time&branch=main&at=$at&table=wp_posts&id=ID%3D1" \
	"page=heimdb-status"; do
	html=$(curl_admin "$page")
	if grep -qiE 'Fatal error|Warning:|Notice:|Deprecated:|notice-error' <<<"$html"; then
		fail "admin $page has errors"
	elif ! grep -q 'class="wrap heimdb"' <<<"$html"; then
		fail "admin $page did not render"
	else
		pass "admin $page"
	fi
done

# ---------------------------------------------------------------- 管理画面の操作（POST、nonce、通知、取り消し）

echo "--- admin operations"
title=$(wp post get 1 --field=post_title)
mark=$(mark_utc)
wp post update 1 --post_title='E2E before admin restore' >/dev/null
html=$(curl_admin "page=heimdb-time&branch=main&at=$(php -r 'echo rawurlencode($argv[1]);' "$mark")&tables=wp_posts&do=preview")
nonce=$(form_field restore _wpnonce <<<"$html") && pass "admin restore form has a nonce" || fail "admin restore form has a nonce"
admin_post -d op=restore -d "_wpnonce=$nonce" -d branch=main --data-urlencode "at=$mark" -d scope=tables -d 'tables[]=wp_posts' \
	--data-urlencode "redirect_to=$WP_URL/wp-admin/admin.php?page=heimdb-status" >/dev/null
expect "admin restore puts the title back" "$(wp post get 1 --field=post_title)" "$title"
html=$(curl_admin "page=heimdb-status")
if grep -q 'notice-success heimdb-notice' <<<"$html"; then pass "admin restore shows its notice after the redirect"; else fail "admin restore shows its notice after the redirect"; fi
if grep -q 'notice-success heimdb-notice' <<<"$(curl_admin "page=heimdb-status")"; then fail "the notice is shown only once"; else pass "the notice is shown only once"; fi
nonce=$(form_field undo _wpnonce <<<"$html")
undo_branch=$(form_field undo branch <<<"$html")
undo_time=$(form_field undo time <<<"$html")
undo_tables=$(form_field undo tables <<<"$html")
expect "the undo button carries the restore" "$undo_branch $undo_tables" "main wp_posts"
admin_post -d op=undo -d "_wpnonce=$nonce" -d "branch=$undo_branch" --data-urlencode "time=$undo_time" -d "tables=$undo_tables" --data-urlencode "redirect_to=$WP_URL/wp-admin/admin.php?page=heimdb-status" >/dev/null
expect "admin undo brings the restored title back" "$(wp post get 1 --field=post_title)" "E2E before admin restore"
if form_field undo time <<<"$(curl_admin "page=heimdb-status")" >/dev/null; then pass "the undo notice can undo again"; else fail "the undo notice can undo again"; fi
if form_field undo time <<<"$(curl_admin "page=heimdb-status")" >/dev/null; then fail "the undo button is only in the notice"; else pass "the undo button is only in the notice"; fi
if [ -z "$(wp user meta get 1 heimdb_history)" ]; then pass "no history in usermeta"; else fail "no history in usermeta"; fi
loc=$(admin_post -d op=gc -d _wpnonce=bad --data-urlencode "redirect_to=$WP_URL/wp-admin/admin.php?page=heimdb-status")
[ -z "$loc" ] && pass "a bad nonce is refused" || fail "a bad nonce is refused (redirected to $loc)"

# ---------------------------------------------------------------- ブランチのユーザー、上限、マージから外す表、GC

echo "--- grant, limits, exclusions, gc"
site_as() { # site_as <database> <wp の引数...>: e2e_site でつなぐサイトとして
	(cd dev && docker compose run --rm -T -e WORDPRESS_DB_NAME="$1" -e WORDPRESS_DB_USER=e2e_site -e WORDPRESS_DB_PASSWORD=e2e-secret cli wp "${@:2}" 2>/dev/null)
}
if dev/wp heimdb branch grant "$BRANCH" root >/dev/null 2>&1; then fail "grant refuses the admin user"; else pass "grant refuses the admin user"; fi
if dev/wp heimdb branch grant "$BRANCH" wordpress >/dev/null 2>&1; then fail "grant refuses the site user"; else pass "grant refuses the site user"; fi
wp heimdb branch grant "$BRANCH" e2e_site --password=e2e-secret >/dev/null
expect "the granted user reads the branch" "$(site_as "wordpress@$BRANCH" option get blogdescription)" "$(wp_branch option get blogdescription)"
if site_as wordpress option get blogdescription >/dev/null; then fail "the granted user cannot read production"; else pass "the granted user cannot read production"; fi
case "$(wp heimdb branch grant "$BRANCH" e2e_site)" in *existing*) pass "grant again keeps the existing user" ;; *) fail "grant again keeps the existing user" ;; esac
expect "the existing user keeps its password" "$(site_as "wordpress@$BRANCH" option get blogdescription)" "$(wp_branch option get blogdescription)"

if wp_branch heimdb branch delete "$BRANCH" --yes; then fail "a branch site cannot delete its own branch"; else pass "a branch site cannot delete its own branch"; fi

wp heimdb branch limit "$BRANCH" 2G 0 >/dev/null
expect "branch limit alone" "$(wp heimdb branch list --format=json | php -r '$b = array_column(json_decode(stream_get_contents(STDIN), true), null, "branch"); echo $b["e2e"]["max_size_bytes"], "/", $b["e2e"]["max_execution_ms"];')" "2147483648/"

wp heimdb exclude add 'wp_e2e_%' >/dev/null
expect "exclude add" "$(wp heimdb exclude list | grep -cx 'wp_e2e_%')" 1
wp heimdb exclude remove 'wp_e2e_%' >/dev/null
expect "exclude remove" "$(wp heimdb exclude list | grep -cx 'wp_e2e_%' || true)" 0

case "$(wp heimdb gc --yes)" in *"Success: Boundary "*"versions."*) pass "gc" ;; *) fail "gc" ;; esac
# 開発環境の HeimDB は -backup-dir なしで動くので、バックアップは HeimDB のエラーとして返る
case "$(dev/wp heimdb backup 2>&1)" in *"backups are disabled"*"[1105]"*) pass "backup reports the HeimDB error" ;; *) fail "backup reports the HeimDB error" ;; esac

# ---------------------------------------------------------------- ブランチのユーザー（HeimDB 0.2.15 の -branch-procedures）

if version_at_least 0.2.15; then
	echo "--- branch user"
	branch_user() { # 管理者の設定のない、e2e_site でつなぐブランチのサイトとして
		(cd dev && docker compose run --rm -T -e WORDPRESS_DB_NAME="wordpress@$BRANCH" -e WORDPRESS_DB_USER=e2e_site -e WORDPRESS_DB_PASSWORD=e2e-secret -e WORDPRESS_CONFIG_EXTRA= cli wp "$@")
	}
	wp heimdb branch limit "$BRANCH" 0 0 >/dev/null
	branch_start=$(mark_utc)
	wp_branch post update 1 --post_title='E2E branch user' >/dev/null
	wp post update 1 --post_title='E2E main for branch user' >/dev/null
	expect "branch user sees the conflict" "$(branch_user heimdb diff "$BRANCH" --conflicts --format=json 2>/dev/null | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID=1") echo json_decode((string) $r["conflict_values"], true)["post_title"]["parent"] ?? "";')" "E2E main for branch user"
	out=$(branch_user heimdb resolve "$BRANCH" '*' '*' branch 2>&1 || true)
	case "$out" in *"a branch user must name a row"*) pass "branch user cannot resolve with '*'" ;; *) fail "branch user cannot resolve with '*': $out" ;; esac
	branch_user heimdb resolve "$BRANCH" wp_posts ID=1 branch >/dev/null 2>&1
	expect "admin sees who resolved" "$(wp heimdb diff "$BRANCH" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID=1") echo $r["resolution"], " ", $r["resolved_by"];')" "branch 'e2e_site'@'%'"
	out=$(branch_user heimdb merge "$BRANCH" --yes 2>&1 || true)
	case "$out" in *"command denied"*"Only an administrator can do this"*) pass "branch user cannot merge" ;; *) fail "branch user cannot merge: $out" ;; esac
	out=$(branch_user heimdb diff main 2>&1 || true)
	case "$out" in *"only on the branch it is connected to"*) pass "branch user cannot look at main" ;; *) fail "branch user cannot look at main: $out" ;; esac
	out=$(branch_user heimdb changes "$BRANCH" '2000-01-01' 2>&1 || true)
	case "$out" in *"before the branch was created"*) pass "branch user cannot go back before the branch" ;; *) fail "branch user cannot go back before the branch: $out" ;; esac
	mark=$(mark_utc)
	wp_branch post update 1 --post_title='E2E branch user after mark' >/dev/null
	branch_user heimdb restore "$BRANCH" "$mark" --tables=wp_posts --yes >/dev/null 2>&1
	expect "branch user restores its branch" "$(wp_branch post get 1 --field=post_title)" "E2E branch user"

	# 管理画面（管理者の設定のないステージングのサイト）
	export STAGING_BRANCH=$BRANCH STAGING_USER=e2e_site STAGING_PASSWORD=e2e-secret
	STAGING_URL=http://localhost:${STAGING_PORT:-8091}
	(cd dev && docker compose --profile staging up -d staging >/dev/null 2>&1)
	sjar=$(mktemp)
	for i in $(seq 1 30); do curl -s -o /dev/null "$STAGING_URL/wp-login.php" && break; sleep 1; done
	curl -s -c "$sjar" -b "$sjar" -o /dev/null "$STAGING_URL/wp-login.php"
	curl -s -c "$sjar" -b "$sjar" -o /dev/null -d 'log=admin&pwd=admin&testcookie=1' "$STAGING_URL/wp-login.php"
	at=$(php -r 'echo rawurlencode($argv[1]);' "$branch_start")
	for page in \
		"page=heimdb" \
		"page=heimdb&branch=$BRANCH&table=wp_posts" \
		"page=heimdb&branch=$BRANCH&table=wp_posts&id=ID%3D1" \
		"page=heimdb-time&at=$at&do=changes" \
		"page=heimdb-time&at=$at&do=preview" \
		"page=heimdb-status"; do
		html=$(curl -s -b "$sjar" "$STAGING_URL/wp-admin/admin.php?$page")
		if grep -qiE 'Fatal error|Warning:|Notice:|Deprecated:|notice-error' <<<"$html"; then
			fail "branch site $page has errors"
		elif ! grep -q 'class="wrap heimdb"' <<<"$html"; then
			fail "branch site $page did not render"
		else
			pass "branch site $page"
		fi
	done
	html=$(curl -s -b "$sjar" "$STAGING_URL/wp-admin/admin.php?page=heimdb")
	if grep -q 'name="op" value="merge"\|name="op" value="branch_delete"' <<<"$html"; then fail "branch site offers no merge or delete"; else pass "branch site offers no merge or delete"; fi
	html=$(curl -s -b "$sjar" "$STAGING_URL/wp-admin/admin.php?page=heimdb&branch=$BRANCH&table=wp_posts&id=ID%3D1")
	if grep -q 'E2E main for branch user' <<<"$html"; then pass "branch site shows the parent value of the conflict"; else fail "branch site shows the parent value of the conflict"; fi
	nonce=$(form_field resolve _wpnonce <<<"$html")
	curl -s -b "$sjar" -c "$sjar" -o /dev/null -d action=heimdb -d op=resolve -d "_wpnonce=$nonce" -d "branch=$BRANCH" -d table=wp_posts -d row=ID=1 -d side=parent \
		--data-urlencode "redirect_to=$STAGING_URL/wp-admin/admin.php?page=heimdb" "$STAGING_URL/wp-admin/admin-post.php"
	expect "branch site records a resolution" "$(wp heimdb diff "$BRANCH" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === "ID=1") echo $r["resolution"], " ", $r["resolved_by"];')" "parent 'e2e_site'@'%'"
	(cd dev && docker compose --profile staging rm -sf staging >/dev/null 2>&1)
	rm -f "$sjar"
fi

# ---------------------------------------------------------------- 両側で足された同じ投稿（HeimDB 0.2.16 から、中身も同じなら skip）

if version_at_least 0.2.16; then
	echo "--- duplicates"
	nav() { "$1" post create --post_type=wp_navigation --post_name="$2" --post_title='E2E nav' --post_content="$3" --post_status=publish --porcelain; }
	nav wp e2e-nav-same '<!-- wp:page-list /-->' >/dev/null
	same=$(nav wp_branch e2e-nav-same '<!-- wp:page-list /-->')
	main_other=$(nav wp e2e-nav-other 'E2E nav on main')
	other=$(nav wp_branch e2e-nav-other 'E2E nav on the branch')
	row_of() { wp heimdb diff "$BRANCH" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["id"] === $argv[1]) echo $r["action"], " ", $r["parent_change"];' "ID=$1"; }
	expect "the same post added on both sides is skipped" "$(row_of "$same")" "skip insert"
	expect "a different post with the same name is a conflict" "$(row_of "$other")" "conflict insert"
	html=$(curl_admin "page=heimdb&branch=$BRANCH&table=wp_posts&id=ID%3D$other")
	if grep -q "(ID=$main_other)" <<<"$html" && grep -q 'E2E nav on main' <<<"$html"; then pass "row page shows the parent's row of the duplicate"; else fail "row page shows the parent's row of the duplicate"; fi
fi

# ---------------------------------------------------------------- 主キーのない表と、複数列の主キー（HeimDB 0.2.20 から）

if version_at_least 0.2.20; then
	echo "--- keyless and composite keys"
	KB=e2e-keys
	sql() { (cd dev && docker compose run --rm -T -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root-secret -e WORDPRESS_DB_NAME="$1" cli wp db query "$2" >/dev/null 2>&1); }
	sql wordpress "DROP TABLE IF EXISTS wp_e2e_log; DROP TABLE IF EXISTS wp_e2e_multi;
		CREATE TABLE wp_e2e_log (ip varchar(40) NOT NULL, n int NOT NULL);
		CREATE TABLE wp_e2e_multi (k varchar(20) NOT NULL, d date NOT NULL, v text, PRIMARY KEY (k, d));
		INSERT INTO wp_e2e_log VALUES ('192.0.2.1', 1); INSERT INTO wp_e2e_multi VALUES ('a,b=c', '2026-10-01', 'x');"
	wp heimdb branch create "$KB" >/dev/null
	sql "wordpress@$KB" "UPDATE wp_e2e_log SET n = 10; UPDATE wp_e2e_multi SET v = 'E2E multi branch'"
	sql wordpress "UPDATE wp_e2e_log SET n = 20; UPDATE wp_e2e_multi SET v = 'E2E multi main'"
	log_id=$(wp heimdb diff "$KB" wp_e2e_log --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)[0]["id"] ?? "";')
	case "$log_id" in __heimdb_row_id=*) pass "a keyless row is named by its hidden row id" ;; *) fail "a keyless row is named by its hidden row id (got '$log_id')" ;; esac
	html=$(curl_admin "page=heimdb&branch=$KB&table=wp_e2e_log&id=$(php -r 'echo rawurlencode($argv[1]);' "$log_id")")
	if grep -q 'notice-error' <<<"$html"; then fail "keyless row page has no error"; else pass "keyless row page has no error"; fi
	if grep -q 'hidden row ID' <<<"$html" && grep -q '>20<' <<<"$html"; then pass "keyless row page shows the conflicting values"; else fail "keyless row page shows the conflicting values"; fi
	case "$(dev/wp heimdb row "$KB" wp_e2e_log "$log_id" 2>&1)" in *"hidden row ID"*) pass "cli row explains a keyless row" ;; *) fail "cli row explains a keyless row" ;; esac
	html=$(curl_admin "page=heimdb&branch=$KB&table=wp_e2e_multi&id=$(php -r 'echo rawurlencode($argv[1]);' 'k=a,b=c,d=2026-10-01')")
	if grep -q 'E2E multi main' <<<"$html" && grep -q 'E2E multi branch' <<<"$html"; then pass "composite key row page shows both sides"; else fail "composite key row page shows both sides"; fi
	wp heimdb branch delete "$KB" --yes >/dev/null
	sql wordpress "DROP TABLE wp_e2e_log; DROP TABLE wp_e2e_multi"
fi

# ---------------------------------------------------------------- 親の変更の取り込み（HeimDB 0.2.33 から）

if version_at_least 0.2.33; then
	echo "--- sync"
	SB=e2e-sync
	wp_sync() { HEIMDB_SITE_DB="wordpress@$SB" dev/wp "$@" 2>/dev/null; }
	kept=$(wp post create --post_title='E2E sync kept' --post_status=publish --porcelain)
	both=$(wp post create --post_title='E2E sync both' --post_status=publish --porcelain)
	wp heimdb branch create "$SB" >/dev/null
	wp_sync post update "$kept" --post_title='E2E sync branch change' >/dev/null
	main_post=$(wp post create --post_title='E2E sync from main' --post_status=publish --porcelain)
	wp comment create --comment_post_ID="$main_post" --comment_content='E2E sync comment' >/dev/null
	wp option update blogdescription 'E2E sync tagline' >/dev/null

	out=$(wp heimdb sync "$SB" --dry-run --format=json | head -1) # 次の行は Success:
	expect "sync dry run counts the parent's new post" "$(php -r 'foreach (json_decode($argv[1], true) as $r) if ($r["table_name"] === "wp_posts") echo $r["inserts"];' "$out")" 1
	if wp_sync post get "$main_post" --field=ID >/dev/null 2>&1; then fail "sync dry run writes nothing"; else pass "sync dry run writes nothing"; fi

	html=$(curl_admin "page=heimdb&branch=$SB")
	nonce=$(form_field sync _wpnonce <<<"$html")
	[ -n "$nonce" ] && pass "branch page offers the sync" || fail "branch page offers the sync"
	admin_post -d op=sync -d "_wpnonce=$nonce" -d "branch=$SB" -d side= --data-urlencode "redirect_to=$WP_URL/wp-admin/admin.php?page=heimdb&branch=$SB" >/dev/null
	html=$(curl_admin "page=heimdb&branch=$SB")
	if grep -q 'Pulled the changes of main\|main の変更を' <<<"$html"; then pass "admin sync notice"; else fail "admin sync notice"; fi
	expect "sync brings the parent's post" "$(wp_sync post get "$main_post" --field=post_title)" "E2E sync from main"
	expect "sync brings the parent's comment" "$(wp_sync comment list --post_id="$main_post" --field=comment_content)" "E2E sync comment"
	expect "sync brings the parent's option" "$(wp_sync option get blogdescription)" "E2E sync tagline"
	expect "sync keeps the branch's change" "$(wp_sync post get "$kept" --field=post_title)" "E2E sync branch change"
	# ブランチの変更は kept の更新だけ（そのリビジョンは skip）
	expect "copied rows are not the branch's changes" "$(wp heimdb diff "$SB" wp_posts --format=json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if ($r["action"] !== "skip") echo $r["id"], " ";')" "ID=$kept "

	wp_sync post update "$both" --post_title='E2E sync both on branch' >/dev/null
	wp post update "$both" --post_title='E2E sync both on main' >/dev/null
	out=$(dev/wp heimdb sync "$SB" --yes 2>&1) && fail "sync with a conflict is refused" || case "$out" in *"sync refused (unresolved_conflicts)"*"ID=$both"*) pass "sync with a conflict is refused" ;; *) fail "sync refusal message: $out" ;; esac
	out=$(wp heimdb sync "$SB" --side=parent --yes)
	expect "sync --side=parent takes the parent" "$(wp_sync post get "$both" --field=post_title)" "E2E sync both on main"
	undo=$(grep '^To undo: wp heimdb restore ' <<<"$out" | sed 's/^To undo: wp //')
	[ -n "$undo" ] && pass "sync prints the undo command" || fail "sync prints the undo command"
	eval "wp $undo --yes" >/dev/null
	expect "undo of the sync puts the branch back" "$(wp_sync post get "$both" --field=post_title)" "E2E sync both on branch"

	wp heimdb branch delete "$SB" --yes >/dev/null
	wp post delete "$kept" "$both" "$main_post" --force >/dev/null
fi

# ---------------------------------------------------------------- 古いインデックスの作り直し（HeimDB 0.2.39 から）

echo "--- rebuild indexes"
if version_at_least 0.2.39; then
	# 今の HeimDB で作った表のインデックスは、初めからエントリを持つので、作り直すものはない
	case "$(dev/wp heimdb rebuild-indexes 2>&1)" in *"main has no indexes to rebuild"*) pass "rebuild-indexes on main" ;; *) fail "rebuild-indexes on main" ;; esac
	expect "rebuild-indexes on a branch" "$(wp heimdb rebuild-indexes "$BRANCH" --format=count)" 0
	html=$(curl_admin "page=heimdb-status")
	nonce=$(form_field rebuild_indexes _wpnonce <<<"$html")
	[ -n "$nonce" ] && pass "status page offers rebuilding indexes" || fail "status page offers rebuilding indexes"
	admin_post -d op=rebuild_indexes -d "_wpnonce=$nonce" -d "branch=$BRANCH" --data-urlencode "redirect_to=$WP_URL/wp-admin/admin.php?page=heimdb-status" >/dev/null
	html=$(curl_admin "page=heimdb-status")
	if grep -q "$BRANCH has no indexes to rebuild\|$BRANCH には作り直すインデックスがありません" <<<"$html"; then pass "admin rebuild indexes notice"; else fail "admin rebuild indexes notice"; fi
else
	case "$(dev/wp heimdb rebuild-indexes 2>&1)" in *"needs HeimDB 0.2.39 or later"*) pass "rebuild-indexes explains the HeimDB version" ;; *) fail "rebuild-indexes explains the HeimDB version" ;; esac
fi

wp heimdb branch delete "$BRANCH" --yes >/dev/null

echo
if [ "$failures" -gt 0 ]; then
	echo "$failures failed"
	exit 1
fi
echo "all passed"
