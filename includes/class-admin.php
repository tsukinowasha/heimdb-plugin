<?php
namespace Heimdb;

defined( 'ABSPATH' ) || exit;

/**
 * 管理画面（HeimDB のメニュー）。
 *
 * - ブランチ: 一覧と作成、差分（テーブルごと、行ごと、行の両側の比較）、競合の解決、マージ
 * - タイムトラベル: ある時刻からの変更、行のその時点の値、復元（とその下見）、過去の時点からのブランチ
 * - 状態: 接続、操作の記録と取り消し、マージから外す表、バックアップ、GC、heimdb_status
 *
 * 書き換える操作は、すべて admin-post.php への POST（nonce と権限を確かめる）で行い、結果を一時的に覚えて元の画面に
 * 戻す。
 *
 * ステージングのサイトがブランチだけの権限のユーザーでつなぐとき（Client::is_branch_user）は、そのブランチの差分、
 * 行ごとの解決、タイムトラベルと復元だけを出す（マージ、ブランチの管理、運用は本番のサイトから）。
 */
class Admin {
	const PAGE_BRANCHES = 'heimdb';
	const PAGE_TIME     = 'heimdb-time';
	const PAGE_STATUS   = 'heimdb-status';
	const ACTION        = 'heimdb';
	const ROWS_PER_PAGE = 200;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		$cap = heimdb_capability();
		add_menu_page( 'HeimDB', 'HeimDB', $cap, self::PAGE_BRANCHES, array( __CLASS__, 'page_branches' ), 'dashicons-randomize', 80 );
		add_submenu_page( self::PAGE_BRANCHES, __( 'Branches', 'heimdb' ), __( 'Branches', 'heimdb' ), $cap, self::PAGE_BRANCHES, array( __CLASS__, 'page_branches' ) );
		add_submenu_page( self::PAGE_BRANCHES, __( 'Time travel', 'heimdb' ), __( 'Time travel', 'heimdb' ), $cap, self::PAGE_TIME, array( __CLASS__, 'page_time' ) );
		add_submenu_page( self::PAGE_BRANCHES, __( 'Status', 'heimdb' ), __( 'Status', 'heimdb' ), $cap, self::PAGE_STATUS, array( __CLASS__, 'page_status' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'heimdb' ) ) {
			return;
		}
		// バージョンにファイルの更新時刻を足して、直した CSS と JS がキャッシュで隠れないようにする。
		$ver = fn( string $file ) => HEIMDB_PLUGIN_VERSION . '.' . (int) @filemtime( HEIMDB_PLUGIN_DIR . '/' . $file );
		wp_enqueue_style( 'heimdb-admin', plugins_url( 'assets/admin.css', HEIMDB_PLUGIN_FILE ), array(), $ver( 'assets/admin.css' ) );
		wp_enqueue_script( 'heimdb-admin', plugins_url( 'assets/admin.js', HEIMDB_PLUGIN_FILE ), array(), $ver( 'assets/admin.js' ), true );
	}

	// ================================================================ 操作（POST）

	public static function handle(): void {
		if ( ! current_user_can( heimdb_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to operate HeimDB.', 'heimdb' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		check_admin_referer( 'heimdb_' . $op );

		$p        = wp_unslash( $_POST );
		$redirect = isset( $p['redirect_to'] ) ? (string) $p['redirect_to'] : admin_url( 'admin.php?page=' . self::PAGE_BRANCHES );
		$client   = Client::instance();
		$notice   = array();
		$ttl      = 5 * MINUTE_IN_SECONDS;

		try {
			switch ( $op ) {
				case 'branch_create':
					$name = trim( (string) $p['name'] );
					if ( ! Client::is_valid_branch_name( $name ) ) {
						throw new Error( __( 'A branch name may contain only lowercase letters, digits, "_" and "-".', 'heimdb' ) );
					}
					$at  = '' !== trim( (string) ( $p['at'] ?? '' ) ) ? Time::to_db( (string) $p['at'] ) : null;
					$row      = $client->branch_create( $name, (string) ( $p['parent'] ?? 'main' ), $at );
					$redirect = self::url( self::PAGE_BRANCHES, array( 'branch' => $name ) );
					/* translators: 1: branch, 2: database name */
					$message = sprintf( __( 'Created branch %1$s. Point a WordPress site at the database %2$s to work on it.', 'heimdb' ), $name, $row['database'] );
					if ( '' !== trim( (string) ( $p['max_size'] ?? '' ) ) || '' !== trim( (string) ( $p['max_time'] ?? '' ) ) ) {
						try {
							$client->branch_limit( $name, self::or_zero( $p['max_size'] ?? '' ), self::or_zero( $p['max_time'] ?? '' ) );
						} catch ( Error $e ) {
							$notice = array(
								'type'    => 'warning',
								/* translators: %s: error message */
								'message' => $message . ' ' . sprintf( __( 'But the limits were not set: %s', 'heimdb' ), self::error_message( $e ) ),
							);
							break;
						}
					}
					$notice = self::success( $message );
					break;

				case 'branch_delete':
					$name = (string) $p['branch'];
					$client->branch_delete( $name );
					/* translators: %s: branch */
					$notice   = self::success( sprintf( __( 'Deleted branch %s.', 'heimdb' ), $name ) );
					$redirect = self::url( self::PAGE_BRANCHES );
					break;

				case 'branch_limit':
					$name = (string) $p['branch'];
					$client->branch_limit( $name, self::or_zero( $p['max_size'] ?? '' ), self::or_zero( $p['max_time'] ?? '' ) );
					/* translators: %s: branch */
					$notice = self::success( sprintf( __( 'Updated the limits of %s.', 'heimdb' ), $name ) );
					break;

				case 'branch_grant':
					$name      = (string) $p['branch'];
					$user      = trim( (string) $p['user'] );
					$max       = trim( (string) ( $p['max_connections'] ?? '' ) );
					$result    = $client->grant_branch_user( $name, $user, '%', (string) ( $p['password'] ?? '' ), '' === $max ? null : (int) $max );
					$message   = $result['created']
						/* translators: 1: database user, 2: branch */
						? sprintf( __( 'Created the database user %1$s. It can use branch %2$s (and nothing else).', 'heimdb' ), $user, $name )
						/* translators: 1: database user, 2: branch */
						: sprintf( __( 'The existing database user %1$s can now also use branch %2$s. Its password and max connections were changed only if you entered them.', 'heimdb' ), $user, $name );
					if ( null !== $result['password'] ) {
						/* translators: %s: generated password */
						$message .= ' ' . sprintf( __( 'Password: %s (shown only once)', 'heimdb' ), $result['password'] );
						$ttl      = MINUTE_IN_SECONDS; // パスワードを含む通知は、表示されなくても長く残さない。
					}
					$notice = self::success( $message );
					break;

				case 'resolve':
					$name = (string) $p['branch'];
					$client->branch_resolve( $name, (string) $p['table'], (string) $p['row'], (string) $p['side'] );
					/* translators: 1: table, 2: row id, 3: side */
					$message = sprintf( __( 'Recorded the resolution: %1$s %2$s → %3$s.', 'heimdb' ), $p['table'], $p['row'], self::side_label( (string) $p['side'] ) );
					$left    = 'branch' === $p['side'] && '*' === $p['row'] ? count( $client->parent_only_conflicts( $name, (string) $p['table'] ) ) : 0;
					if ( $left ) {
						/* translators: %d: number of conflicts */
						$message .= ' ' . sprintf( _n( '%d conflict can keep only the parent value and is still unresolved. Resolve it to the parent.', '%d conflicts can keep only the parent value and are still unresolved. Resolve them to the parent.', $left, 'heimdb' ), $left );
					}
					$notice = $left ? array( 'type' => 'warning', 'message' => $message ) : self::success( $message );
					break;

				case 'merge':
					$name    = (string) $p['branch'];
					$dry_run = ! empty( $p['dry_run'] );
					$rows    = $client->branch_merge( $name, $dry_run );
					$undo    = Client::undo_args( $rows );
					$tables  = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
					if ( $dry_run ) {
						$notice = self::success( __( 'Dry run: the merge would succeed. Nothing was written.', 'heimdb' ), $tables );
						break;
					}
					$parent = $client->branch( $name )['parent'];
					$notice = self::success(
						/* translators: 1: branch, 2: parent branch */
						$tables ? sprintf( __( 'Merged %1$s into %2$s.', 'heimdb' ), $name, $parent ) : __( 'Nothing to merge.', 'heimdb' ),
						$tables,
						$tables ? $undo : null
					);
					break;

				case 'sync':
					$name    = (string) $p['branch'];
					$side    = (string) ( $p['side'] ?? '' );
					$dry_run = ! empty( $p['dry_run'] );
					$rows    = $client->branch_sync( $name, '' === $side ? null : $side, $dry_run );
					$tables  = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
					$synced  = array_filter( $tables, fn( $r ) => 'excluded' !== $r['status'] && (int) $r['inserts'] + (int) $r['updates'] + (int) $r['deletes'] + (int) $r['resolved'] > 0 || 'new table' === $r['status'] );
					if ( $dry_run ) {
						$notice = self::success( $synced ? __( 'Dry run: the sync would succeed. Nothing was written.', 'heimdb' ) : __( 'Dry run: nothing to pull in from the parent.', 'heimdb' ), $tables );
						break;
					}
					$parent = $client->branch( $name )['parent'];
					$undo   = Client::undo_args( $rows );
					$notice = self::success(
						/* translators: 1: parent branch, 2: branch */
						$undo ? sprintf( __( 'Pulled the changes of %1$s into %2$s.', 'heimdb' ), $parent, $name ) : __( 'Nothing to pull in from the parent.', 'heimdb' ),
						$tables,
						$undo
					);
					break;

				case 'restore':
					$branch = (string) $p['branch'];
					$at     = Time::to_db( (string) $p['at'] );
					$tables = self::restore_tables( $p );
					$rows   = $client->restore( $branch, $at, $tables );
					$undo   = Client::undo_args( $rows );
					$shown  = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
					/* translators: 1: branch, 2: time */
					$message = sprintf( __( 'Restored %1$s to %2$s.', 'heimdb' ), $branch, Time::display( $at, true ) );
					$pair    = Client::restore_suggestion( $rows );
					if ( $pair ) {
						/* translators: %s: tables */
						$message .= ' ' . sprintf( __( 'Some rows also changed in a paired table that was not restored (see Detail). To restore them together, restore %s.', 'heimdb' ), str_replace( ',', ', ', $pair['tables'] ) );
					}
					$notice = self::success( $message, $shown, $undo );
					break;

				case 'undo':
					// 戻すための引数は、操作の結果の通知のボタンが送る（HeimDB の結果の最後の行の heimdb_restore の引数）。
					$u = array(
						'branch' => (string) ( $p['branch'] ?? '' ),
						'time'   => (string) ( $p['time'] ?? '' ),
						'tables' => '' !== (string) ( $p['tables'] ?? '' ) ? (string) $p['tables'] : null,
					);
					if ( '' === $u['branch'] || '' === $u['time'] ) {
						throw new Error( __( 'That operation cannot be undone from here.', 'heimdb' ) );
					}
					$rows  = $client->restore( $u['branch'], $u['time'], $u['tables'] );
					$shown = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
					$notice = self::success(
						/* translators: 1: branch, 2: time */
						sprintf( __( 'Undone: %1$s is back to %2$s.', 'heimdb' ), $u['branch'], Time::display( $u['time'], true ) ),
						$shown,
						Client::undo_args( $rows )
					);
					break;

				case 'exclude_add':
					$pattern = trim( (string) $p['pattern'] );
					$client->merge_exclude( $pattern );
					/* translators: %s: table name pattern */
					$notice = self::success( sprintf( __( 'Tables matching %s are now excluded from merges.', 'heimdb' ), $pattern ) );
					break;

				case 'exclude_remove':
					$pattern = (string) $p['pattern'];
					$client->merge_include( $pattern );
					/* translators: %s: table name pattern */
					$notice = self::success( sprintf( __( 'Tables matching %s are merged again.', 'heimdb' ), $pattern ) );
					break;

				case 'backup':
					$row = $client->backup( (string) ( $p['name'] ?? '' ) );
					/* translators: 1: path, 2: size */
					$notice = self::success( sprintf( __( 'Backed up to %1$s (%2$s).', 'heimdb' ), $row['path'], size_format( (int) $row['bytes'] ) ) );
					break;

				case 'gc':
					$row = $client->gc();
					/* translators: 1: time, 2: number */
					$notice = self::success( sprintf( __( 'GC done. Data before %1$s can no longer be read; removed %2$s versions.', 'heimdb' ), Time::display( $row['boundary'] ), number_format_i18n( (int) $row['versions'] ) ) );
					break;

				case 'rebuild_indexes':
					$branch = (string) ( $p['branch'] ?? Client::site_branch() );
					$rows   = $client->rebuild_indexes( $branch );
					$notice = $rows
						/* translators: 1: number, 2: branch */
						? self::success( sprintf( _n( 'Rebuilt %1$s index on %2$s.', 'Rebuilt %1$s indexes on %2$s.', count( $rows ), 'heimdb' ), number_format_i18n( count( $rows ) ), $branch ), $rows )
						/* translators: %s: branch */
						: self::success( sprintf( __( '%s has no indexes to rebuild.', 'heimdb' ), $branch ) );
					break;

				default:
					throw new Error( 'unknown operation' );
			}
		} catch ( Error $e ) {
			$notice = array(
				'type'    => 'error',
				'message' => self::error_message( $e ),
			);
		}

		set_transient( self::notice_key(), $notice, $ttl );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @param array<string,string|null>|null $undo 取り消しのボタンが送る heimdb_restore の引数（Client::undo_args）。
	 */
	private static function success( string $message, array $rows = array(), ?array $undo = null ): array {
		return array(
			'type'    => 'success',
			'message' => $message,
			'rows'    => $rows,
			'undo'    => $undo,
		);
	}

	private static function notice_key(): string {
		return 'heimdb_notice_' . get_current_user_id();
	}

	private static function or_zero( string $value ): string {
		$value = trim( $value );
		return '' === $value ? '0' : $value;
	}

	/**
	 * 復元するテーブル: 省く（コメントとユーザー以外）、`*`（すべて）、選んだもの。
	 */
	private static function restore_tables( array $p ): ?string {
		$scope = (string) ( $p['scope'] ?? 'default' );
		if ( 'all' === $scope ) {
			return '*';
		}
		if ( 'tables' === $scope ) {
			$tables = array_filter( array_map( 'trim', (array) ( $p['tables'] ?? array() ) ) );
			if ( ! $tables ) {
				throw new Error( __( 'Choose the tables to restore.', 'heimdb' ) );
			}
			return implode( ',', $tables );
		}
		return null;
	}

	/**
	 * HeimDB のエラーを、何をすればよいかの分かる文にする（語ごと）。
	 */
	private static function error_message( Error $e ): string {
		$hints = array(
			'unresolved_conflicts' => __( 'Resolve the conflicts below first.', 'heimdb' ),
			'db_version_differs'   => __( 'Run the WordPress database upgrade on both sides before merging.', 'heimdb' ),
			'ambiguous_prefix'     => __( 'Tables with two WordPress table prefixes were found. Drop or rename the unused copy (e.g. a WP Staging copy).', 'heimdb' ),
			'in_transaction'       => __( 'Finish the open transaction first.', 'heimdb' ),
			'parent_changed'       => __( 'The parent changed during the merge. Try again.', 'heimdb' ),
			'read_only_replica'    => __( 'This HeimDB is a read-only replica. Connect to the primary.', 'heimdb' ),
			'core_table'           => __( 'WordPress core tables cannot be excluded from merges.', 'heimdb' ),
		);
		$message = $e->getMessage();
		// 取り込み（heimdb_branch_sync）を拒否するときは、同じ語でも直し方が違う。
		$sync    = 0 === strpos( $message, 'HeimDB: sync refused' );
		if ( $sync ) {
			$hints = array_merge(
				$hints,
				array(
					'unresolved_conflicts' => __( 'Both sides changed the same rows. Choose which side the conflicts keep and pull in again, or make those rows on the branch equal to the parent.', 'heimdb' ),
					'db_version_differs'   => __( 'Run the WordPress database upgrade on both sides before pulling in.', 'heimdb' ),
					'schema_differs'       => __( 'A table definition differs between the branch and the parent. Run the same plugin update (or ALTER TABLE) on the branch first.', 'heimdb' ),
				)
			);
		}
		if ( false !== strpos( $message, 'stored procedure "heimdb_rebuild_indexes" does not exist' ) ) {
			$message .= ' — ' . __( 'Rebuilding indexes needs HeimDB 0.2.39 or later.', 'heimdb' );
		} elseif ( false !== strpos( $message, 'a branch user cannot use a time before the branch was created' ) ) {
			$message .= ' — ' . __( 'From a branch site, you can go back only to when the branch was created.', 'heimdb' );
		} elseif ( false !== strpos( $message, 'a branch user must name a row' ) ) {
			$message .= ' — ' . __( 'From a branch site, resolve the conflicts one row at a time.', 'heimdb' );
		} elseif ( $sync && null !== Client::parent_only( $message ) ) {
			$message .= ' — ' . __( 'Rows added on both sides with the same ID can only take the parent. Pull in with the parent kept for conflicts.', 'heimdb' );
		} elseif ( null !== Client::parent_only( $message ) ) {
			$message .= ' — ' . __( 'Some conflicts can keep only the parent value. Resolve them to the parent.', 'heimdb' );
		} elseif ( $e->reason && isset( $hints[ $e->reason ] ) ) {
			$message .= ' — ' . $hints[ $e->reason ];
		} elseif ( 1227 === $e->getCode() || 1044 === $e->getCode() || 1045 === $e->getCode() || false !== stripos( $message, 'command denied' ) || false !== stripos( $message, 'access denied' ) ) {
			$message .= ' — ' . __( 'HeimDB procedures need an administrator. Set HEIMDB_ADMIN_USER and HEIMDB_ADMIN_PASSWORD in wp-config.php.', 'heimdb' );
		}
		return $message;
	}

	// ================================================================ 画面: ブランチ

	public static function page_branches(): void {
		$branch = isset( $_GET['branch'] ) ? sanitize_text_field( wp_unslash( $_GET['branch'] ) ) : '';
		$table  = isset( $_GET['table'] ) ? sanitize_text_field( wp_unslash( $_GET['table'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? wp_unslash( (string) $_GET['id'] ) : '';

		echo '<div class="wrap heimdb">';
		if ( ! self::header_and_check() ) {
			echo '</div>';
			return;
		}
		try {
			if ( '' === $branch && Client::instance()->is_branch_user() ) {
				$branch = Client::site_branch(); // ブランチのユーザーは一覧を読めない
			}
			if ( '' === $branch ) {
				self::view_branch_list();
			} elseif ( '' !== $id ) {
				self::view_compare( $branch, $table, $id );
			} elseif ( '' !== $table ) {
				self::view_row_diff( $branch, $table );
			} else {
				self::view_branch( $branch );
			}
		} catch ( Error $e ) {
			self::flash();
			self::notice( 'error', self::error_message( $e ) );
		}
		echo '</div>';
	}

	private static function view_branch_list(): void {
		$client   = Client::instance();
		$branches = $client->branches();
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Branches', 'heimdb' ) . '</h1>';
		self::flash();
		?>
		<table class="widefat striped heimdb-table">
			<thead><tr>
				<th><?php esc_html_e( 'Branch', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Parent', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Forked at', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Database', 'heimdb' ); ?></th>
				<th class="num"><?php esc_html_e( 'Size', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Limits', 'heimdb' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $branches as $name => $b ) : ?>
				<tr>
					<td>
						<?php if ( null === $b['parent'] ) : ?>
							<strong><?php echo esc_html( $name ); ?></strong> <span class="heimdb-badge heimdb-badge-main"><?php esc_html_e( 'production', 'heimdb' ); ?></span>
						<?php else : ?>
							<strong><a href="<?php echo esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $name ) ) ); ?>"><?php echo esc_html( $name ); ?></a></strong>
						<?php endif; ?>
						<?php if ( Client::site_branch() === $name ) : ?>
							<span class="heimdb-badge"><?php esc_html_e( 'this site', 'heimdb' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $b['parent'] ); ?></td>
					<td><?php echo esc_html( Time::display( $b['forked_at'] ) ); ?></td>
					<td><code><?php echo esc_html( $b['database'] ); ?></code></td>
					<td class="num"><?php echo esc_html( size_format( (int) $b['size_bytes'] ) ?: '0 B' ); ?></td>
					<td><?php echo esc_html( self::limits_label( $b ) ); ?></td>
					<td class="heimdb-actions">
						<?php if ( null !== $b['parent'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $name ) ) ); ?>"><?php esc_html_e( 'Diff & merge', 'heimdb' ); ?></a>
						<?php endif; ?>
						<a class="button button-small" href="<?php echo esc_url( self::url( self::PAGE_TIME, array( 'branch' => $name ) ) ); ?>"><?php esc_html_e( 'Time travel', 'heimdb' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Create a branch', 'heimdb' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A branch is a writable copy of its parent, made instantly without copying data. It appears as the database <production>@<name>.', 'heimdb' ); ?></p>
		<?php self::form_open( 'branch_create' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="heimdb-name"><?php esc_html_e( 'Name', 'heimdb' ); ?></label></th>
					<td><input id="heimdb-name" name="name" class="regular-text" required pattern="[a-z0-9_\-]+" placeholder="staging">
						<p class="description"><?php esc_html_e( 'Lowercase letters, digits, "_" and "-".', 'heimdb' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="heimdb-parent"><?php esc_html_e( 'Parent', 'heimdb' ); ?></label></th>
					<td><?php self::branch_select( 'parent', 'main', 'heimdb-parent' ); ?></td>
				</tr>
				<tr>
					<th><label for="heimdb-at"><?php esc_html_e( 'From the point in time', 'heimdb' ); ?></label></th>
					<td><input id="heimdb-at" type="datetime-local" step="1" name="at">
						<p class="description"><?php esc_html_e( 'Leave empty to branch from now. A past time must be within the retention period.', 'heimdb' ); ?> (<?php echo esc_html( wp_timezone_string() ); ?>)</p></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Limits', 'heimdb' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Size', 'heimdb' ); ?> <input name="max_size" class="small-text" placeholder="2G"></label>
						<label><?php esc_html_e( 'Statement time', 'heimdb' ); ?> <input name="max_time" class="small-text" placeholder="30s"></label>
						<p class="description"><?php esc_html_e( 'Optional. Keeps a staging site from using up the disk and time of production.', 'heimdb' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create branch', 'heimdb' ) ); ?>
		</form>
		<?php
	}

	private static function view_branch( string $name ): void {
		$client = Client::instance();
		$b      = $client->branch( $name );
		$bu     = $client->is_branch_user();
		if ( null === $b['parent'] && ! $bu ) {
			echo '<h1>' . esc_html( $name ) . '</h1>';
			self::flash();
			/* translators: %s: branch */
			self::notice( 'info', sprintf( __( '%s has no parent to merge into.', 'heimdb' ), $name ) );
			echo '<p><a class="button" href="' . esc_url( self::url( self::PAGE_TIME, array( 'branch' => $name ) ) ) . '">' . esc_html__( 'Time travel', 'heimdb' ) . '</a></p>';
			return;
		}
		$show_all = ! empty( $_GET['all'] );
		$rows     = $client->branch_diff( $name );
		$status   = array_values( array_filter( $rows, fn( $r ) => '*' === $r['table_name'] ) );
		$tables   = array_values( array_filter( $rows, fn( $r ) => '*' !== $r['table_name'] ) );
		$changed  = array_values( array_filter( $tables, fn( $r ) => 'unchanged' !== $r['status'] ) );
		$conflicts = array_sum( array_map( fn( $r ) => (int) $r['conflicts'], $tables ) );
		$resolved  = array_sum( array_map( fn( $r ) => (int) $r['resolved'], $tables ) );
		$blocked   = $conflicts > 0 || (bool) $status;

		self::breadcrumbs( array( $name => null ) );
		/* translators: 1: branch, 2: parent */
		echo '<h1>' . esc_html( sprintf( __( '%1$s → %2$s', 'heimdb' ), $name, self::parent_label( $b ) ) ) . '</h1>';
		echo '<p class="heimdb-meta">';
		if ( $bu ) {
			/* translators: 1: database, 2: database user */
			echo esc_html( sprintf( __( 'Database %1$s · connected as the branch user %2$s', 'heimdb' ), $b['database'], Client::admin_user() ) );
		} else {
			/* translators: 1: time, 2: database, 3: size */
			echo esc_html( sprintf( __( 'Forked at %1$s · database %2$s · %3$s', 'heimdb' ), Time::display( $b['forked_at'] ), $b['database'], size_format( (int) $b['size_bytes'] ) ?: '0 B' ) );
		}
		echo '</p>';
		self::flash();

		foreach ( $status as $s ) {
			self::notice( 'error', $s['status'] . ': ' . $s['detail'] );
		}

		echo '<div class="heimdb-columns"><div class="heimdb-main">';
		echo '<h2>' . esc_html__( 'Changes to merge', 'heimdb' ) . '</h2>';
		if ( ! $changed ) {
			echo '<p>' . esc_html__( 'Nothing to merge: the branch has no changes against its parent.', 'heimdb' ) . '</p>';
		} else {
			self::summary_table( $name, $show_all ? $tables : $changed );
		}
		if ( count( $tables ) !== count( $changed ) ) {
			$toggle = $show_all
				? array( __( 'Hide unchanged tables', 'heimdb' ), self::url( self::PAGE_BRANCHES, array( 'branch' => $name ) ) )
				/* translators: %d: number of tables */
				: array( sprintf( __( 'Show %d unchanged tables', 'heimdb' ), count( $tables ) - count( $changed ) ), self::url( self::PAGE_BRANCHES, array( 'branch' => $name, 'all' => 1 ) ) );
			echo '<p><a href="' . esc_url( $toggle[1] ) . '">' . esc_html( $toggle[0] ) . '</a></p>';
		}
		if ( $changed ) {
			echo '<p><a class="button" href="' . esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $name, 'table' => '*' ) ) ) . '">' . esc_html__( 'Show every row', 'heimdb' ) . '</a></p>';
		}
		echo '</div><div class="heimdb-side">';

		// マージ
		echo '<div class="heimdb-box">';
		echo '<h2>' . esc_html__( 'Merge', 'heimdb' ) . '</h2>';
		if ( $conflicts ) {
			/* translators: %d: number of conflicts */
			echo '<p class="heimdb-warn">' . esc_html( sprintf( _n( '%d unresolved conflict. Decide which side to keep for each row before merging.', '%d unresolved conflicts. Decide which side to keep for each row before merging.', $conflicts, 'heimdb' ), $conflicts ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $name, 'table' => '*', 'action' => 'conflict' ) ) ) . '">' . esc_html__( 'Review conflicts', 'heimdb' ) . '</a></p>';
			if ( ! $bu ) { // ワイルドカードの解決は管理者だけ
				echo '<p class="heimdb-inline-forms">';
				self::resolve_button( $name, '*', '*', 'branch', __( 'Keep the branch for all', 'heimdb' ) );
				self::resolve_button( $name, '*', '*', 'parent', __( 'Keep the parent for all', 'heimdb' ) );
				echo '</p>';
			}
		} elseif ( $resolved ) {
			/* translators: %d: number of conflicts */
			echo '<p>' . esc_html( sprintf( _n( '%d conflict resolved.', '%d conflicts resolved.', $resolved, 'heimdb' ), $resolved ) ) . '</p>';
		}
		if ( $bu ) {
			echo '<p class="description">' . esc_html__( 'An administrator merges from the production site. The resolutions recorded here are used by that merge, with who recorded them and when. If a value changes after you record a resolution, decide again.', 'heimdb' ) . '</p>';
			echo '</div></div></div>';
			return;
		}
		if ( $changed ) {
			echo '<p class="description">' . esc_html__( 'The merge is a single commit; on failure nothing is written. Writes to the parent wait while it runs. Comments and users stay as they are on the parent.', 'heimdb' ) . '</p>';
			echo '<p class="heimdb-inline-forms">';
			self::form_open( 'merge' );
			echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '"><input type="hidden" name="dry_run" value="1">';
			echo '<button class="button"' . disabled( $blocked, true, false ) . '>' . esc_html__( 'Dry run', 'heimdb' ) . '</button></form>';
			self::form_open( 'merge', self::confirm_attr( sprintf( /* translators: 1: branch, 2: parent */ __( 'Merge %1$s into %2$s?', 'heimdb' ), $name, $b['parent'] ) ) );
			echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '">';
			/* translators: %s: parent */
			echo '<button class="button button-primary"' . disabled( $blocked, true, false ) . '>' . esc_html( sprintf( __( 'Merge into %s', 'heimdb' ), $b['parent'] ) ) . '</button></form>';
			echo '</p>';
		}
		echo '</div>';

		// 取り込み（HeimDB 0.2.33 から）
		echo '<div class="heimdb-box">';
		/* translators: %s: parent */
		echo '<h2>' . esc_html( sprintf( __( 'Pull in changes from %s', 'heimdb' ), $b['parent'] ) ) . '</h2>';
		/* translators: %s: parent */
		echo '<p class="description">' . esc_html( sprintf( __( 'Brings the latest of %s into this branch in one commit, such as other branches merged while this one was in review. Changes on this branch that were not merged are kept. Comments and users are copied too; environment options (siteurl, home, ...) and tables excluded from merges are not. Files are not copied. Needs HeimDB 0.2.33 or later.', 'heimdb' ), $b['parent'] ) ) . '</p>';
		if ( $resolved ) {
			echo '<p class="heimdb-warn">' . esc_html__( 'Pulling in clears the resolutions recorded on this branch. Decide the conflicts again before merging.', 'heimdb' ) . '</p>';
		}
		/* translators: 1: parent, 2: branch */
		self::form_open( 'sync', self::confirm_attr( sprintf( __( 'Pull in the changes of %1$s into %2$s?', 'heimdb' ), $b['parent'], $name ) ) );
		echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '">';
		echo '<p><label>' . esc_html__( 'When both sides changed the same column', 'heimdb' ) . ' <select name="side">';
		echo '<option value="">' . esc_html__( 'refuse (write nothing)', 'heimdb' ) . '</option>';
		echo '<option value="branch">' . esc_html__( 'keep the branch', 'heimdb' ) . '</option>';
		echo '<option value="parent">' . esc_html__( 'take the parent', 'heimdb' ) . '</option>';
		echo '</select></label></p>';
		echo '<p class="heimdb-inline-forms">';
		echo '<button class="button" name="dry_run" value="1" data-heimdb-no-confirm>' . esc_html__( 'Dry run', 'heimdb' ) . '</button> ';
		echo '<button class="button">' . esc_html__( 'Pull in', 'heimdb' ) . '</button>';
		echo '</p></form></div>';

		// 上限
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Limits', 'heimdb' ) . '</h2>';
		self::form_open( 'branch_limit' );
		echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '">';
		echo '<p><label>' . esc_html__( 'Size', 'heimdb' ) . ' <input name="max_size" class="heimdb-short" placeholder="2G" value="' . esc_attr( null === $b['max_size_bytes'] ? '' : self::size_input( (int) $b['max_size_bytes'] ) ) . '"></label> ';
		echo '<label>' . esc_html__( 'Statement time', 'heimdb' ) . ' <input name="max_time" class="heimdb-short" placeholder="30s" value="' . esc_attr( null === $b['max_execution_ms'] ? '' : self::duration_input( (int) $b['max_execution_ms'] ) ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'Empty means no limit.', 'heimdb' ) . '</p>';
		echo '<p><button class="button">' . esc_html__( 'Save limits', 'heimdb' ) . '</button></p></form></div>';

		// サイトのユーザー
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Database user for the branch site', 'heimdb' ) . '</h2>';
		/* translators: %s: database name */
		echo '<p class="description">' . esc_html( sprintf( __( 'Creates (or updates) a user that can use only %s. Even if the staging site is compromised, production is out of reach.', 'heimdb' ), $b['database'] ) ) . '</p>';
		self::form_open( 'branch_grant' );
		echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '">';
		echo '<p><label>' . esc_html__( 'User', 'heimdb' ) . ' <input name="user" class="regular-text" required value="' . esc_attr( $name ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Password', 'heimdb' ) . ' <input name="password" type="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr__( 'generate', 'heimdb' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Max connections', 'heimdb' ) . ' <input name="max_connections" type="number" min="0" class="small-text" placeholder="20"></label></p>';
		echo '<p class="description">' . esc_html__( 'Empty: 20 for a new user; an existing user keeps its password and max connections.', 'heimdb' ) . '</p>';
		echo '<p><button class="button">' . esc_html__( 'Grant', 'heimdb' ) . '</button></p></form>';
		echo '<pre class="heimdb-code">define( \'DB_NAME\', \'' . esc_html( $b['database'] ) . "' );\ndefine( 'DB_USER', '" . esc_html( $name ) . "' );</pre>";
		echo '</div>';

		// 削除（このサイトがつないでいるブランチは、このサイトからは消せない）
		echo '<div class="heimdb-box heimdb-danger"><h2>' . esc_html__( 'Delete', 'heimdb' ) . '</h2>';
		if ( Client::site_branch() === $name ) {
			echo '<p class="description">' . esc_html__( 'This site runs on this branch, so it cannot be deleted from here. Delete it from the production site or with wp-cli there.', 'heimdb' ) . '</p></div>';
		} else {
			/* translators: %s: branch */
			self::form_open( 'branch_delete', self::confirm_attr( sprintf( __( 'Delete branch %s? Its changes that were not merged are lost.', 'heimdb' ), $name ) ) );
			echo '<input type="hidden" name="branch" value="' . esc_attr( $name ) . '">';
			echo '<button class="button button-link-delete">' . esc_html__( 'Delete this branch', 'heimdb' ) . '</button></form></div>';
		}

		echo '</div></div>';
	}

	private static function summary_table( string $branch, array $rows ): void {
		?>
		<table class="widefat striped heimdb-table">
			<thead><tr>
				<th><?php esc_html_e( 'Table', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Status', 'heimdb' ); ?></th>
				<th class="num" title="<?php esc_attr_e( 'Rows the merge inserts into the parent', 'heimdb' ); ?>">+</th>
				<th class="num" title="<?php esc_attr_e( 'Rows the merge updates in the parent', 'heimdb' ); ?>">~</th>
				<th class="num" title="<?php esc_attr_e( 'Rows the merge deletes from the parent', 'heimdb' ); ?>">−</th>
				<th class="num"><?php esc_html_e( 'Skipped', 'heimdb' ); ?></th>
				<th class="num"><?php esc_html_e( 'Conflicts', 'heimdb' ); ?></th>
				<th class="num"><?php esc_html_e( 'Resolved', 'heimdb' ); ?></th>
				<th class="num"><?php esc_html_e( 'Warnings', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'heimdb' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr class="<?php echo (int) $r['conflicts'] ? 'heimdb-has-conflict' : ''; ?>">
					<td><a href="<?php echo esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $branch, 'table' => $r['table_name'] ) ) ); ?>"><code><?php echo esc_html( $r['table_name'] ); ?></code></a></td>
					<td><?php echo self::status_badge( (string) $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['inserts'] ) ); ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['updates'] ) ); ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['deletes'] ) ); ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['skipped'] ) ); ?></td>
					<td class="num"><?php echo (int) $r['conflicts'] ? '<span class="heimdb-badge heimdb-badge-conflict">' . esc_html( $r['conflicts'] ) . '</span>' : ''; ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['resolved'] ) ); ?></td>
					<td class="num"><?php echo esc_html( self::num( $r['warnings'] ) ); ?></td>
					<td><?php echo esc_html( (string) $r['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function view_row_diff( string $branch, string $table ): void {
		$client = Client::instance();
		$b      = $client->branch( $branch );
		$filter = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$rows   = $client->branch_diff( $branch, $table );

		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ $r['action'] ] = ( $counts[ $r['action'] ] ?? 0 ) + 1;
		}
		if ( '' !== $filter ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => $r['action'] === $filter || ( 'conflict' === $filter && null !== $r['resolution'] ) ) );
		}
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::ROWS_PER_PAGE, self::ROWS_PER_PAGE );

		self::breadcrumbs( array( $branch => self::url( self::PAGE_BRANCHES, array( 'branch' => $branch ) ), ( '*' === $table ? __( 'All tables', 'heimdb' ) : $table ) => null ) );
		/* translators: 1: table, 2: branch, 3: parent */
		echo '<h1>' . esc_html( sprintf( __( '%1$s: %2$s → %3$s', 'heimdb' ), '*' === $table ? __( 'All tables', 'heimdb' ) : $table, $branch, self::parent_label( $b ) ) ) . '</h1>';
		self::flash();

		// 判定ごとの絞り込み
		$base  = array( 'branch' => $branch, 'table' => $table );
		$links = array( '<a href="' . esc_url( self::url( self::PAGE_BRANCHES, $base ) ) . '"' . ( '' === $filter ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'heimdb' ) . ' <span class="count">(' . esc_html( (string) array_sum( $counts ) ) . ')</span></a>' );
		foreach ( $counts as $action => $count ) {
			$links[] = '<a href="' . esc_url( self::url( self::PAGE_BRANCHES, $base + array( 'action' => $action ) ) ) . '"' . ( $filter === $action ? ' class="current"' : '' ) . '>' . esc_html( self::action_label( (string) $action ) ) . ' <span class="count">(' . esc_html( (string) $count ) . ')</span></a>';
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<br class="clear">';

		if ( ! empty( $counts['conflict'] ) && '*' !== $table && ! $client->is_branch_user() ) {
			echo '<p class="heimdb-inline-forms">';
			/* translators: %s: table */
			self::resolve_button( $branch, $table, '*', 'branch', sprintf( __( 'Keep the branch for all conflicts in %s', 'heimdb' ), $table ) );
			/* translators: %s: table */
			self::resolve_button( $branch, $table, '*', 'parent', sprintf( __( 'Keep the parent for all conflicts in %s', 'heimdb' ), $table ) );
			echo '</p>';
		}

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No rows.', 'heimdb' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped heimdb-table">
			<thead><tr>
				<?php if ( '*' === $table ) : ?><th><?php esc_html_e( 'Table', 'heimdb' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Row', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Branch', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Parent', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Merge does', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Columns', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'heimdb' ); ?></th>
				<th><?php esc_html_e( 'Resolution', 'heimdb' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $is_row = '*' !== $r['id'] && 0 !== strpos( (string) $r['id'], 'COLUMN ' ) && 0 !== strpos( (string) $r['id'], 'INDEX ' ); ?>
				<tr class="<?php echo 'conflict' === $r['action'] ? 'heimdb-has-conflict' : ''; ?>">
					<?php if ( '*' === $table ) : ?>
						<td><a href="<?php echo esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $branch, 'table' => $r['table_name'] ) ) ); ?>"><code><?php echo esc_html( $r['table_name'] ); ?></code></a></td>
					<?php endif; ?>
					<td>
						<?php if ( $is_row ) : ?>
							<a href="<?php echo esc_url( self::url( self::PAGE_BRANCHES, array( 'branch' => $branch, 'table' => $r['table_name'], 'id' => $r['id'], 'columns' => $r['columns'] ) ) ); ?>"><code><?php echo esc_html( $r['id'] ); ?></code></a>
							<?php echo esc_html( self::row_label( $branch, (string) $r['table_name'], (string) $r['id'] ) ); ?>
						<?php else : ?>
							<code><?php echo esc_html( $r['id'] ); ?></code>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( self::change_label( (string) $r['branch_change'] ) ); ?></td>
					<td><?php echo esc_html( self::change_label( (string) $r['parent_change'] ) ); ?></td>
					<td><?php echo self::merge_badge( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( str_replace( ',', ', ', (string) $r['columns'] ) ); ?></td>
					<td><?php echo esc_html( (string) $r['detail'] ); ?></td>
					<td class="heimdb-inline-forms">
						<?php if ( 'conflict' === $r['action'] || null !== $r['resolution'] ) : ?>
							<?php self::resolution_controls( $branch, (string) $r['table_name'], (string) $r['id'], $r['resolution'], false, Client::parent_only( $r['detail'] ) ); ?>
						<?php endif; ?>
						<?php echo self::resolved_by( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $total, $paged );
	}

	/**
	 * 行の両側（ブランチと親）を並べる。競合なら解決のボタンも出す。
	 */
	private static function view_compare( string $branch, string $table, string $id ): void {
		$client  = Client::instance();
		$b       = $client->branch( $branch );
		$bu      = $client->is_branch_user();
		$hidden  = Client::is_hidden_row( $id );
		$parent  = (string) $b['parent'];
		$ours    = $hidden ? null : $client->row( $branch, $table, $id );
		// ブランチのユーザーは親を読めない。主キーのない表の行は誰も 1 行を引けない（どちらも、値は差分の conflict_values の競合した列だけ）。
		$theirs  = $bu || $hidden ? null : $client->row( $parent, $table, $id );
		$base    = $bu || $hidden ? null : $client->row( $parent, $table, $id, ( Time::parse( (string) $b['forked_at'] ) ?? throw new Error( sprintf( /* translators: %s: time from HeimDB */ __( 'Cannot read the fork time "%s".', 'heimdb' ), $b['forked_at'] ) ) )->format( 'Y-m-d H:i:s.uP' ) );
		$diff    = null;
		foreach ( $client->branch_diff( $branch, $table ) as $r ) {
			if ( $r['id'] === $id ) {
				$diff = $r;
				break;
			}
		}

		self::breadcrumbs(
			array(
				$branch => self::url( self::PAGE_BRANCHES, array( 'branch' => $branch ) ),
				$table  => self::url( self::PAGE_BRANCHES, array( 'branch' => $branch, 'table' => $table ) ),
				$id     => null,
			)
		);
		echo '<h1>' . esc_html( $table . ' ' . $id ) . ' ' . esc_html( self::row_label( $branch, $table, $id ) ) . '</h1>';
		self::flash();

		if ( $diff ) {
			echo '<p>' . esc_html__( 'Merge does:', 'heimdb' ) . ' ' . self::merge_badge( $diff ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $diff['detail'] ) {
				echo ' — ' . esc_html( (string) $diff['detail'] );
			}
			echo '</p>';
			if ( 'conflict' === $diff['action'] || null !== $diff['resolution'] ) {
				echo '<div class="heimdb-box heimdb-resolve"><h2>' . esc_html__( 'Which value should the merge keep?', 'heimdb' ) . '</h2>';
				echo '<p class="description">' . esc_html__( 'Only the conflicting columns are decided here. Columns changed only on the branch are merged either way.', 'heimdb' ) . '</p>';
				echo '<p class="heimdb-inline-forms">';
				self::resolution_controls( $branch, $table, $id, $diff['resolution'], true, Client::parent_only( $diff['detail'] ) );
				echo '</p>' . self::resolved_by( $diff ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		} else {
			echo '<p>' . esc_html__( 'This row is no longer in the diff (it may have been merged or changed back).', 'heimdb' ) . '</p>';
		}

		$highlight = array_filter( explode( ',', (string) ( $diff['columns'] ?? ( $_GET['columns'] ?? '' ) ) ) );
		if ( $hidden ) {
			$note = '' !== (string) ( $diff['conflict_values'] ?? '' )
				? __( 'This table has no primary key, so its rows are named by a hidden row ID that SQL cannot look up. Only the current values of the conflicting columns are shown.', 'heimdb' )
				: __( 'This table has no primary key, so its rows are named by a hidden row ID that SQL cannot look up. A single row cannot be shown.', 'heimdb' );
			self::view_compare_values( $branch, $diff, null, $highlight, $note );
			return;
		}
		if ( $bu ) {
			self::view_compare_values( $branch, $diff, $ours, $highlight, __( 'This site connects as a branch user, which cannot read the parent. Of the parent, only the current values of the conflicting columns are shown.', 'heimdb' ) );
			return;
		}
		/* translators: %s: parent */
		$theirs_label = sprintf( __( '%s now', 'heimdb' ), $parent );
		// 両側で足された同じ行は、親では別の主キーの行（HeimDB 0.2.16 からは parent_change が insert）。
		$duplicate = null === $theirs ? Client::parent_duplicate( $diff['detail'] ?? null ) : null;
		if ( null !== $duplicate ) {
			$theirs = $client->row( $parent, $table, $duplicate );
			/* translators: 1: parent, 2: row id such as ID=4 */
			$theirs_label = sprintf( __( '%1$s now (%2$s)', 'heimdb' ), $parent, $duplicate );
		}
		$labels = array(
			/* translators: 1: parent, 2: time */
			'base'   => sprintf( __( '%1$s at fork (%2$s)', 'heimdb' ), $parent, Time::display( $b['forked_at'] ) ),
			'theirs' => $theirs_label,
			/* translators: %s: branch */
			'ours'   => sprintf( __( '%s now', 'heimdb' ), $branch ),
		);
		self::compare_table(
			array(
				$labels['base']   => $base,
				$labels['theirs'] => $theirs,
				$labels['ours']   => $ours,
			),
			$highlight,
			array( $labels['theirs'], $labels['ours'] )
		);
	}

	/**
	 * 行を読めないときの、行の比較（ブランチのユーザー、主キーのない表）。競合した列の両側の今の値（差分の conflict_values）と、
	 * 読めればブランチの今の行を出す。
	 */
	private static function view_compare_values( string $branch, ?array $diff, ?array $ours, array $highlight, string $note ): void {
		$values = json_decode( (string) ( $diff['conflict_values'] ?? '' ), true );
		if ( is_array( $values ) && $values ) {
			echo '<h2>' . esc_html__( 'Conflicting columns', 'heimdb' ) . '</h2>';
			$side = function ( string $which, string $change ) use ( $values ) {
				return 'delete' === $change ? null : array_map( fn( $v ) => $v[ $which ] ?? null, $values );
			};
			$parent_label = __( 'Parent now', 'heimdb' );
			/* translators: %s: branch */
			$branch_label = sprintf( __( '%s now', 'heimdb' ), $branch );
			self::compare_table(
				array(
					$parent_label => $side( 'parent', (string) $diff['parent_change'] ),
					$branch_label => $side( 'branch', (string) $diff['branch_change'] ),
				),
				array_keys( $values ),
				array( $parent_label, $branch_label )
			);
		}
		if ( null !== $ours ) {
			/* translators: %s: branch */
			echo '<h2>' . esc_html( sprintf( __( '%s now', 'heimdb' ), $branch ) ) . '</h2>';
			/* translators: %s: branch */
			self::compare_table( array( sprintf( __( '%s now', 'heimdb' ), $branch ) => $ours ), $highlight );
		}
		echo '<p class="description">' . esc_html( $note ) . '</p>';
	}

	// ================================================================ 画面: タイムトラベル

	public static function page_time(): void {
		echo '<div class="wrap heimdb">';
		if ( ! self::header_and_check() ) {
			echo '</div>';
			return;
		}
		$client = Client::instance();
		$branch = isset( $_GET['branch'] ) ? sanitize_text_field( wp_unslash( $_GET['branch'] ) ) : Client::site_branch();
		$at_in  = isset( $_GET['at'] ) ? sanitize_text_field( wp_unslash( $_GET['at'] ) ) : '';
		$tables = isset( $_GET['tables'] ) ? sanitize_text_field( wp_unslash( $_GET['tables'] ) ) : '';
		$do     = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$table  = isset( $_GET['table'] ) ? sanitize_text_field( wp_unslash( $_GET['table'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? wp_unslash( (string) $_GET['id'] ) : '';

		try {
			$client->branch( $branch );
			$at = '' !== $at_in ? Time::to_db( $at_in ) : null;

			if ( null !== $at && '' !== $table && '' !== $id ) {
				self::view_asof_row( $branch, $at, $table, $id );
				echo '</div>';
				return;
			}

			echo '<h1>' . esc_html__( 'Time travel', 'heimdb' ) . '</h1>';
			echo '<p class="description">' . esc_html__( 'HeimDB keeps every version of every row for the retention period. See what changed since a point in time, read a row as it was, restore the branch to that point (undoable), or branch off from it.', 'heimdb' ) . '</p>';
			self::flash();
			self::time_form( $branch, $at, $tables );

			if ( null === $at ) {
				echo '</div>';
				return;
			}
			if ( 'preview' === $do ) {
				self::view_restore_preview( $branch, $at, $tables );
			} else {
				self::view_changes( $branch, $at, $tables );
			}
		} catch ( Error $e ) {
			self::flash();
			self::notice( 'error', self::error_message( $e ) );
		}
		echo '</div>';
	}

	private static function time_form( string $branch, ?string $at, string $tables ): void {
		$presets = array(
			'-15 minutes' => __( '15 min ago', 'heimdb' ),
			'-1 hour'     => __( '1 hour ago', 'heimdb' ),
			'-1 day'      => __( '1 day ago', 'heimdb' ),
			'-7 days'     => __( '7 days ago', 'heimdb' ),
		);
		?>
		<form method="get" class="heimdb-box heimdb-time-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_TIME ); ?>">
			<p>
				<label><?php esc_html_e( 'Branch', 'heimdb' ); ?> <?php self::branch_select( 'branch', $branch ); ?></label>
				<label><?php esc_html_e( 'Time', 'heimdb' ); ?> <input type="datetime-local" step="1" name="at" required id="heimdb-time-at" value="<?php echo esc_attr( $at ? Time::to_input( $at ) : Time::to_input( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); ?>"></label>
				<span class="description"><?php echo esc_html( wp_timezone_string() ); ?></span>
			</p>
			<p class="heimdb-presets">
				<?php foreach ( $presets as $offset => $label ) : ?>
					<button type="button" class="button button-small" data-heimdb-preset="<?php echo esc_attr( Time::to_input( gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ) ) ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</p>
			<p>
				<label><?php esc_html_e( 'Tables', 'heimdb' ); ?> <input name="tables" class="regular-text" value="<?php echo esc_attr( $tables ); ?>" placeholder="wp_posts,wp_postmeta"></label>
				<span class="description"><?php esc_html_e( 'Comma separated. Empty means all.', 'heimdb' ); ?></span>
			</p>
			<p>
				<button class="button button-primary" name="do" value="changes"><?php esc_html_e( 'Show changes since then', 'heimdb' ); ?></button>
				<button class="button" name="do" value="preview"><?php esc_html_e( 'Preview restoring to then', 'heimdb' ); ?></button>
			</p>
		</form>
		<?php
	}

	private static function view_changes( string $branch, string $at, string $tables ): void {
		$rows = Client::instance()->changes( $branch, $at, $tables );
		/* translators: 1: branch, 2: time */
		echo '<h2>' . esc_html( sprintf( __( 'Changes on %1$s since %2$s', 'heimdb' ), $branch, Time::display( $at ) ) ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing changed since then.', 'heimdb' ) . '</p>';
		} else {
			echo '<table class="widefat striped heimdb-table"><thead><tr>';
			foreach ( array( __( 'Changed at', 'heimdb' ), __( 'Table', 'heimdb' ), __( 'Row', 'heimdb' ), __( 'Change', 'heimdb' ), __( 'Columns', 'heimdb' ), __( 'Detail', 'heimdb' ) ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$is_row = '*' !== $r['id'];
				echo '<tr class="' . ( $r['detail'] && $is_row ? 'heimdb-watch' : '' ) . '">';
				echo '<td>' . esc_html( Time::display( $r['changed_at'] ) ) . '</td>';
				echo '<td><code>' . esc_html( $r['table_name'] ) . '</code></td>';
				echo '<td>';
				if ( $is_row ) {
					$url = self::url( self::PAGE_TIME, array( 'branch' => $branch, 'at' => Time::to_input( $at ), 'table' => $r['table_name'], 'id' => $r['id'] ) );
					echo '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $r['id'] ) . '</code></a> ' . esc_html( self::row_label( $branch, (string) $r['table_name'], (string) $r['id'] ) );
				} else {
					echo '<code>*</code>';
				}
				echo '</td>';
				echo '<td>' . self::action_badge( (string) $r['change'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td>' . esc_html( str_replace( ',', ', ', (string) $r['columns'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $r['detail'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		self::branch_from_form( $branch, $at );
	}

	private static function view_restore_preview( string $branch, string $at, string $tables ): void {
		$client  = Client::instance();
		$default = $client->restore_preview( $branch, $at, '' !== $tables ? $tables : null );
		/* translators: 1: branch, 2: time */
		echo '<h2>' . esc_html( sprintf( __( 'Restoring %1$s to %2$s would…', 'heimdb' ), $branch, Time::display( $at, true ) ) ) . '</h2>';
		if ( ! $default ) {
			echo '<p>' . esc_html__( 'Nothing to restore: the branch is already in that state.', 'heimdb' ) . '</p>';
			self::branch_from_form( $branch, $at );
			return;
		}
		self::result_table( $default );
		$pair = Client::restore_suggestion( $default );
		if ( $pair ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: %s: tables */
			echo esc_html( sprintf( __( 'Some rows also changed in a paired table that would not be restored (see Detail). Restoring only one side leaves, for example, a post with its old title but new custom fields. Restore %s together?', 'heimdb' ), str_replace( ',', ', ', $pair['tables'] ) ) );
			echo ' <a class="button button-small" href="' . esc_url( self::url( self::PAGE_TIME, array( 'branch' => $branch, 'at' => Time::to_input( $at ), 'tables' => $pair['tables'], 'do' => 'preview' ) ) ) . '">' . esc_html__( 'Preview restoring them together', 'heimdb' ) . '</a>';
			echo '</p></div>';
		}
		if ( '' === $tables ) {
			echo '<p class="description">' . esc_html__( 'Comments and users (wp_comments, wp_commentmeta, wp_users, wp_usermeta) are kept as they are now, so comments posted and users added since then are not lost. Choose "All tables" to restore them too.', 'heimdb' ) . '</p>';
		}

		$restorable = array_values( array_unique( array_column( array_filter( $default, fn( $r ) => 'not restored' !== $r['action'] ), 'table_name' ) ) );
		$scope      = '' !== $tables ? 'tables' : 'default';
		/* translators: 1: branch, 2: time */
		self::form_open( 'restore', self::confirm_attr( sprintf( __( 'Restore %1$s to %2$s? You can undo it afterwards.', 'heimdb' ), $branch, Time::display( $at, true ) ) ) . ' data-heimdb-confirm-scope', self::url( self::PAGE_TIME, array( 'branch' => $branch, 'at' => Time::to_input( $at ), 'do' => 'changes' ) ) );
		echo '<div class="heimdb-box heimdb-danger">';
		echo '<input type="hidden" name="branch" value="' . esc_attr( $branch ) . '"><input type="hidden" name="at" value="' . esc_attr( $at ) . '">';
		echo '<p><label><input type="radio" name="scope" value="default"' . checked( 'default', $scope, false ) . '> ' . esc_html__( 'Everything except comments and users', 'heimdb' ) . '</label><br>';
		echo '<label><input type="radio" name="scope" value="all"> ' . esc_html__( 'All tables (including comments and users)', 'heimdb' ) . '</label><br>';
		echo '<label><input type="radio" name="scope" value="tables"' . checked( 'tables', $scope, false ) . '> ' . esc_html__( 'Only these tables:', 'heimdb' ) . '</label></p>';
		echo '<p class="heimdb-table-choices">';
		$chosen = '' !== $tables ? array_map( 'trim', explode( ',', $tables ) ) : $restorable;
		foreach ( $restorable as $t ) {
			echo '<label><input type="checkbox" name="tables[]" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $chosen, true ), true, false ) . '> <code>' . esc_html( $t ) . '</code></label> ';
		}
		echo '</p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Restore', 'heimdb' ) . '</button></p>';
		echo '</div></form>';

		self::branch_from_form( $branch, $at );
	}

	/**
	 * 行のその時点の値と今の値を並べる。
	 */
	private static function view_asof_row( string $branch, string $at, string $table, string $id ): void {
		$client = Client::instance();
		$hidden = Client::is_hidden_row( $id );
		$then   = $hidden ? null : $client->row( $branch, $table, $id, $at );
		$now    = $hidden ? null : $client->row( $branch, $table, $id );
		self::breadcrumbs(
			array(
				__( 'Time travel', 'heimdb' ) => self::url( self::PAGE_TIME, array( 'branch' => $branch, 'at' => Time::to_input( $at ), 'do' => 'changes' ) ),
				$table . ' ' . $id           => null,
			),
			self::PAGE_TIME
		);
		echo '<h1>' . esc_html( $table . ' ' . $id ) . ' ' . esc_html( self::row_label( $branch, $table, $id ) ) . '</h1>';
		/* translators: %s: branch */
		echo '<p class="heimdb-meta">' . esc_html( sprintf( __( 'Branch %s', 'heimdb' ), $branch ) ) . '</p>';
		self::flash();
		$t_label = Time::display( $at );
		$n_label = __( 'Now', 'heimdb' );
		if ( $hidden ) {
			self::notice( 'info', __( 'This table has no primary key, so its rows are named by a hidden row ID that SQL cannot look up. A single row cannot be shown.', 'heimdb' ) );
		} else {
			self::compare_table( array( $t_label => $then, $n_label => $now ), array(), array( $t_label, $n_label ) );
		}
		/* translators: 1: table, 2: time */
		echo '<p class="description">' . esc_html( sprintf( __( 'To put back only this table, preview restoring %1$s to %2$s.', 'heimdb' ), $table, $t_label ) ) . ' ';
		echo '<a href="' . esc_url( self::url( self::PAGE_TIME, array( 'branch' => $branch, 'at' => Time::to_input( $at ), 'tables' => $table, 'do' => 'preview' ) ) ) . '">' . esc_html__( 'Preview', 'heimdb' ) . '</a></p>';
	}

	private static function branch_from_form( string $branch, string $at ): void {
		if ( Client::instance()->is_branch_user() ) {
			return; // ブランチの作成は管理者だけ
		}
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Branch off from this point', 'heimdb' ) . '</h2>';
		/* translators: 1: branch, 2: time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Creates a new branch with %1$s as it was at %2$s, without changing %1$s. Useful to look around or to take data back by merging.', 'heimdb' ), $branch, Time::display( $at, true ) ) ) . '</p>';
		self::form_open( 'branch_create' );
		echo '<input type="hidden" name="parent" value="' . esc_attr( $branch ) . '"><input type="hidden" name="at" value="' . esc_attr( $at ) . '">';
		echo '<p><label>' . esc_html__( 'Name', 'heimdb' ) . ' <input name="name" required pattern="[a-z0-9_\-]+" value="' . esc_attr( 'past-' . wp_date( 'md-Hi', (int) Time::parse( $at )->getTimestamp() ) ) . '"></label> ';
		echo '<button class="button">' . esc_html__( 'Create branch', 'heimdb' ) . '</button></p></form></div>';
	}

	// ================================================================ 画面: 状態

	public static function page_status(): void {
		echo '<div class="wrap heimdb">';
		echo '<h1>' . esc_html__( 'HeimDB status', 'heimdb' ) . '</h1>';
		self::flash();
		self::connection_info();

		$client = Client::instance();
		$check  = $client->check();
		if ( true !== $check ) {
			self::notice( 'error', self::error_message( $check ) );
			self::setup_help();
			echo '</div>';
			return;
		}

		$bu = $client->is_branch_user();
		echo '<div class="heimdb-columns"><div class="heimdb-main">';

		if ( $bu ) {
			// ブランチのユーザーが読めるのは、マージから外す表の一覧だけ（変えるのも、サーバの状態も管理者だけ）。
			echo '<h2>' . esc_html__( 'Tables excluded from merges', 'heimdb' ) . '</h2>';
			$excludes = $client->merge_excludes();
			echo $excludes ? '<ul><li><code>' . implode( '</code></li><li><code>', array_map( 'esc_html', $excludes ) ) . '</code></li></ul>' : '<p>' . esc_html__( 'None yet.', 'heimdb' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p class="description">' . esc_html__( 'Changed by an administrator from the production site.', 'heimdb' ) . '</p>';
			echo '</div></div></div>';
			return;
		}

		// heimdb_status
		echo '<h2>' . esc_html__( 'Server', 'heimdb' ) . '</h2>';
		$status = $client->status();
		echo '<table class="widefat striped heimdb-table heimdb-status"><tbody>';
		foreach ( $status as $name => $value ) {
			echo '<tr><th><code>' . esc_html( $name ) . '</code></th><td>' . esc_html( self::status_value( (string) $name, $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '</div><div class="heimdb-side">';

		// マージから外す表
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Tables excluded from merges', 'heimdb' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Rebuildable tables such as search indexes (e.g. wp_yoast_%). Merges leave them as they are on the parent.', 'heimdb' ) . '</p>';
		$excludes = $client->merge_excludes();
		if ( $excludes ) {
			echo '<ul>';
			foreach ( $excludes as $pattern ) {
				echo '<li><code>' . esc_html( $pattern ) . '</code> ';
				self::form_open( 'exclude_remove', '', null, 'heimdb-inline' );
				echo '<input type="hidden" name="pattern" value="' . esc_attr( $pattern ) . '"><button class="button-link">' . esc_html__( 'Remove', 'heimdb' ) . '</button></form></li>';
			}
			echo '</ul>';
		}
		self::form_open( 'exclude_add' );
		echo '<p><input name="pattern" required placeholder="wp_yoast_%"> <button class="button">' . esc_html__( 'Exclude', 'heimdb' ) . '</button></p></form></div>';

		// バックアップ
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Backup', 'heimdb' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A consistent online backup of all branches, history and users, written under the server\'s -backup-dir.', 'heimdb' ) . '</p>';
		self::form_open( 'backup' );
		echo '<p><input name="name" pattern="[A-Za-z0-9._\-]*" placeholder="before-upgrade"> <button class="button">' . esc_html__( 'Back up now', 'heimdb' ) . '</button></p></form></div>';

		// 古いインデックスの作り直し（HeimDB 0.2.39 から）
		echo '<div class="heimdb-box"><h2>' . esc_html__( 'Rebuild old indexes', 'heimdb' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Indexes on DECIMAL, FLOAT, DOUBLE, ENUM, SET and BIT columns made by HeimDB 0.2.40 or earlier have no entries and read the whole table each time (e.g. WooCommerce\'s wc_product_meta_lookup). Since HeimDB 0.2.42, main\'s are rebuilt in the background after start; rebuild branches and anything it skipped here. Writes to the branch wait while it runs. Needs HeimDB 0.2.39 or later.', 'heimdb' ) . '</p>';
		self::form_open( 'rebuild_indexes' );
		echo '<p>';
		self::branch_select( 'branch', Client::site_branch() );
		echo ' <button class="button">' . esc_html__( 'Rebuild', 'heimdb' ) . '</button></p></form></div>';

		// GC
		echo '<div class="heimdb-box heimdb-danger"><h2>' . esc_html__( 'Garbage collection', 'heimdb' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Removes versions older than the retention period now (the server also does this hourly). Points before the boundary can no longer be read, restored or branched from.', 'heimdb' ) . '</p>';
		self::form_open( 'gc', self::confirm_attr( __( 'Remove old versions now?', 'heimdb' ) ) );
		echo '<p><button class="button">' . esc_html__( 'Run GC', 'heimdb' ) . '</button></p></form></div>';

		echo '</div></div></div>';
	}

	private static function connection_info(): void {
		$host = defined( 'HEIMDB_ADMIN_HOST' ) ? HEIMDB_ADMIN_HOST : DB_HOST;
		echo '<p class="heimdb-meta">';
		/* translators: 1: user, 2: host, 3: branch, 4: database */
		echo esc_html( sprintf( __( 'Procedures run as %1$s@%2$s. This site uses branch %3$s (database %4$s).', 'heimdb' ), Client::admin_user(), $host, Client::site_branch(), DB_NAME ) );
		try {
			if ( Client::instance()->is_branch_user() ) {
				echo ' ' . esc_html__( 'The user has access to this branch only, so the merge and branch management are done from the production site.', 'heimdb' );
			}
		} catch ( Error $e ) {
			// つなげないことは、この後の確かめで出す。
		}
		echo '</p>';
	}

	private static function setup_help(): void {
		echo '<h2>' . esc_html__( 'Setup', 'heimdb' ) . '</h2>';
		if ( 'main' !== Client::site_branch() && ! Client::has_admin_credentials() ) {
			echo '<p>' . esc_html__( 'On a staging site, keep connecting with the user of the branch, and start HeimDB (0.2.15 or later) with the flag below. The user needs the EXECUTE privilege on the branch database, which GRANT ALL includes.', 'heimdb' ) . '</p>';
			echo '<pre class="heimdb-code">heimdb -branch-procedures ...   # ' . esc_html__( 'or the environment variable HEIMDB_BRANCH_PROCEDURES=1', 'heimdb' ) . '</pre>';
			echo '<p class="description">' . esc_html__( 'The diff then also shows values changed on the parent (production). Do not use the flag if branches separate different tenants.', 'heimdb' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'HeimDB procedures can be called only by an administrator (SUPER). WordPress itself should keep connecting with the site user, so give this plugin the administrator separately in wp-config.php:', 'heimdb' ) . '</p>';
		echo "<pre class=\"heimdb-code\">define( 'HEIMDB_ADMIN_USER', 'root' );\ndefine( 'HEIMDB_ADMIN_PASSWORD', '...' );\n// define( 'HEIMDB_ADMIN_HOST', 'heimdb-host:3306' ); // " . esc_html__( 'defaults to DB_HOST', 'heimdb' ) . '</pre>';
		echo '<p class="description">' . esc_html__( 'Anyone who can run PHP on this site can read these credentials. Define them only on the site you manage branches from (usually production), never on a staging site.', 'heimdb' ) . '</p>';
	}

	// ================================================================ 部品

	/**
	 * 画面の頭で接続を確かめる。使えなければ設定の案内を出して false。
	 */
	private static function header_and_check(): bool {
		// 通知はここで受け取る。画面の途中で例外が出ても、受け取った通知は catch の flash() で出す（後で別の画面に出さない）。
		self::take_notice();
		$check = Client::instance()->check();
		if ( true !== $check ) {
			echo '<h1>HeimDB</h1>';
			self::flash();
			self::notice( 'error', self::error_message( $check ) );
			self::setup_help();
			return false;
		}
		if ( 'main' !== Client::site_branch() && empty( $_GET['branch'] ) ) {
			/* translators: %s: branch */
			self::notice( 'warning', sprintf( __( 'This site is running on branch %s, not production.', 'heimdb' ), Client::site_branch() ) );
		}
		return true;
	}

	/** @var array<string,mixed>|null|false 受け取った通知（false はまだ受け取っていない、null は出した後かない）。 */
	private static $notice = false;

	private static function take_notice(): void {
		if ( false !== self::$notice ) {
			return;
		}
		self::$notice = get_transient( self::notice_key() ) ?: null;
		if ( self::$notice ) {
			delete_transient( self::notice_key() );
		}
	}

	private static function flash(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo '<hr class="wp-header-end">';
		self::take_notice();
		$notice       = self::$notice;
		self::$notice = null;
		if ( ! $notice ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' heimdb-notice"><p>' . esc_html( $notice['message'] ) . '</p>';
		if ( ! empty( $notice['rows'] ) ) {
			self::result_table( $notice['rows'] );
		}
		if ( ! empty( $notice['undo'] ) && is_array( $notice['undo'] ) ) {
			echo '<p>';
			self::undo_button( $notice['undo'] );
			echo '</p><p class="description">' . esc_html__( 'Undo restores the tables to just before the operation. Anything written to those tables after it is lost too. To undo later, restore to that time from Time travel.', 'heimdb' ) . '</p>';
		}
		echo '</div>';
	}

	private static function notice( string $type, string $message ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * マージや復元の結果の行（列はそのまま）。
	 */
	private static function result_table( array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		$columns = array_keys( $rows[0] );
		echo '<table class="widefat striped heimdb-table heimdb-result"><thead><tr>';
		foreach ( $columns as $c ) {
			echo '<th' . ( in_array( $c, array( 'inserts', 'updates', 'deletes', 'skipped', 'conflicts', 'resolved', 'warnings' ), true ) ? ' class="num"' : '' ) . '>' . esc_html( self::column_label( (string) $c ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( $columns as $c ) {
				$v = $r[ $c ];
				if ( in_array( $c, array( 'status', 'action' ), true ) ) {
					echo '<td>' . self::status_badge( (string) $v ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( in_array( $c, array( 'table_name', 'table', 'index' ), true ) ) {
					echo '<td><code>' . esc_html( (string) $v ) . '</code></td>';
				} elseif ( is_numeric( $v ) ) {
					echo '<td class="num">' . esc_html( self::num( $v ) ) . '</td>';
				} else {
					echo '<td>' . esc_html( self::num( $v ) ) . '</td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * 行の値をバージョンごとに並べる。長い文字列が違えば、行ごとの差分（wp_text_diff）も出す。
	 *
	 * @param array<string,array<string,mixed>|null> $versions 見出しごとの行（ない行は null）。
	 * @param string[]                               $highlight 目立たせる列。
	 * @param string[]                               $diff_pair 差分を取る 2 つの見出し（古い方、新しい方）。空なら取らない。
	 */
	private static function compare_table( array $versions, array $highlight, array $diff_pair = array() ): void {
		$fields = array();
		foreach ( $versions as $row ) {
			if ( $row ) {
				$fields = array_keys( $row );
				break;
			}
		}
		if ( ! $fields ) {
			echo '<p>' . esc_html__( 'The row does not exist in any of these versions.', 'heimdb' ) . '</p>';
			return;
		}
		list( $left, $right ) = $diff_pair + array( null, null );
		$text_diffs           = array();

		echo '<table class="widefat heimdb-table heimdb-compare"><thead><tr><th>' . esc_html__( 'Column', 'heimdb' ) . '</th>';
		foreach ( array_keys( $versions ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $fields as $f ) {
			$values  = array_map( fn( $row ) => null === $row ? null : ( $row[ $f ] ?? null ), $versions );
			$differs = count( array_unique( array_map( 'serialize', $values ) ) ) > 1;
			$marked  = in_array( strtolower( $f ), array_map( 'strtolower', $highlight ), true );
			echo '<tr class="' . ( $marked ? 'heimdb-conflict-col ' : '' ) . ( $differs ? 'heimdb-differs' : '' ) . '"><th><code>' . esc_html( $f ) . '</code></th>';
			foreach ( $versions as $label => $row ) {
				if ( null === $row ) {
					echo '<td class="heimdb-missing">' . esc_html__( '(no row)', 'heimdb' ) . '</td>';
					continue;
				}
				$v = $row[ $f ];
				echo '<td>' . ( null === $v ? '<span class="heimdb-null">NULL</span>' : '<div class="heimdb-value">' . esc_html( (string) $v ) . '</div>' ) . '</td>';
			}
			echo '</tr>';
			if ( null === $left ) {
				continue;
			}
			$a = $versions[ $left ][ $f ] ?? null;
			$b = $versions[ $right ][ $f ] ?? null;
			if ( $a !== $b && ( strlen( (string) $a ) > 80 || strlen( (string) $b ) > 80 || false !== strpos( (string) $a . $b, "\n" ) ) ) {
				$text_diffs[ $f ] = wp_text_diff( (string) $a, (string) $b, array( 'title_left' => $left, 'title_right' => $right ) );
			}
		}
		echo '</tbody></table>';
		foreach ( $text_diffs as $f => $html ) {
			if ( $html ) {
				echo '<h3><code>' . esc_html( $f ) . '</code></h3>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_text_diff escapes.
			}
		}
	}

	/**
	 * @param string|null $parent_only 親の側でしか解決できない理由（Client::parent_only）。あればブランチの側のボタンは出さない。
	 */
	private static function resolution_controls( string $branch, string $table, string $id, ?string $resolution, bool $large = false, ?string $parent_only = null ): void {
		if ( null !== $resolution ) {
			echo '<span class="heimdb-badge heimdb-badge-' . esc_attr( $resolution ) . '">' . esc_html( self::side_label( $resolution ) ) . '</span> ';
		}
		$labels = $large
			? array(
				'branch' => __( 'Keep the branch value', 'heimdb' ),
				'parent' => __( 'Keep the parent value', 'heimdb' ),
			)
			: array(
				'branch' => __( 'Branch', 'heimdb' ),
				'parent' => __( 'Parent', 'heimdb' ),
			);
		if ( null !== $parent_only ) {
			unset( $labels['branch'] );
		}
		foreach ( $labels as $side => $label ) {
			if ( $resolution !== $side ) {
				self::resolve_button( $branch, $table, $id, $side, $label, $large ? '' : 'button-small' );
			}
		}
		if ( null !== $resolution ) {
			self::resolve_button( $branch, $table, $id, 'none', __( 'Undecide', 'heimdb' ), 'button-link' . ( $large ? '' : ' button-small' ) );
		}
		if ( null !== $parent_only ) {
			/* translators: %s: reason from HeimDB */
			echo '<span class="description">' . esc_html( sprintf( __( 'Only the parent value can be kept: %s.', 'heimdb' ), $parent_only ) ) . '</span>';
		}
	}

	private static function resolve_button( string $branch, string $table, string $row, string $side, string $label, string $class = '' ): void {
		self::form_open( 'resolve', '', null, 'heimdb-inline' );
		echo '<input type="hidden" name="branch" value="' . esc_attr( $branch ) . '"><input type="hidden" name="table" value="' . esc_attr( $table ) . '"><input type="hidden" name="row" value="' . esc_attr( $row ) . '"><input type="hidden" name="side" value="' . esc_attr( $side ) . '">';
		echo '<button class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * 解決を記録したユーザーと時刻（HeimDB 0.2.15 から）。
	 */
	private static function resolved_by( array $r ): string {
		if ( empty( $r['resolved_by'] ) ) {
			return '';
		}
		/* translators: 1: database user, 2: time */
		return '<span class="heimdb-resolved-by">' . esc_html( sprintf( __( 'by %1$s, %2$s', 'heimdb' ), $r['resolved_by'], Time::display( (string) $r['resolved_at'] ) ) ) . '</span>';
	}

	/**
	 * 親の名前。ブランチのユーザーには分からない（ブランチの一覧を読めない）。
	 */
	private static function parent_label( array $b ): string {
		return null === $b['parent'] ? __( 'parent', 'heimdb' ) : (string) $b['parent'];
	}

	private static function undo_button( array $undo ): void {
		/* translators: 1: branch, 2: time, 3: tables */
		$confirm = sprintf( __( 'Restore %1$s to %2$s (%3$s)? Anything written to these tables since then is lost.', 'heimdb' ), $undo['branch'], Time::display( $undo['time'], true ), $undo['tables'] ?? __( 'all but comments and users', 'heimdb' ) );
		self::form_open( 'undo', self::confirm_attr( $confirm ), null, 'heimdb-inline' );
		foreach ( array( 'branch', 'time', 'tables' ) as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) ( $undo[ $field ] ?? '' ) ) . '">';
		}
		echo '<button class="button button-small">' . esc_html__( 'Undo', 'heimdb' ) . '</button></form>';
	}

	/**
	 * POST の form を開く（nonce、操作、戻り先）。
	 */
	private static function form_open( string $op, string $attrs = '', ?string $redirect = null, string $class = '' ): void {
		$redirect = $redirect ?? ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : admin_url() );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '" ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attrs is built by confirm_attr.
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( remove_query_arg( array( 'paged' ), $redirect ) ) . '">';
		wp_nonce_field( 'heimdb_' . $op );
	}

	private static function confirm_attr( string $message ): string {
		return 'data-heimdb-confirm="' . esc_attr( $message ) . '"';
	}

	private static function branch_select( string $name, string $selected, string $id = '' ): void {
		echo '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		$client = Client::instance();
		foreach ( $client->is_branch_user() ? array( Client::site_branch() ) : array_keys( $client->branches() ) as $b ) {
			echo '<option value="' . esc_attr( $b ) . '"' . selected( $b, $selected, false ) . '>' . esc_html( $b ) . '</option>';
		}
		echo '</select>';
	}

	private static function breadcrumbs( array $items, string $page = self::PAGE_BRANCHES ): void {
		$root = self::PAGE_TIME === $page ? __( 'HeimDB', 'heimdb' ) : __( 'Branches', 'heimdb' );
		echo '<p class="heimdb-breadcrumbs"><a href="' . esc_url( self::url( self::PAGE_BRANCHES ) ) . '">' . esc_html( $root ) . '</a>';
		foreach ( $items as $label => $url ) {
			echo ' › ' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $label ) . '</a>' : esc_html( (string) $label ) );
		}
		echo '</p>';
	}

	private static function pagination( int $total, int $paged ): void {
		$pages = (int) ceil( $total / self::ROWS_PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}

	public static function url( string $page, array $args = array() ): string {
		$args = array_map( fn( $v ) => rawurlencode( (string) $v ), array( 'page' => $page ) + $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * 行に、人の分かる名前を添える（記事のタイトル、オプションの名前など）。今のブランチの値から取る。
	 */
	private static function row_label( string $branch, string $table, string $id ): string {
		global $wpdb;
		static $cache = array();
		$key = "$branch|$table|$id";
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$label = '';
		// 大きな差分で 1 行ずつ読みに行かないように、数を絞る。
		if ( count( $cache ) >= 100 ) {
			return '';
		}
		$names = array(
			'posts'    => array( 'post_title', 'post_type' ),
			'terms'    => array( 'name', null ),
			'users'    => array( 'user_login', null ),
			'comments' => array( 'comment_author', null ),
			'postmeta' => array( 'meta_key', 'post_id' ),
			'usermeta' => array( 'meta_key', 'user_id' ),
			'termmeta' => array( 'meta_key', 'term_id' ),
		);
		$suffix = substr( $table, strlen( $wpdb->base_prefix ) );
		if ( 0 === strpos( $table, $wpdb->base_prefix ) && isset( $names[ $suffix ] ) && preg_match( '/^[A-Za-z_]+=\d+$/', $id ) ) {
			try {
				$row = Client::instance()->row( $branch, $table, $id );
				if ( ! $row ) {
					// 消した行は、親の今の値から取る。
					$parent = Client::instance()->branch( $branch )['parent'];
					$row    = $parent ? Client::instance()->row( $parent, $table, $id ) : null;
				}
				if ( $row ) {
					list( $main, $sub ) = $names[ $suffix ];
					$label              = (string) $row[ $main ];
					if ( $sub && isset( $row[ $sub ] ) ) {
						$label .= ' (' . $sub . ' ' . $row[ $sub ] . ')';
					}
					$label = '“' . wp_html_excerpt( $label, 60, '…' ) . '”';
				}
			} catch ( Error $e ) {
				$label = '';
			}
		}
		$cache[ $key ] = $label;
		return $label;
	}

	private static function status_badge( string $status ): string {
		$class = array(
			'changed'           => 'change',
			'merged'            => 'ok',
			'synced'            => 'ok',
			'restore rows'      => 'ok',
			'new table'         => 'insert',
			'schema differs'    => 'conflict',
			'excluded'          => 'muted',
			'not excluded'      => 'warning',
			'unchanged'         => 'muted',
			'not restored'      => 'warning',
			'drop'              => 'delete',
			'dropped on branch' => 'delete',
			'dropped on parent' => 'delete',
		);
		return '<span class="heimdb-badge heimdb-badge-' . esc_attr( $class[ $status ] ?? 'muted' ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * HeimDB の結果の状態の語（status、action の列）。語は HeimDB が変えないと約束しているので、表示のときに訳す。
	 * 知らない語はそのまま出す。
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'changed'                        => __( 'changed', 'heimdb' ),
			'merged'                         => __( 'merged', 'heimdb' ),
			'synced'                         => __( 'synced', 'heimdb' ),
			'restore rows'                   => __( 'restore rows', 'heimdb' ),
			'restore definition'             => __( 'restore definition', 'heimdb' ),
			'new table'                      => __( 'new table', 'heimdb' ),
			'schema differs'                 => __( 'schema differs', 'heimdb' ),
			'excluded'                       => __( 'excluded', 'heimdb' ),
			'not excluded'                   => __( 'not excluded', 'heimdb' ),
			'unchanged'                      => __( 'unchanged', 'heimdb' ),
			'not restored'                   => __( 'not restored', 'heimdb' ),
			'drop'                           => __( 'drop table', 'heimdb' ),
			'dropped on branch'              => __( 'dropped on branch', 'heimdb' ),
			'dropped on parent'              => __( 'dropped on parent', 'heimdb' ),
			'dropped on branch (not merged)' => __( 'dropped on branch (not merged)', 'heimdb' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * マージや復元の結果の列の見出し。
	 */
	private static function column_label( string $column ): string {
		$labels = array(
			'table_name' => __( 'Table', 'heimdb' ),
			'table'      => __( 'Table', 'heimdb' ),
			'index'      => __( 'Index', 'heimdb' ),
			'status'     => __( 'Status', 'heimdb' ),
			'action'     => __( 'Action', 'heimdb' ),
			'inserts'    => __( 'Inserts', 'heimdb' ),
			'updates'    => __( 'Updates', 'heimdb' ),
			'deletes'    => __( 'Deletes', 'heimdb' ),
			'skipped'    => __( 'Skipped', 'heimdb' ),
			'conflicts'  => __( 'Conflicts', 'heimdb' ),
			'resolved'   => __( 'Resolved', 'heimdb' ),
			'warnings'   => __( 'Warnings', 'heimdb' ),
			'detail'     => __( 'Detail', 'heimdb' ),
		);
		return $labels[ $column ] ?? $column;
	}

	/**
	 * 差分の行の判定。解決を記録した競合は、どちらを採るかを添える。
	 */
	private static function merge_badge( array $r ): string {
		$badge = self::action_badge( (string) $r['action'] );
		if ( 'conflict' === $r['action'] && null !== $r['resolution'] && 'stale' !== $r['resolution'] ) {
			/* translators: %s: side such as "keep branch" */
			$badge = '<span class="heimdb-badge heimdb-badge-' . esc_attr( $r['resolution'] ) . '">' . esc_html( sprintf( __( 'resolved: %s', 'heimdb' ), self::side_label( (string) $r['resolution'] ) ) ) . '</span>';
		}
		return $badge;
	}

	private static function action_badge( string $action ): string {
		return '<span class="heimdb-badge heimdb-badge-' . esc_attr( sanitize_html_class( $action ) ) . '">' . esc_html( self::action_label( $action ) ) . '</span>';
	}

	private static function action_label( string $action ): string {
		$labels = array(
			'insert'   => __( 'insert', 'heimdb' ),
			'update'   => __( 'update', 'heimdb' ),
			'delete'   => __( 'delete', 'heimdb' ),
			'skip'     => __( 'skip', 'heimdb' ),
			'conflict' => __( 'conflict', 'heimdb' ),
			'warning'  => __( 'warning', 'heimdb' ),
			'merge'    => __( 'merge', 'heimdb' ),
			'restore'  => __( 'restore', 'heimdb' ),
			'undo'     => __( 'undo', 'heimdb' ),
			// heimdb_changes の、テーブルごとの行と最後の行。
			'table created' => __( 'table created', 'heimdb' ),
			'table dropped' => __( 'table dropped', 'heimdb' ),
			'table changed' => __( 'table changed', 'heimdb' ),
			'not shown'     => __( 'not shown', 'heimdb' ),
		);
		return $labels[ $action ] ?? $action;
	}

	private static function change_label( string $change ): string {
		return 'none' === $change ? '—' : self::action_label( $change );
	}

	private static function side_label( string $side ): string {
		$labels = array(
			'branch' => __( 'keep branch', 'heimdb' ),
			'parent' => __( 'keep parent', 'heimdb' ),
			'none'   => __( 'undecided', 'heimdb' ),
			'stale'  => __( 'stale — decide again', 'heimdb' ),
		);
		return $labels[ $side ] ?? $side;
	}

	private static function limits_label( array $b ): string {
		$parts = array();
		if ( null !== $b['max_size_bytes'] ) {
			$parts[] = size_format( (int) $b['max_size_bytes'] );
		}
		if ( null !== $b['max_execution_ms'] ) {
			$parts[] = ( (int) $b['max_execution_ms'] / 1000 ) . 's';
		}
		return implode( ' / ', $parts );
	}

	private static function duration_input( int $ms ): string {
		if ( $ms >= 60000 && 0 === $ms % 60000 ) {
			return ( $ms / 60000 ) . 'm';
		}
		return 0 === $ms % 1000 ? ( $ms / 1000 ) . 's' : $ms . 'ms';
	}

	private static function size_input( int $bytes ): string {
		foreach ( array( 'T' => 4, 'G' => 3, 'M' => 2, 'K' => 1 ) as $unit => $pow ) {
			$n = 1024 ** $pow;
			if ( $bytes >= $n && 0 === $bytes % $n ) {
				return ( $bytes / $n ) . $unit;
			}
		}
		return (string) $bytes;
	}

	private static function num( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_numeric( $value ) && (string) (int) $value === (string) $value ) {
			return 0 === (int) $value ? '' : number_format_i18n( (int) $value );
		}
		return (string) $value;
	}

	private static function status_value( string $name, $value ): string {
		if ( null === $value ) {
			return '—';
		}
		if ( preg_match( '/_bytes$/', $name ) && is_numeric( $value ) ) {
			return size_format( (int) $value ) ?: '0 B';
		}
		if ( preg_match( '/(_at|_boundary|last_commit|_time)$/', $name ) && preg_match( '/^\d{4}-\d\d-\d\d /', (string) $value ) ) {
			return Time::display( (string) $value ) . ' (' . wp_timezone_string() . ')';
		}
		return (string) $value;
	}
}
