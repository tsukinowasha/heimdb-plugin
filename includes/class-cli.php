<?php
namespace Heimdb;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * HeimDB のブランチ、差分、マージ、時間旅行と復元を操作する。
 *
 * 手続きは wp-config.php の HEIMDB_ADMIN_USER（なければ DB_USER）で呼ぶ。時刻は、オフセットがなければサイトの
 * タイムゾーンで読む。`2 hours ago` のような書き方もできる。
 *
 * ステージングのサイトでブランチだけの権限のユーザーでつなぐなら、HeimDB 0.2.15 の -branch-procedures で、
 * そのブランチの diff、行を指定した resolve、changes、row、restore が使える（マージやブランチの管理は本番で）。
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
	 * 親との差分を見る。
	 *
	 * テーブルを省くとテーブルごとの要約、テーブル（`*` ならすべて）を渡すと行ごとの判定を出す。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ。
	 *
	 * [<table>]
	 * : テーブル。`*` ならすべてのテーブルの行。
	 *
	 * [--conflicts]
	 * : 行ごとの判定のうち、競合（と解決を記録したもの）だけを出す。<table> を省けば `*`。
	 *
	 * [--all]
	 * : 要約で、マージで変えるもののない `unchanged` のテーブルも出す。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * 競合をどちらの側で解決するかを記録する。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ。
	 *
	 * <table>
	 * : テーブル（`*` ならすべて）。
	 *
	 * <row>
	 * : 行（差分の id の形。`ID=1`、`option_name=blogname`）。`*` ならそのテーブルのすべての競合。
	 *
	 * <side>
	 * : 採る側。
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
		// ワイルドカードの 'branch' は、親の側でしか解決できない競合（HeimDB 0.2.13 から）には当たらない。
		if ( 'branch' === $side && '*' === $row ) {
			$left = $this->run( fn( Client $c ) => $c->parent_only_conflicts( $branch, $table ) );
			if ( $left ) {
				WP_CLI::warning( sprintf( '%d conflict(s) can keep only the parent value and are still unresolved: %s', count( $left ), implode( ', ', array_map( fn( $r ) => $r['table_name'] . ' ' . $r['id'], array_slice( $left, 0, 5 ) ) ) . ( count( $left ) > 5 ? ', ...' : '' ) ) );
			}
		}
	}

	/**
	 * ブランチを親にマージする。
	 *
	 * 解決していない競合があれば、何も書かずに断る（`wp heimdb diff <branch> --conflicts` で見て、
	 * `wp heimdb resolve` で解決する）。成功すれば、マージを戻すコマンドを出す。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ。
	 *
	 * [--dry-run]
	 * : 実際のマージと同じ経路を通り、書かずに捨てる。
	 *
	 * [--yes]
	 * : 確かめずにマージする。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * 親の変更をブランチに取り込む（HeimDB 0.2.33 から）。
	 *
	 * ほかのブランチを本番にマージした後などに、検証中のブランチへ本番の最新を入れる。ブランチのまだマージしていない
	 * 変更は残る。コメントとユーザーも写し、環境ごとのオプション（siteurl、home など）とマージから外した表は写さない。
	 * 両側で同じ列を変えた競合があれば、--side を渡さないと何も書かずに断る。ブランチで記録した解決は消える。
	 * 成功すれば、取り込みを戻すコマンドを出す。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ。
	 *
	 * [--side=<side>]
	 * : 競合した列で採る側。すべての競合に効く。両側が同じ ID で別の行を足した競合は parent でしか決められない。
	 * ---
	 * options:
	 *   - branch
	 *   - parent
	 * ---
	 *
	 * [--dry-run]
	 * : 書かずに、取り込む件数と競合を確かめる。
	 *
	 * [--yes]
	 * : 確かめずに取り込む。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * ある時刻から今までに変わった行を見る。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ（本番は main）。
	 *
	 * <time>
	 * : 時刻（`2026-10-18 09:00`、`1 hour ago` など）。オフセットがなければサイトのタイムゾーン。
	 *
	 * [--tables=<tables>]
	 * : テーブル（カンマ区切り）。省けばすべて。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * ブランチを過去の時点に戻す。
	 *
	 * テーブルを省くと、コメントとユーザー（wp_comments、wp_commentmeta、wp_users、wp_usermeta）は今のまま残す。
	 * 復元は取り消せる（結果に戻すコマンドを出す）。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ（本番は main）。
	 *
	 * <time>
	 * : 時刻。オフセットがなければサイトのタイムゾーン。
	 *
	 * [--tables=<tables>]
	 * : 戻すテーブル（カンマ区切り）か `*`（すべて）。
	 *
	 * [--preview]
	 * : 戻さずに、何が変わるかを見る。
	 *
	 * [--yes]
	 * : 確かめずに戻す。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * テーブルの 1 行を、ある時点で読む（時間旅行）。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ（本番は main）。
	 *
	 * <table>
	 * : テーブル。
	 *
	 * <row>
	 * : 行（差分や変更の一覧の id の形。`ID=12`、`option_name=blogname`）。
	 *
	 * [--at=<time>]
	 * : 時刻。省けば今。
	 *
	 * [--compare]
	 * : その時点の値と今の値を並べ、違う列に印を付ける。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * HeimDB の状態を見る。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * 動いたままバックアップを取る（HeimDB の -backup-dir の下）。
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : 名前（英数字、`.`、`_`、`-`）。省けば時刻。
	 */
	public function backup( $args ) {
		$row = $this->run( fn( Client $c ) => $c->backup( $args[0] ?? null ) );
		WP_CLI::success( sprintf( 'Backed up to %s (%s bytes, at %s).', $row['path'], $row['bytes'], Time::display( $row['backed_up_at'], true ) ) );
	}

	/**
	 * 保持期間より古い版を今すぐ消す。
	 *
	 * 境界より前の時点は、時間旅行、復元、ブランチの作成に使えなくなる。
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : 確かめずに消す。
	 */
	public function gc( $args, $assoc ) {
		WP_CLI::confirm( 'Remove versions older than the retention period now?', $assoc );
		$row = $this->run( fn( Client $c ) => $c->gc() );
		WP_CLI::success( sprintf( 'Boundary %s; removed %s versions.', Time::display( $row['boundary'], true ), $row['versions'] ) );
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
 * HeimDB のブランチを操作する。
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
	 * ブランチの一覧。
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : 出す列。
	 *
	 * [--format=<format>]
	 * : 出力の形。
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
	 * ブランチを作る。データは複製しない（一瞬で終わる）。
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : 名前（英小文字、数字、`_`、`-`）。データベース `<本番のデータベース>@<名前>` に見える。
	 *
	 * [--parent=<parent>]
	 * : 親。
	 * ---
	 * default: main
	 * ---
	 *
	 * [--at=<time>]
	 * : 親のこの時点から作る（保持期間の中だけ）。
	 *
	 * [--max-size=<size>]
	 * : 大きさの上限（`2G` など）。
	 *
	 * [--max-time=<time>]
	 * : 1 つの文の実行時間の上限（`30s` など）。
	 *
	 * [--porcelain]
	 * : データベースの名前だけを出す。
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
	 * ブランチを消す。子のブランチがあれば断る。解決の記録も消える。
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : ブランチ。
	 *
	 * [--yes]
	 * : 確かめずに消す。
	 */
	public function delete( $args, $assoc ) {
		list( $name ) = $args;
		WP_CLI::confirm( "Delete branch $name?", $assoc );
		CLI::run( fn( Client $c ) => $c->branch_delete( $name ) );
		WP_CLI::success( "Deleted branch $name." );
	}

	/**
	 * ブランチの大きさと文の実行時間に上限を付ける（`0` で外す）。
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : ブランチ。
	 *
	 * <size>
	 * : 大きさ（`2G`、`500M`、バイト数、`0`）。
	 *
	 * <time>
	 * : 実行時間（`30s`、`500ms`、`2m`、`0`）。
	 */
	public function limit( $args ) {
		list( $name, $size, $time ) = $args;
		$row                        = CLI::run( fn( Client $c ) => $c->branch_limit( $name, $size, $time ) );
		WP_CLI::success( sprintf( 'Limits of %s: size %s, statement time %s.', $name, $row['max_size_bytes'] ?? 'none', null === $row['max_execution_ms'] ? 'none' : $row['max_execution_ms'] . 'ms' ) );
	}

	/**
	 * ブランチだけの権限のユーザー（ステージングのサイトがつなぐユーザー）を作る。
	 *
	 * ユーザーがいなければ作り、ブランチのデータベースのすべての権限を与える。サイトが乗っ取られても本番に届かない。
	 *
	 * ## OPTIONS
	 *
	 * <branch>
	 * : ブランチ。
	 *
	 * <user>
	 * : ユーザーの名前。
	 *
	 * [--password=<password>]
	 * : パスワード。省けば作って出す（ユーザーがいれば変えない）。このサイトの DB_USER と管理者のユーザーは断る。
	 *
	 * [--host=<host>]
	 * : つなぐ元。
	 * ---
	 * default: %
	 * ---
	 *
	 * [--max-connections=<n>]
	 * : 同時接続の上限（0 なら上限なし）。省けば、作るときは 20、いるユーザーは変えない。
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
 * マージから外す表（作り直せる索引など）のパターン。
 *
 * ## EXAMPLES
 *
 *     wp heimdb exclude add 'wp_yoast_%'
 *     wp heimdb exclude list
 *     wp heimdb exclude remove 'wp_yoast_%'
 */
class CLI_Exclude {
	/**
	 * パターンの一覧。
	 *
	 * @subcommand list
	 */
	public function list_() {
		foreach ( CLI::run( fn( Client $c ) => $c->merge_excludes() ) as $pattern ) {
			WP_CLI::log( $pattern );
		}
	}

	/**
	 * パターンを足す（`LIKE` と同じく `%` と `_`）。WordPress 本体の表に当たるパターンは断られる。
	 *
	 * ## OPTIONS
	 *
	 * <pattern>
	 * : 表の名前のパターン。
	 */
	public function add( $args ) {
		CLI::run( fn( Client $c ) => $c->merge_exclude( $args[0] ) );
		WP_CLI::success( "Excluded {$args[0]} from merges." );
	}

	/**
	 * パターンを消す（マージに戻す）。
	 *
	 * ## OPTIONS
	 *
	 * <pattern>
	 * : 表の名前のパターン。
	 */
	public function remove( $args ) {
		CLI::run( fn( Client $c ) => $c->merge_include( $args[0] ) );
		WP_CLI::success( "Removed {$args[0]} from the exclusions." );
	}
}
