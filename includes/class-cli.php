<?php
namespace Heimdb;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Manages HeimDB branches, diffs, merges, time travel and restores.
 *
 * Procedures are called as HEIMDB_ADMIN_USER in wp-config.php (DB_USER if it is not set). Times without an offset are
 * read in the site's timezone. Relative times such as `2 hours ago` work too.
 *
 * A staging site that connects as a branch-only user can use diff, resolve with a row, changes, row and restore on its
 * branch when HeimDB runs with -branch-procedures (merges and branch management are done on production).
 *
 * ## EXAMPLES
 *
 *     wp heimdb branch create staging
 *     wp heimdb diff staging
 *     wp heimdb diff staging wp_posts
 *     wp heimdb resolve staging wp_posts ID=1 branch
 *     wp heimdb merge staging --dry-run
 *     wp heimdb merge staging --yes
 *     wp heimdb sync staging --dry-run
 *     wp heimdb changes main "1 hour ago"
 *     wp heimdb restore main "2026-10-18 09:00" --preview
 */
class CLI {
	/**
	 * Shows the diff against the parent.
	 *
	 * Without a table, shows a summary per table. With a table (`*` for all), shows the action for each row.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch.
	 *
	 * [<table>]
	 * : The table. `*` for the rows of all tables.
	 *
	 * [--conflicts]
	 * : Show only the conflicting rows (and those with a recorded resolution). <table> defaults to `*`.
	 *
	 * [--all]
	 * : In the summary, also show `unchanged` tables that the merge would not change.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb diff staging
	 *     wp heimdb diff staging wp_posts
	 *     wp heimdb diff staging --conflicts
	 */
	public function diff( $args, $assoc ) {
		list( $branch ) = $args;
		$table          = $args[1] ?? null;
		$conflicts      = Utils\get_flag_value( $assoc, 'conflicts', false );
		if ( $conflicts && null === $table ) {
			$table = '*';
		}
		$rows = $this->run( fn( Client $c ) => $c->branch_diff( $branch, $table ) );
		if ( null === $table && ! Utils\get_flag_value( $assoc, 'all', false ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => 'unchanged' !== $r['status'] ) );
		}
		if ( $conflicts ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => 'conflict' === $r['action'] || null !== $r['resolution'] ) );
		}
		if ( ! $rows && 'table' === $assoc['format'] ) {
			WP_CLI::success( $conflicts ? 'No conflicts.' : 'No differences.' );
			return;
		}
		self::output( $rows, $assoc );
	}

	/**
	 * Records which side resolves a conflict.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch.
	 *
	 * <table>
	 * : The table (`*` for all).
	 *
	 * <row>
	 * : The row, as the id in the diff (`ID=1`, `option_name=blogname`). `*` for all conflicts in the table.
	 *
	 * <side>
	 * : The side to keep.
	 * ---
	 * options:
	 *   - branch
	 *   - parent
	 *   - none
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb resolve staging wp_posts ID=1 branch
	 *     wp heimdb resolve staging '*' '*' parent
	 */
	public function resolve( $args ) {
		list( $branch, $table, $row, $side ) = $args;
		$this->run( fn( Client $c ) => $c->branch_resolve( $branch, $table, $row, $side ) );
		WP_CLI::success( "Recorded: $table $row -> $side" );
		// ワイルドカードの 'branch' は、親の側でしか解決できない競合には当たらない。
		if ( 'branch' === $side && '*' === $row ) {
			$left = $this->run( fn( Client $c ) => $c->parent_only_conflicts( $branch, $table ) );
			if ( $left ) {
				WP_CLI::warning( sprintf( '%d conflict(s) can keep only the parent value and are still unresolved: %s', count( $left ), implode( ', ', array_map( fn( $r ) => $r['table_name'] . ' ' . $r['id'], array_slice( $left, 0, 5 ) ) ) . ( count( $left ) > 5 ? ', ...' : '' ) ) );
			}
		}
	}

	/**
	 * Merges a branch into its parent.
	 *
	 * With unresolved conflicts, the merge is refused and nothing is written (see them with
	 * `wp heimdb diff <branch> --conflicts` and resolve them with `wp heimdb resolve`). On success, prints the command
	 * that undoes the merge.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch.
	 *
	 * [--dry-run]
	 * : Run the merge the same way as a real one, then discard it.
	 *
	 * [--yes]
	 * : Merge without asking.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb merge staging --dry-run
	 *     wp heimdb merge staging --yes
	 */
	public function merge( $args, $assoc ) {
		list( $branch ) = $args;
		$dry_run        = Utils\get_flag_value( $assoc, 'dry-run', false );
		if ( ! $dry_run ) {
			$parent = $this->run( fn( Client $c ) => $c->branch( $branch )['parent'] );
			WP_CLI::confirm( "Merge $branch into " . ( $parent ?? '(none)' ) . '?', $assoc );
		}
		$rows   = $this->run( fn( Client $c ) => $c->branch_merge( $branch, $dry_run ) );
		$undo   = Client::undo_args( $rows );
		$tables = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
		self::output( $tables, $assoc );
		if ( $dry_run ) {
			WP_CLI::success( 'Dry run: the merge would succeed (nothing was written).' );
			return;
		}
		WP_CLI::success( $tables ? 'Merged.' : 'Nothing to merge.' );
		if ( $undo ) {
			WP_CLI::log( 'To undo: ' . self::restore_command( $undo ) );
		}
	}

	/**
	 * Pulls the parent's changes into a branch.
	 *
	 * Brings the latest of production into a branch under review, for example after other branches were merged.
	 * Changes on the branch that were not merged are kept. Comments and users are copied too; environment options
	 * (siteurl, home, ...) and tables excluded from merges are not. If both sides changed the same columns, the sync is
	 * refused and nothing is written unless --side is given. Resolutions recorded on the branch are cleared. On success,
	 * prints the command that undoes the sync.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch.
	 *
	 * [--side=<side>]
	 * : The side that conflicting columns keep, for all conflicts. Rows added on both sides with the same ID can only take the parent.
	 * ---
	 * options:
	 *   - branch
	 *   - parent
	 * ---
	 *
	 * [--dry-run]
	 * : Check the counts and conflicts without writing.
	 *
	 * [--yes]
	 * : Pull in without asking.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb sync staging --dry-run
	 *     wp heimdb sync staging --yes
	 *     wp heimdb sync staging --side=parent --yes
	 */
	public function sync( $args, $assoc ) {
		list( $branch ) = $args;
		$side           = $assoc['side'] ?? null;
		$dry_run        = Utils\get_flag_value( $assoc, 'dry-run', false );
		if ( ! $dry_run ) {
			$parent = $this->run( fn( Client $c ) => $c->branch( $branch )['parent'] );
			WP_CLI::confirm( 'Pull in the changes of ' . ( $parent ?? '(none)' ) . " into $branch?", $assoc );
		}
		$rows   = $this->run( fn( Client $c ) => $c->branch_sync( $branch, $side, $dry_run ) );
		$undo   = Client::undo_args( $rows );
		$tables = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
		self::output( $tables, $assoc );
		if ( $dry_run ) {
			WP_CLI::success( 'Dry run: the sync would succeed (nothing was written).' );
			return;
		}
		WP_CLI::success( $undo ? 'Synced.' : 'Nothing to sync.' );
		if ( $undo ) {
			WP_CLI::log( 'To undo: ' . self::restore_command( $undo ) );
		}
	}

	/**
	 * Lists the rows changed since a time.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch (main for production).
	 *
	 * <time>
	 * : The time (`2026-10-18 09:00`, `1 hour ago`, ...). Without an offset, in the site's timezone.
	 *
	 * [--tables=<tables>]
	 * : Tables, comma-separated. All if omitted.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb changes main "3 hours ago"
	 *     wp heimdb changes main "2026-10-12 03:00" --tables=wp_users,wp_usermeta
	 */
	public function changes( $args, $assoc ) {
		list( $branch, $time ) = $args;
		$at                    = self::time( $time );
		$rows                  = $this->run( fn( Client $c ) => $c->changes( $branch, $at, $assoc['tables'] ?? null ) );
		foreach ( $rows as &$row ) {
			$row['changed_at'] = Time::display( $row['changed_at'], true );
		}
		unset( $row );
		if ( ! $rows && 'table' === $assoc['format'] ) {
			WP_CLI::success( 'No changes since ' . Time::display( $at ) . '.' );
			return;
		}
		self::output( $rows, $assoc );
	}

	/**
	 * Restores a branch to a past time.
	 *
	 * Without tables, comments and users (wp_comments, wp_commentmeta, wp_users, wp_usermeta) are kept as they are now.
	 * A restore can be undone (the command that undoes it is printed).
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch (main for production).
	 *
	 * <time>
	 * : The time. Without an offset, in the site's timezone.
	 *
	 * [--tables=<tables>]
	 * : Tables to restore, comma-separated, or `*` for all.
	 *
	 * [--preview]
	 * : Show what would change without restoring.
	 *
	 * [--yes]
	 * : Restore without asking.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb restore main "2026-10-18 09:00" --preview
	 *     wp heimdb restore main "2026-10-18 09:00" --tables=wp_posts,wp_postmeta --yes
	 */
	public function restore( $args, $assoc ) {
		list( $branch, $time ) = $args;
		$at                    = self::time( $time );
		$tables                = $assoc['tables'] ?? null;
		if ( Utils\get_flag_value( $assoc, 'preview', false ) ) {
			$rows = $this->run( fn( Client $c ) => $c->restore_preview( $branch, $at, $tables ) );
			if ( ! $rows && 'table' === $assoc['format'] ) {
				WP_CLI::success( 'Nothing to restore.' );
				return;
			}
			self::output( $rows, $assoc );
			self::pair_warning( $rows, true );
			return;
		}
		WP_CLI::confirm( "Restore $branch to " . Time::display( $at, true ) . ( $tables ? " ($tables)" : '' ) . '?', $assoc );
		$rows = $this->run( fn( Client $c ) => $c->restore( $branch, $at, $tables ) );
		$undo = Client::undo_args( $rows );
		self::output( array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) ), $assoc );
		WP_CLI::success( 'Restored.' );
		self::pair_warning( $rows, false );
		if ( $undo ) {
			WP_CLI::log( 'To undo: ' . self::restore_command( $undo ) );
		}
	}

	/**
	 * 組になる表（wp_posts と wp_postmeta など）の片方だけを戻す（戻した）なら、まとめて戻すコマンドを出す。
	 */
	private static function pair_warning( array $rows, bool $preview ): void {
		$pair = Client::restore_suggestion( $rows );
		if ( ! $pair ) {
			return;
		}
		WP_CLI::warning(
			( $preview ? 'Some rows also changed in a paired table that would not be restored (see detail). To restore them together: ' : 'Some rows also changed in a paired table that was not restored (see detail). To restore them together: ' )
			. self::restore_command( $pair ) . ( $preview ? ' --preview' : '' )
		);
	}

	/**
	 * Reads one row of a table as of a time (time travel).
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch (main for production).
	 *
	 * <table>
	 * : The table.
	 *
	 * <row>
	 * : The row, as the id in the diff or the list of changes (`ID=12`, `option_name=blogname`).
	 *
	 * [--at=<time>]
	 * : The time. Now if omitted.
	 *
	 * [--compare]
	 * : Show the value then and now side by side, and mark the columns that differ.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp heimdb row main wp_options option_name=blogname --at="1 day ago" --compare
	 *     wp heimdb row main wp_posts ID=12 --at="2026-10-18 09:00"
	 */
	public function row( $args, $assoc ) {
		list( $branch, $table, $id ) = $args;
		$at                          = isset( $assoc['at'] ) ? self::time( $assoc['at'] ) : null;
		$then                        = $this->run( fn( Client $c ) => $c->row( $branch, $table, $id, $at ) );
		if ( ! Utils\get_flag_value( $assoc, 'compare', false ) ) {
			if ( null === $then ) {
				WP_CLI::error( 'The row did not exist at that time.' );
			}
			$items = array();
			foreach ( $then as $field => $value ) {
				$items[] = array( 'field' => $field, 'value' => $value );
			}
			self::output( $items, $assoc );
			return;
		}
		$now    = $this->run( fn( Client $c ) => $c->row( $branch, $table, $id ) );
		$fields = array_keys( $then ?? $now ?? array() );
		$items  = array();
		foreach ( $fields as $field ) {
			$a       = null === $then ? null : $then[ $field ];
			$b       = null === $now ? null : $now[ $field ];
			$items[] = array(
				'field'   => $field,
				'changed' => $a === $b && ( null === $then ) === ( null === $now ) ? '' : '*',
				'then'    => null === $then ? '(no row)' : $a,
				'now'     => null === $now ? '(no row)' : $b,
			);
		}
		if ( ! $items ) {
			WP_CLI::error( 'The row exists neither then nor now.' );
		}
		self::output( $items, $assoc );
	}

	/**
	 * Shows the status of HeimDB.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function status( $args, $assoc ) {
		$status = $this->run( fn( Client $c ) => $c->status() );
		$items  = array();
		foreach ( $status as $name => $value ) {
			$items[] = array( 'name' => $name, 'value' => $value );
		}
		self::output( $items, $assoc );
	}

	/**
	 * Takes an online backup (under HeimDB's -backup-dir).
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : The name (letters, digits, `.`, `_`, `-`). The time if omitted.
	 */
	public function backup( $args ) {
		$row = $this->run( fn( Client $c ) => $c->backup( $args[0] ?? null ) );
		WP_CLI::success( sprintf( 'Backed up to %s (%s bytes, at %s).', $row['path'], $row['bytes'], Time::display( $row['backed_up_at'], true ) ) );
	}

	/**
	 * Removes versions older than the retention period now.
	 *
	 * Times before the boundary can no longer be used for time travel, restores or creating branches.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Remove without asking.
	 */
	public function gc( $args, $assoc ) {
		WP_CLI::confirm( 'Remove versions older than the retention period now?', $assoc );
		$row = $this->run( fn( Client $c ) => $c->gc() );
		WP_CLI::success( sprintf( 'Boundary %s; removed %s versions.', Time::display( $row['boundary'], true ), $row['versions'] ) );
	}

	/**
	 * Creates entries for existing indexes on DECIMAL, FLOAT, DOUBLE, ENUM, SET and BIT columns that have none.
	 *
	 * Such indexes, made by older HeimDB versions, read the whole table each time. HeimDB rebuilds main's in the
	 * background after it starts, so this is usually for branches and anything that skipped. Writes to the branch wait
	 * while it runs.
	 *
	 * ## OPTIONS
	 *
	 * [<branch>]
	 * : The branch. This site's branch if omitted.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @subcommand rebuild-indexes
	 */
	public function rebuild_indexes( $args, $assoc ) {
		$branch = $args[0] ?? Client::site_branch();
		$rows   = $this->run( fn( Client $c ) => $c->rebuild_indexes( $branch ) );
		if ( ! $rows && 'table' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::success( sprintf( '%s has no indexes to rebuild.', $branch ) );
			return;
		}
		self::output( $rows, $assoc );
		if ( 'table' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::success( sprintf( 'Rebuilt %d indexes on %s.', count( $rows ), $branch ) );
		}
	}

	// ---------------------------------------------------------------- 共通

	/**
	 * @template T
	 * @param callable(Client):T $fn
	 * @return T
	 */
	public static function run( callable $fn ) {
		try {
			return $fn( Client::instance() );
		} catch ( Error $e ) {
			$hint = Client::is_denied( $e ) ? ' Only an administrator can do this: run it on the production site, or set HEIMDB_ADMIN_USER in wp-config.php.' : '';
			WP_CLI::error( $e->getMessage() . ( $e->getCode() ? ' [' . $e->getCode() . ']' : '' ) . $hint );
		}
	}

	public static function time( string $input ): string {
		try {
			return Time::to_db( $input );
		} catch ( Error $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	public static function output( array $rows, array $assoc ): void {
		$format = $assoc['format'] ?? 'table';
		if ( ! $rows ) {
			if ( 'table' === $format ) {
				WP_CLI::log( 'No rows.' );
			} else {
				Utils\format_items( $format, array(), array( 'none' ) );
				if ( 'json' === $format ) {
					echo "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			return;
		}
		$fields = isset( $assoc['fields'] ) ? explode( ',', $assoc['fields'] ) : array_keys( $rows[0] );
		Utils\format_items( $format, $rows, $fields );
		// json は改行で終わらないので、続く Success: などが同じ行に付かないようにする。
		if ( 'json' === $format ) {
			echo "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function restore_command( array $undo ): string {
		return 'wp heimdb restore ' . escapeshellarg( $undo['branch'] ) . ' ' . escapeshellarg( $undo['time'] )
			. ( $undo['tables'] ? ' --tables=' . escapeshellarg( $undo['tables'] ) : '' );
	}
}

/**
 * Manages HeimDB branches.
 *
 * ## EXAMPLES
 *
 *     wp heimdb branch list
 *     wp heimdb branch create staging
 *     wp heimdb branch create test --parent=main --at="2026-10-18 09:00"
 *     wp heimdb branch grant staging staging --max-connections=20
 *     wp heimdb branch delete test --yes
 */
class CLI_Branch {
	/**
	 * Lists the branches.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : The columns to show.
	 *
	 * [--format=<format>]
	 * : The output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array_values( CLI::run( fn( Client $c ) => $c->branches() ) );
		if ( 'ids' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::log( implode( ' ', array_column( $rows, 'branch' ) ) );
			return;
		}
		foreach ( $rows as &$row ) {
			$row['forked_at'] = Time::display( $row['forked_at'] );
		}
		unset( $row );
		CLI::output( $rows, $assoc );
	}

	/**
	 * Creates a branch. No data is copied, so it is instant.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name (lowercase letters, digits, `_`, `-`). It appears as the database `<production database>@<name>`.
	 *
	 * [--parent=<parent>]
	 * : The parent.
	 * ---
	 * default: main
	 * ---
	 *
	 * [--at=<time>]
	 * : Create from the parent as of this time (within the retention period).
	 *
	 * [--max-size=<size>]
	 * : The size limit (`2G`, ...).
	 *
	 * [--max-time=<time>]
	 * : The time limit of one statement (`30s`, ...).
	 *
	 * [--porcelain]
	 * : Print only the database name.
	 */
	public function create( $args, $assoc ) {
		list( $name ) = $args;
		$at           = isset( $assoc['at'] ) ? CLI::time( $assoc['at'] ) : null;
		$row          = CLI::run( fn( Client $c ) => $c->branch_create( $name, $assoc['parent'] ?? 'main', $at ) );
		if ( isset( $assoc['max-size'] ) || isset( $assoc['max-time'] ) ) {
			CLI::run( fn( Client $c ) => $c->branch_limit( $name, $assoc['max-size'] ?? '0', $assoc['max-time'] ?? '0' ) );
		}
		if ( Utils\get_flag_value( $assoc, 'porcelain', false ) ) {
			WP_CLI::log( $row['database'] );
			return;
		}
		WP_CLI::success( sprintf( 'Created branch %s (database %s, forked from %s at %s).', $row['branch'], $row['database'], $row['parent'], Time::display( $row['forked_at'], true ) ) );
	}

	/**
	 * Deletes a branch. Refused if it has child branches. Its recorded resolutions are deleted too.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The branch.
	 *
	 * [--yes]
	 * : Delete without asking.
	 */
	public function delete( $args, $assoc ) {
		list( $name ) = $args;
		WP_CLI::confirm( "Delete branch $name?", $assoc );
		CLI::run( fn( Client $c ) => $c->branch_delete( $name ) );
		WP_CLI::success( "Deleted branch $name." );
	}

	/**
	 * Sets limits on a branch's size and statement time (`0` removes one).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The branch.
	 *
	 * <size>
	 * : The size (`2G`, `500M`, bytes, `0`).
	 *
	 * <time>
	 * : The statement time (`30s`, `500ms`, `2m`, `0`).
	 */
	public function limit( $args ) {
		list( $name, $size, $time ) = $args;
		$row                        = CLI::run( fn( Client $c ) => $c->branch_limit( $name, $size, $time ) );
		WP_CLI::success( sprintf( 'Limits of %s: size %s, statement time %s.', $name, $row['max_size_bytes'] ?? 'none', null === $row['max_execution_ms'] ? 'none' : $row['max_execution_ms'] . 'ms' ) );
	}

	/**
	 * Creates a branch-only user (the user a staging site connects as).
	 *
	 * Creates the user if it does not exist, and grants all privileges on the branch database. If the site is taken
	 * over, production stays out of reach.
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : The branch.
	 *
	 * <user>
	 * : The user name.
	 *
	 * [--password=<password>]
	 * : The password. Generated and printed if omitted (an existing user keeps its password). This site's DB_USER and the administrator are refused.
	 *
	 * [--host=<host>]
	 * : The host the user connects from.
	 * ---
	 * default: %
	 * ---
	 *
	 * [--max-connections=<n>]
	 * : The limit of concurrent connections (0 for none). If omitted, 20 for a new user; an existing user is unchanged.
	 */
	public function grant( $args, $assoc ) {
		list( $branch, $user ) = $args;
		$host                  = $assoc['host'];
		$max                   = isset( $assoc['max-connections'] ) ? (int) $assoc['max-connections'] : null;
		$password              = $assoc['password'] ?? null;
		$result                = CLI::run( fn( Client $c ) => $c->grant_branch_user( $branch, $user, $host, $password, $max ) );
		WP_CLI::success( ( $result['created'] ? "Created $user@$host and granted" : "Granted the existing user $user@$host" ) . " all on the database of branch $branch." );
		if ( null !== $result['password'] ) {
			WP_CLI::log( 'Password: ' . $result['password'] );
		}
	}
}

/**
 * Manages the patterns of tables excluded from merges (such as rebuildable indexes).
 *
 * ## EXAMPLES
 *
 *     wp heimdb exclude add 'wp_yoast_%'
 *     wp heimdb exclude list
 *     wp heimdb exclude remove 'wp_yoast_%'
 */
class CLI_Exclude {
	/**
	 * Lists the patterns.
	 *
	 * @subcommand list
	 */
	public function list_() {
		foreach ( CLI::run( fn( Client $c ) => $c->merge_excludes() ) as $pattern ) {
			WP_CLI::log( $pattern );
		}
	}

	/**
	 * Adds a pattern (`%` and `_` as in `LIKE`). Patterns that match WordPress core tables are refused.
	 *
	 * ## OPTIONS
	 *
	 * <pattern>
	 * : The table name pattern.
	 */
	public function add( $args ) {
		CLI::run( fn( Client $c ) => $c->merge_exclude( $args[0] ) );
		WP_CLI::success( "Excluded {$args[0]} from merges." );
	}

	/**
	 * Removes a pattern (the tables are merged again).
	 *
	 * ## OPTIONS
	 *
	 * <pattern>
	 * : The table name pattern.
	 */
	public function remove( $args ) {
		CLI::run( fn( Client $c ) => $c->merge_include( $args[0] ) );
		WP_CLI::success( "Removed {$args[0]} from the exclusions." );
	}
}
