# HeimDB plugin

English | [日本語](README.ja.md)

A WordPress plugin that brings [HeimDB](https://github.com/tsukinowasha/heimdb)'s procedures (`CALL heimdb_*`) out of SQL and into wp-admin and wp-cli.

- Create and delete branches, set their limits, and create branch-only users
- Diff against the parent (per table → per row → both sides of one row), resolve conflicts, dry-run and merge
- Pull the parent's changes into a branch
- Time travel: changes since a time, one row then and now, restore (with a preview), and branch from a past time
- One-click undo right after a merge, sync or restore
- Tables excluded from merges, backups, GC, rebuilding old indexes, and `heimdb_status`

Requirements: WordPress 6.0 or later, PHP 8.2 or later (8.4 or later recommended), HeimDB 0.3.0 or later.

## Installation

Put this repository at `wp-content/plugins/heimdb` and activate it.

```sh
git clone https://github.com/tsukinowasha/heimdb-plugin.git wp-content/plugins/heimdb
wp plugin activate heimdb
```

## Configuration

Only an administrator (with the SUPER privilege) can call the procedures. Keep WordPress itself on the site user, and give only this plugin an administrator connection in `wp-config.php`:

```php
define( 'HEIMDB_ADMIN_USER', 'root' );
define( 'HEIMDB_ADMIN_PASSWORD', '...' );
// define( 'HEIMDB_ADMIN_HOST', 'heimdb-host:3306' );   // DB_HOST if omitted
```

Without `HEIMDB_ADMIN_USER`, the plugin connects as `DB_USER` (with HeimDB's `compose.yaml`, where only wp-cli runs as root, wp-cli works as is).

> Anyone who can run PHP on the site can read these constants. Put them only on the site that manages branches (usually production), not on staging sites. A staging site connects as a branch-only user created with `wp heimdb branch grant`.

### Staging sites

When HeimDB runs with `-branch-procedures` (or the environment variable `HEIMDB_BRANCH_PROCEDURES=1`), a staging site can do the following as its site user (with branch-only privileges), without the administrator constants:

- Diff its own branch (per table, per row, and the current values of both sides of the conflicting columns)
- Resolve conflicts one row at a time (who resolved and when is recorded; the production administrator reviews it and merges)
- Time travel and restore (back to when the branch was created), and undo the restore

Merging, pulling in the parent's changes, creating and deleting branches, limits, users, resolving all conflicts at once, changing the tables excluded from merges, backups, GC and `heimdb_status` stay administrator-only (done from the production site). A branch user cannot read the parent, so the row comparison shows the parent's values only for the conflicting columns. The user needs the EXECUTE privilege on the branch database (included in the `GRANT ALL` of `wp heimdb branch grant`).

The diff also shows a branch user the values that changed on the parent (production) after the fork. Do not use the flag if branches separate different tenants.

Times without an offset are read in the site's timezone (Settings > General), and are always passed to HeimDB in UTC with an offset.

## Admin screens

The **HeimDB** menu.

| Screen | What you can do |
| --- | --- |
| Branches | List, and create (parent, a past time, limits) |
| Branches › staging | Diff per table, the number of conflicts, resolve all conflicts to one side, dry run, merge, pull in the parent's changes (dry run, the side that conflicting columns keep), limits, branch-only users, delete (a site cannot delete the branch it is connected to) |
| Branches › staging › wp_posts | The action for each row (filter by action), and resolve each row. Conflicts that only the parent side can resolve, such as a post by an author who is not on the parent, get only the parent button |
| Branches › … › ID=1 | One row's values at the fork on the parent, now on the parent and now on the branch, side by side, with the conflicting columns highlighted. Long strings get a line diff. Conflicts can be resolved here too. For the same post added on both sides (the same natural key), the parent's row with another ID is shown as "parent now". A row of a table without a primary key is named by its hidden row ID (`__heimdb_row_id=N`) and cannot be read with SQL, so only the conflicting columns' values are shown |
| Time travel | Changes since a time (with buttons such as "1 hour ago"), one row then and now, restore preview and restore (all but comments and users / everything / selected tables), and a branch from that time. If the selected tables are one side of a pair (such as `wp_posts` and `wp_postmeta`), HeimDB's warning is shown with a button to preview restoring them together |
| Status | Tables excluded from merges, backup, GC, rebuilding old indexes (choose a branch), `heimdb_status` |

The admin bar shows the branch this site is connected to (red unless it is production).

When a staging site connects as a branch user, the **HeimDB** menu shows only that branch's diff and resolution, time travel, and status (the list of tables excluded from merges). Rows in the diff show who recorded a resolution and when (on the production site too).

The notice after a merge, sync or restore has an undo button (it sends the `heimdb_restore` arguments that HeimDB returns in the last result row). The plugin keeps no log of operations (stored in the database, a merge or restore would replace or roll back the log itself). To undo later, restore to a time before the operation on the time travel screen. wp-cli prints the undo command after each operation.

## wp-cli

```sh
wp heimdb branch list
wp heimdb branch create staging [--parent=main] [--at="2026-10-18 09:00"] [--max-size=2G] [--max-time=30s]
wp heimdb branch grant staging staging [--password=...] [--max-connections=20]   # refuses this site's DB_USER and the administrator; for an existing user, changes only what is given
wp heimdb branch limit staging 2G 30s
wp heimdb branch delete staging [--yes]

wp heimdb diff staging                       # summary per table (--all includes unchanged ones)
wp heimdb diff staging wp_posts              # per row ('*' for all tables)
wp heimdb diff staging --conflicts           # conflicts only
wp heimdb resolve staging wp_posts ID=1 branch     # branch | parent | none, '*' works too ('*' with branch warns about conflicts only the parent can resolve)
wp heimdb merge staging --dry-run
wp heimdb merge staging --yes                # prints the undo command on success
wp heimdb sync staging --dry-run             # pull the parent's changes into the branch
wp heimdb sync staging [--side=branch|parent] --yes   # refused on conflicts unless --side is given; prints the undo command on success

wp heimdb changes main "3 hours ago" [--tables=wp_users,wp_usermeta]
wp heimdb row main wp_options option_name=blogname --at="1 day ago" --compare
wp heimdb restore main "2026-10-18 09:00" --preview
wp heimdb restore main "2026-10-18 09:00" [--tables=wp_posts,wp_postmeta | --tables='*'] --yes
# restoring one side of a pair (e.g. --tables=wp_posts) prints a warning with the command that restores both

wp heimdb exclude add 'wp_yoast_%' | remove 'wp_yoast_%' | list
wp heimdb status | backup [<name>] | gc
wp heimdb rebuild-indexes [<branch>]         # rebuild old indexes that have no entries
```

Commands that print tables accept `--format=json` and the like. HeimDB errors are printed with their number (`[51004]`, ...) and exit with status 1. See `wp help heimdb <command>` for details.

`backup` writes under HeimDB's `-backup-dir`. With `-backup-dir` inside `-data` (`/data/backups` in Docker), a backup hard-links the files and finishes at once (since HeimDB 0.3.1).

`rebuild-indexes` rebuilds indexes on DECIMAL, FLOAT, DOUBLE, ENUM, SET and BIT columns that older HeimDB versions made without entries (such as `min_max_price` of WooCommerce's `wc_product_meta_lookup`), and lists what it rebuilt. Such an index reads the whole table each time. HeimDB rebuilds main's in the background after it starts, so this is usually for branches and anything the background rebuild skipped (such as a unique index with duplicates, which stops with `1062`). Writes to the branch wait while it runs.

On a staging site's wp-cli (a branch user, with HeimDB's `-branch-procedures`), `diff`, `resolve` with a row, `changes`, `row` and `restore` work on that branch. Everything else is refused.

## Hooks

A merge runs inside the database server, so HeimDB cannot call PHP hooks. This plugin fires them before and after it calls a procedure (from wp-admin or wp-cli).

| Hook | Arguments |
| --- | --- |
| `heimdb_before_merge` | `$branch`, `$dry_run` |
| `heimdb_after_merge` | `$branch`, `$rows` (the result of `heimdb_branch_merge`), `$undo` (the arguments to undo it, or null) |
| `heimdb_before_sync` | `$branch`, `$side` (`branch`, `parent` or null), `$dry_run` |
| `heimdb_after_sync` | `$branch`, `$rows` (the result of `heimdb_branch_sync`), `$undo` |
| `heimdb_before_restore` / `heimdb_after_restore` | `$branch`, `$at`, `$tables` (, `$rows`) |
| `heimdb_branch_created` / `heimdb_branch_deleted` | `$name` (, the `$branch` row) |
| `heimdb_capability` (filter) | The capability for the screens (default `manage_options`; on multisite `manage_network_options`, since HeimDB operations affect all sites) |

```php
// Example: if Yoast's tables are excluded from merges, rebuild its index after a merge (HeimDB's docs/plugins.md)
add_action( 'heimdb_after_merge', function ( $branch, $rows ) {
	foreach ( $rows as $row ) {
		if ( 'excluded' === $row['status'] && $row['inserts'] + $row['updates'] + $row['deletes'] > 0 ) {
			wp_schedule_single_event( time(), 'my_rebuild_yoast_index' );
			return;
		}
	}
}, 10, 2 );
```

From PHP, use `Heimdb\Client::instance()` (`branch_diff()`, `branch_merge()`, `branch_sync()`, `restore()`, `row()`, ...). HeimDB errors are `Heimdb\Error`, with the number in `getCode()` and the reason word (`unresolved_conflicts`, ...) in `$reason`.

## Development

```sh
docker compose -f dev/compose.yaml up -d      # HeimDB (127.0.0.1:3317) and WordPress (http://localhost:8090)
dev/wp core install --url=http://localhost:8090 --title=Dev --admin_user=admin --admin_password=admin --admin_email=dev@example.com
dev/wp plugin activate heimdb
HEIMDB_SITE_DB=wordpress@staging dev/wp post update 1 --post_title='from staging'   # write as the branch's site
php tests/run.php                              # unit tests of the parts that do not need WordPress
tests/e2e.sh                                   # end to end on the dev environment: CLI (branch → conflict → resolve → merge → undo → restore → users, limits, GC), admin screens (rendering, POST, undo) and branch users (CLI and the staging site)
docker run --rm -v "$PWD:/app:ro" wordpress:cli-php8.4 sh /app/tests/i18n-check.sh   # missing or stale translations
```

The staging site (http://localhost:8091) connects to the branch staging as the user staging (run `dev/wp heimdb branch create staging` and `dev/wp heimdb branch grant staging staging --password=staging-secret`, then `docker compose -f dev/compose.yaml --profile staging up -d`). HeimDB in the dev environment runs with `HEIMDB_BRANCH_PROCEDURES=1`.

The dev environment runs HeimDB 0.3.1 by default. For another version, start it with `HEIMDB_VERSION=0.3.0 docker compose -f dev/compose.yaml up -d` and run `tests/e2e.sh` with the same `HEIMDB_VERSION`.

GitHub Actions (`.github/workflows/ci.yml`) runs these three on push and pull request (unit tests on PHP 8.2, 8.4 and 8.5; E2E on HeimDB 0.3.0 and 0.3.1).

Translations (Japanese) are in `languages/`. `heimdb-ja.po` is the source; `.mo` and `.l10n.php` (WordPress 6.5 and later) are built from it. Words in HeimDB's results (statuses such as `changed` and `restore rows`) are translated on screen, but the `detail` text is shown as HeimDB returns it. After changing strings, rebuild them:

```sh
docker compose -f dev/compose.yaml run --rm -T -v "$PWD/languages:/out" --entrypoint sh cli -c '
  wp i18n make-pot wp-content/plugins/heimdb /out/heimdb.pot --slug=heimdb --domain=heimdb --exclude=dev,tests,languages &&
  cd /out && wp i18n update-po heimdb.pot heimdb-ja.po'
# after translating the empty msgstr entries in heimdb-ja.po
docker compose -f dev/compose.yaml run --rm -T -v "$PWD/languages:/out" --entrypoint sh cli -c '
  cd /out && wp i18n make-mo heimdb-ja.po . && wp i18n make-php heimdb-ja.po .'
```

For bug reports and pull requests, see [`CONTRIBUTING.md`](CONTRIBUTING.md). To report a vulnerability, see [`SECURITY.md`](SECURITY.md).

## License

[Apache License 2.0](LICENSE). Copyright 2026 Tsukinowa Inc.
