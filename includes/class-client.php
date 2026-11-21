<?php
namespace Heimdb;

defined( 'ABSPATH' ) || exit;

/**
 * HeimDB の手続き（`CALL heimdb_*`）を呼ぶ。
 *
 * 手続きは管理者（SUPER 権限）しか呼べないので、WordPress の接続（$wpdb、サイトのユーザー）とは別に、管理者の
 * 接続を開く。接続先は wp-config.php の定数で決める。
 *
 *     define( 'HEIMDB_ADMIN_USER', 'root' );
 *     define( 'HEIMDB_ADMIN_PASSWORD', '...' );
 *     define( 'HEIMDB_ADMIN_HOST', 'heimdb-host:3306' );   // 省けば DB_HOST
 *
 * HEIMDB_ADMIN_USER がなければ、DB_USER と DB_PASSWORD でつなぐ（wp-cli だけを root で動かす構成）。
 *
 * ステージングのサイトは、管理者の設定を持たず、ブランチだけの権限のユーザー（DB_USER）でつなぐ。HeimDB を
 * -branch-procedures（0.2.15 から）で動かしていれば、そのユーザーでも自分のブランチの差分、行を指定した解決、
 * 変更の一覧、復元を呼べる（is_branch_user）。ブランチの一覧、マージ、上限などは管理者だけ。
 *
 * 接続の time_zone は UTC にし、時刻はいつもオフセット付きで渡す（Time）。
 */
class Client {
	/** @var Client|null */
	private static $instance;

	/** @var \mysqli|null */
	private $mysqli;

	/** @var array<string,string[]> テーブルの列と主キー（`db.table` ごと）。 */
	private $schema = array();

	/** @var array<string,array<string,mixed>>|null ブランチの一覧（名前ごと）。書き換える手続きを呼べば捨てる。 */
	private $branches;

	/** @var bool|null 管理者（ブランチの一覧を呼べる）か。 */
	private $admin;

	/** @var Error|null 管理者でないと分かったときの、ブランチの一覧のエラー。 */
	private $denied;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 管理者の接続の設定が wp-config.php にあるか。なければサイトのユーザーでつなぐ（ふつうは手続きを拒否される）。
	 */
	public static function has_admin_credentials(): bool {
		return defined( 'HEIMDB_ADMIN_USER' );
	}

	/**
	 * 手続きを呼ぶユーザー。
	 */
	public static function admin_user(): string {
		return self::has_admin_credentials() ? (string) HEIMDB_ADMIN_USER : (string) DB_USER;
	}

	/**
	 * このサイトがつないでいるブランチ（DB_NAME が `wordpress@staging` なら `staging`）。
	 */
	public static function site_branch(): string {
		$pos = strpos( (string) DB_NAME, '@' );
		return false === $pos ? 'main' : substr( (string) DB_NAME, $pos + 1 );
	}

	/**
	 * 管理者の接続。初めて呼ばれたときにつなぐ。
	 *
	 * @throws Error つなげない。
	 */
	public function connection(): \mysqli {
		if ( $this->mysqli ) {
			return $this->mysqli;
		}
		global $wpdb;

		$host     = defined( 'HEIMDB_ADMIN_HOST' ) ? (string) HEIMDB_ADMIN_HOST : (string) DB_HOST;
		$user     = self::admin_user();
		$password = self::has_admin_credentials()
			? ( defined( 'HEIMDB_ADMIN_PASSWORD' ) ? (string) HEIMDB_ADMIN_PASSWORD : '' )
			: (string) DB_PASSWORD;

		$parsed = $wpdb->parse_db_host( $host );
		if ( ! $parsed ) {
			/* translators: %s: host name */
			throw new Error( sprintf( __( 'Cannot read the HeimDB host "%s".', 'heimdb' ), $host ) );
		}
		list( $hostname, $port, $socket, $is_ipv6 ) = $parsed;
		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$hostname = "[$hostname]";
		}

		$mysqli = mysqli_init();
		$mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
		$flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;
		try {
			// 接続中のデータベースは、サイトのもの（ブランチのユーザーの手続きは、接続中のブランチだけを対象にする）。
			$ok = @$mysqli->real_connect( $hostname, $user, $password, (string) DB_NAME, $port ? (int) $port : null, $socket, $flags );
		} catch ( \mysqli_sql_exception $e ) {
			throw $this->connect_error( $e->getMessage(), (int) $e->getCode() );
		}
		if ( ! $ok ) {
			throw $this->connect_error( (string) $mysqli->connect_error, (int) $mysqli->connect_errno );
		}
		$mysqli->set_charset( 'utf8mb4' );
		// 結果の時刻は UTC として読む（Time::parse）ので、time_zone を設定できない接続は使わない。
		try {
			$ok = $mysqli->query( "SET time_zone = '+00:00'" );
		} catch ( \mysqli_sql_exception $e ) {
			throw new Error( $e->getMessage(), (int) $e->getCode() );
		}
		if ( ! $ok ) {
			throw new Error( $mysqli->error, $mysqli->errno );
		}
		$this->mysqli = $mysqli;
		return $mysqli;
	}

	private function connect_error( string $message, int $code ): Error {
		/* translators: 1: database user, 2: error message */
		return new Error( sprintf( __( 'Cannot connect to HeimDB as %1$s: %2$s', 'heimdb' ), self::admin_user(), $message ), $code );
	}

	/**
	 * 文を実行して、行（列名をキーにした配列）を返す。結果の集合が複数あれば最初のもの。
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws Error HeimDB のエラー。
	 */
	public function query( string $sql ): array {
		$mysqli = $this->connection();
		try {
			if ( ! $mysqli->multi_query( $sql ) ) {
				throw new Error( $mysqli->error, $mysqli->errno );
			}
			$rows  = null;
			do {
				$result = $mysqli->store_result();
				if ( $result ) {
					if ( null === $rows ) {
						$rows = $result->fetch_all( MYSQLI_ASSOC );
					}
					$result->free();
				} elseif ( $mysqli->errno ) {
					throw new Error( $mysqli->error, $mysqli->errno );
				}
			} while ( $mysqli->more_results() && $mysqli->next_result() );
			if ( $mysqli->errno ) {
				throw new Error( $mysqli->error, $mysqli->errno );
			}
		} catch ( \mysqli_sql_exception $e ) {
			throw Error::from_mysqli( $e );
		}
		return $rows ?? array();
	}

	/**
	 * 手続きを呼ぶ。null の引数から後ろは省く（省略できる引数）。
	 *
	 * @param string             $procedure `heimdb_` の後ろの名前（`branch_list` など）。
	 * @param array<int,mixed>   $args
	 * @return array<int,array<string,mixed>>
	 */
	public function call( string $procedure, array $args = array() ): array {
		if ( ! preg_match( '/^[a-z_]+$/', $procedure ) ) {
			throw new Error( 'invalid procedure name' );
		}
		$sql = array();
		foreach ( $args as $arg ) {
			if ( null === $arg ) {
				break;
			}
			$sql[] = $this->quote( (string) $arg );
		}
		return $this->query( 'CALL heimdb_' . $procedure . '(' . implode( ', ', $sql ) . ')' );
	}

	public function quote( string $value ): string {
		return "'" . $this->connection()->real_escape_string( $value ) . "'";
	}

	public static function quote_identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	// ---------------------------------------------------------------- ブランチ

	/**
	 * @return array<string,array<string,mixed>> ブランチの名前ごとの行（heimdb_branch_list の列）。
	 */
	public function branches(): array {
		if ( null === $this->branches ) {
			$this->branches = array();
			foreach ( $this->call( 'branch_list' ) as $row ) {
				$this->branches[ $row['branch'] ] = $row;
			}
		}
		return $this->branches;
	}

	/**
	 * ブランチのユーザーは一覧を読めないので、このサイトのブランチだけを、分かる列（名前とデータベース）で返す。
	 *
	 * @return array<string,mixed>
	 * @throws Error ブランチがない。
	 */
	public function branch( string $name ): array {
		if ( $this->is_branch_user() ) {
			if ( self::site_branch() !== $name ) {
				/* translators: %s: branch */
				throw new Error( sprintf( __( 'This site connects as a branch user, so it can use only its own branch %s.', 'heimdb' ), self::site_branch() ), Error::BRANCH_NOT_FOUND );
			}
			return array(
				'branch'           => $name,
				'parent'           => null,
				'forked_at'        => null,
				'database'         => (string) DB_NAME,
				'size_bytes'       => null,
				'max_size_bytes'   => null,
				'max_execution_ms' => null,
			);
		}
		$branches = $this->branches();
		if ( ! isset( $branches[ $name ] ) ) {
			/* translators: %s: branch name */
			throw new Error( sprintf( __( 'Branch "%s" not found.', 'heimdb' ), $name ), Error::BRANCH_NOT_FOUND );
		}
		return $branches[ $name ];
	}

	/**
	 * ブランチの名前の規則（英小文字、数字、`_`、`-`）。
	 */
	public static function is_valid_branch_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z0-9_-]+$/', $name );
	}

	/**
	 * @param string|null $parent 省けば main。
	 * @param string|null $at     この時点の親から作る（Time::to_db の形）。
	 * @return array<string,mixed> 作ったブランチ。
	 */
	public function branch_create( string $name, ?string $parent = null, ?string $at = null ): array {
		if ( null !== $at && null === $parent ) {
			$parent = 'main';
		}
		$rows           = $this->call( 'branch_create', array( $name, $parent, $at ) );
		$this->branches = null;
		/**
		 * ブランチを作った後。
		 *
		 * @param string               $name
		 * @param array<string,mixed>  $branch heimdb_branch_list の行。
		 */
		do_action( 'heimdb_branch_created', $name, $rows[0] ?? array() );
		return $rows[0] ?? array();
	}

	public function branch_delete( string $name ): void {
		if ( self::site_branch() === $name ) {
			/* translators: %s: branch */
			throw new Error( sprintf( __( 'This site runs on branch %s, so it cannot delete it. Delete it from the production site or with wp-cli there.', 'heimdb' ), $name ) );
		}
		$this->call( 'branch_delete', array( $name ) );
		$this->branches = null;
		/** ブランチを消した後。 */
		do_action( 'heimdb_branch_deleted', $name );
	}

	/**
	 * @param string $size `2G` など（`0` で外す）。
	 * @param string $time `30s` など（`0` で外す）。
	 * @return array<string,mixed>
	 */
	public function branch_limit( string $name, string $size, string $time ): array {
		$rows           = $this->call( 'branch_limit', array( $name, $size, $time ) );
		$this->branches = null;
		return $rows[0] ?? array();
	}

	/**
	 * @param string|null $table 省けば要約。テーブルの名前か `*` なら行ごと。
	 * @return array<int,array<string,mixed>>
	 */
	public function branch_diff( string $name, ?string $table = null ): array {
		return $this->call( 'branch_diff', array( $name, $table ) );
	}

	/**
	 * 解決していない、親の側でしか解決できない競合の行（ワイルドカードの 'branch' はこれに当たらない）。
	 *
	 * @param string $table テーブル、または `*`。
	 * @return array<int,array<string,mixed>>
	 */
	public function parent_only_conflicts( string $name, string $table = '*' ): array {
		return array_values(
			array_filter(
				$this->branch_diff( $name, $table ),
				fn( $r ) => 'conflict' === $r['action'] && null === $r['resolution'] && null !== self::parent_only( $r['detail'] )
			)
		);
	}

	/**
	 * @param string $side `branch`、`parent`、`none`。
	 */
	public function branch_resolve( string $name, string $table, string $row, string $side ): array {
		return $this->call( 'branch_resolve', array( $name, $table, $row, $side ) );
	}

	/**
	 * マージする。結果の読み方は HeimDB の docs/procedures.md の「マージの結果をプラグインで読む」。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function branch_merge( string $name, bool $dry_run = false ): array {
		/**
		 * マージの前（空打ちを含む）。
		 *
		 * @param string $name
		 * @param bool   $dry_run
		 */
		do_action( 'heimdb_before_merge', $name, $dry_run );
		$rows = $this->call( 'branch_merge', array( $name, $dry_run ? 'dry_run' : null ) );
		if ( ! $dry_run ) {
			$this->branches = null;
			/**
			 * マージが成功した後。
			 *
			 * `status` が `merged` の行が変えたテーブル（キャッシュのパージの範囲）、`excluded` の行がマージから外した
			 * テーブル。時間のかかる処理は、ここで待たずに予約イベントに回す。
			 *
			 * @param string                         $name
			 * @param array<int,array<string,mixed>> $rows heimdb_branch_merge の結果。
			 * @param array<string,string>|null      $undo 戻すための heimdb_restore の引数（self::undo_args）。
			 */
			do_action( 'heimdb_after_merge', $name, $rows, self::undo_args( $rows ) );
		}
		return $rows;
	}

	/**
	 * 親の変更をブランチに取り込む（HeimDB 0.2.33 から）。ブランチのまだマージしていない変更は残る。
	 *
	 * 競合（両側が同じ列を変えた行など）があれば、側を渡さないと 51004 で拒否する。側はすべての競合に効く。
	 * 結果の形はマージと同じで、最後の `undo` の行が取り込みを戻す heimdb_restore。
	 *
	 * @param string|null $side 競合した列で採る側（`branch` か `parent`）。null なら競合を拒否する。
	 * @return array<int,array<string,mixed>>
	 */
	public function branch_sync( string $name, ?string $side = null, bool $dry_run = false ): array {
		if ( null !== $side && ! in_array( $side, array( 'branch', 'parent' ), true ) ) {
			throw new Error( __( 'Choose branch or parent for the conflicts.', 'heimdb' ) );
		}
		/**
		 * 取り込みの前（空打ちを含む）。
		 *
		 * @param string      $name
		 * @param string|null $side
		 * @param bool        $dry_run
		 */
		do_action( 'heimdb_before_sync', $name, $side, $dry_run );
		// 側と 'dry_run' は、どちらも省ける（側を省いて 'dry_run' だけを渡せる）。
		$rows = $this->call( 'branch_sync', array_values( array_filter( array( $name, $side, $dry_run ? 'dry_run' : null ), 'is_string' ) ) );
		if ( ! $dry_run ) {
			$this->branches = null;
			/**
			 * 取り込みが成功した後。`status` が `synced` の行が、ブランチで変えたテーブル。
			 *
			 * @param string                         $name
			 * @param array<int,array<string,mixed>> $rows heimdb_branch_sync の結果。
			 * @param array<string,string>|null      $undo 戻すための heimdb_restore の引数（self::undo_args）。
			 */
			do_action( 'heimdb_after_sync', $name, $rows, self::undo_args( $rows ) );
		}
		return $rows;
	}

	/**
	 * ブランチだけの権限のユーザー（ステージングのサイトがつなぐユーザー）を作り、ブランチのデータベースの
	 * すべての権限を与える。ユーザーがいれば、パスワードと同時接続の上限は、渡したときだけ変える。
	 *
	 * このサイトの DB_USER と、手続きを呼ぶ管理者のユーザーは拒否する（パスワードや上限を変えると、このサイトが落ちる）。
	 *
	 * @param int|null $max_connections 同時接続の上限（0 なら上限なし）。null なら、作るときは 20、いるユーザーは変えない。
	 * @return array{created:bool,password:?string} 作ったか、作ったパスワード。
	 */
	public function grant_branch_user( string $branch, string $user, string $host = '%', ?string $password = null, ?int $max_connections = null ): array {
		if ( 'main' === $branch ) {
			throw new Error( __( 'Use the site user for main.', 'heimdb' ) );
		}
		if ( '' === $user ) {
			throw new Error( __( 'Enter the database user.', 'heimdb' ) );
		}
		if ( in_array( strtolower( $user ), array_map( 'strtolower', array( (string) DB_USER, self::admin_user() ) ), true ) ) {
			/* translators: %s: database user */
			throw new Error( sprintf( __( '%s is the user this site or this plugin connects with. Choose another user for the branch site.', 'heimdb' ), $user ) );
		}
		$database  = (string) $this->branch( $branch )['database'];
		$account   = $this->quote( $user ) . '@' . $this->quote( $host );
		$generated = null;
		$exists    = (bool) $this->query( 'SELECT 1 FROM mysql.user WHERE user = ' . $this->quote( $user ) . ' AND host = ' . $this->quote( $host ) );
		if ( null === $password || '' === $password ) {
			$password = null;
		}
		if ( ! $exists ) {
			if ( null === $password ) {
				$password  = wp_generate_password( 24, false );
				$generated = $password;
			}
			$max = max( 0, $max_connections ?? 20 );
			$this->query( "CREATE USER $account IDENTIFIED BY " . $this->quote( $password ) . " WITH MAX_USER_CONNECTIONS $max" );
		} else {
			$set = array();
			if ( null !== $password ) {
				$set[] = 'IDENTIFIED BY ' . $this->quote( $password );
			}
			if ( null !== $max_connections ) {
				$set[] = 'WITH MAX_USER_CONNECTIONS ' . max( 0, $max_connections );
			}
			if ( $set ) {
				$this->query( "ALTER USER $account " . implode( ' ', $set ) );
			}
		}
		$this->query( 'GRANT ALL ON ' . self::quote_identifier( $database ) . ".* TO $account" );
		return array(
			'created'  => ! $exists,
			'password' => $generated,
		);
	}

	// ---------------------------------------------------------------- マージから外す表

	/** @return string[] */
	public function merge_excludes(): array {
		return array_column( $this->call( 'merge_excludes' ), 'pattern' );
	}

	/** @return string[] 足した後の一覧。 */
	public function merge_exclude( string $pattern ): array {
		return array_column( $this->call( 'merge_exclude', array( $pattern ) ), 'pattern' );
	}

	/** @return string[] 消した後の一覧。 */
	public function merge_include( string $pattern ): array {
		return array_column( $this->call( 'merge_include', array( $pattern ) ), 'pattern' );
	}

	// ---------------------------------------------------------------- タイムトラベルと復元

	/**
	 * @param string      $at     Time::to_db の形。
	 * @param string|null $tables カンマ区切り。省けばすべて。
	 */
	public function changes( string $branch, string $at, ?string $tables = null ): array {
		return $this->call( 'changes', array( $branch, $at, self::empty_to_null( $tables ) ) );
	}

	/**
	 * @param string|null $tables カンマ区切りか `*`。省けばコメントとユーザー以外。
	 */
	public function restore_preview( string $branch, string $at, ?string $tables = null ): array {
		return $this->call( 'restore_preview', array( $branch, $at, self::empty_to_null( $tables ) ) );
	}

	public function restore( string $branch, string $at, ?string $tables = null ): array {
		$tables = self::empty_to_null( $tables );
		/**
		 * 復元の前（マージの取り消しを含む）。
		 *
		 * @param string      $branch
		 * @param string      $at
		 * @param string|null $tables
		 */
		do_action( 'heimdb_before_restore', $branch, $at, $tables );
		$rows           = $this->call( 'restore', array( $branch, $at, $tables ) );
		$this->branches = null;
		/**
		 * 復元が成功した後。`action` が `restore rows` などの行が戻したテーブル。
		 *
		 * @param string                         $branch
		 * @param string                         $at
		 * @param string|null                    $tables
		 * @param array<int,array<string,mixed>> $rows
		 */
		do_action( 'heimdb_after_restore', $branch, $at, $tables, $rows );
		return $rows;
	}

	/**
	 * マージと復元の結果の `undo` の行から、戻すための heimdb_restore の引数を取り出す。
	 *
	 * @return array{branch:string,time:string,tables:?string}|null
	 */
	public static function undo_args( array $rows ): ?array {
		foreach ( $rows as $row ) {
			if ( '*' !== ( $row['table_name'] ?? '' ) ) {
				continue;
			}
			$status = $row['status'] ?? $row['action'] ?? '';
			if ( 'undo' !== $status ) {
				continue;
			}
			$call = self::restore_call( (string) $row['detail'] );
			if ( $call ) {
				return $call;
			}
		}
		return null;
	}

	/**
	 * 表を選んだ復元の下見や結果で、組になる表（wp_posts と wp_postmeta など）の片方だけを戻すときに
	 * HeimDB（0.2.14 から）が勧める、まとめて戻す heimdb_restore の引数。表は、勧められたものをすべて合わせる。
	 *
	 * @return array{branch:string,time:string,tables:string}|null
	 */
	public static function restore_suggestion( array $rows ): ?array {
		$found  = null;
		$tables = array();
		foreach ( $rows as $row ) {
			// `*` の undo の行と、戻さない表の行（ユーザーも戻すなら `'*'` を勧める）は、組の表の提案ではない。
			if ( '*' === ( $row['table_name'] ?? '' ) || 'not restored' === ( $row['action'] ?? '' ) ) {
				continue;
			}
			$detail = (string) ( $row['detail'] ?? '' );
			$pos    = strpos( $detail, 'to restore them together: ' );
			if ( false === $pos ) {
				continue;
			}
			$call = self::restore_call( substr( $detail, $pos ) );
			if ( ! $call || null === $call['tables'] || '*' === trim( $call['tables'] ) ) {
				continue;
			}
			$found  = $found ?? $call;
			$tables = array_merge( $tables, array_map( 'trim', explode( ',', $call['tables'] ) ) );
		}
		if ( ! $found ) {
			return null;
		}
		$found['tables'] = implode( ',', array_values( array_unique( $tables ) ) );
		return $found;
	}

	/**
	 * 文の中の `CALL heimdb_restore('branch', 'time'[, 'tables'])` の引数。
	 *
	 * @return array{branch:string,time:string,tables:?string}|null
	 */
	private static function restore_call( string $text ): ?array {
		$s = "'((?:[^'\\\\]|\\\\.)*)'";
		if ( ! preg_match( "/CALL heimdb_restore\\($s, $s(?:, $s)?\\)/", $text, $m ) ) {
			return null;
		}
		return array(
			'branch' => stripslashes( $m[1] ),
			'time'   => stripslashes( $m[2] ),
			'tables' => isset( $m[3] ) ? stripslashes( $m[3] ) : null,
		);
	}

	/**
	 * 差分の行が、親の側でしか解決できない競合か（HeimDB 0.2.13 から。同じ id の別の行、親にいない投稿者など）。
	 *
	 * @return string|null その理由。
	 */
	public static function parent_only( ?string $detail ): ?string {
		if ( null !== $detail && preg_match( "/only 'parent' can resolve this: ([^;)]+)/", $detail, $m ) ) {
			return trim( $m[1] );
		}
		return null;
	}

	/**
	 * 両側で足された同じ行（自然キーが同じ）の、親の側の行の名前。HeimDB の detail から読む。
	 *
	 * @return string|null `ID=4` など。そういう行でなければ null。
	 */
	public static function parent_duplicate( ?string $detail ): ?string {
		if ( null !== $detail && preg_match( "/(?:was also added on the parent as|same as the parent's) ([^\\s;)]+)/", $detail, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * 主キーのない表の行の名前か（HeimDB 0.2.20 から。`__heimdb_row_id=N`）。見えない行 ID は SQL で引けないので、1 行を読めない。
	 */
	public static function is_hidden_row( string $id ): bool {
		return (bool) preg_match( '/^__heimdb_row_id=\d+$/', $id );
	}

	/**
	 * ブランチのテーブルの 1 行を、ある時点（null なら今）で読む。
	 *
	 * @param string      $id 差分や変更の一覧の `id` の形（`ID=12`、`option_name=blogname`）。
	 * @param string|null $at Time::to_db の形。
	 * @return array<string,mixed>|null その時点に行がなければ null。
	 * @throws Error 行の名前が読めない、主キーのない表の行、テーブルがない。
	 */
	public function row( string $branch, string $table, string $id, ?string $at = null ): ?array {
		if ( self::is_hidden_row( $id ) ) {
			throw new Error( __( 'This table has no primary key, so its rows are named by a hidden row ID that SQL cannot look up. A single row cannot be shown.', 'heimdb' ) );
		}
		$database = (string) $this->branch( $branch )['database'];
		$columns  = $this->columns( $database, $table );
		$where    = array();
		foreach ( self::parse_row_id( $id, $columns, $this->primary_key( $database, $table ) ) as $column => $value ) {
			$where[] = self::quote_identifier( $column ) . ' = ' . $this->quote( $value );
		}
		$sql = 'SELECT * FROM ' . self::quote_identifier( $database ) . '.' . self::quote_identifier( $table )
			. ( null !== $at ? ' AS OF ' . $this->quote( $at ) : '' )
			. ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1';
		try {
			$rows = $this->query( $sql );
		} catch ( Error $e ) {
			// その時点にテーブルがなかった。
			if ( 1146 === $e->getCode() && null !== $at ) {
				return null;
			}
			throw $e;
		}
		return $rows[0] ?? null;
	}

	/**
	 * @return string[] テーブルの列の名前（定義の順）。
	 */
	public function columns( string $database, string $table ): array {
		$key = "c|$database|$table";
		if ( isset( $this->schema[ $key ] ) ) {
			return $this->schema[ $key ];
		}
		$rows = $this->query(
			'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ' . $this->quote( $database )
			. ' AND table_name = ' . $this->quote( $table ) . ' ORDER BY ordinal_position'
		);
		if ( ! $rows ) {
			/* translators: 1: table, 2: database */
			throw new Error( sprintf( __( 'Table %1$s not found in %2$s.', 'heimdb' ), $table, $database ), 1146 );
		}
		return $this->schema[ $key ] = array_column( $rows, 'c' );
	}

	/**
	 * @return string[] 主キーの列。
	 */
	public function primary_key( string $database, string $table ): array {
		$key = "k|$database|$table";
		if ( isset( $this->schema[ $key ] ) ) {
			return $this->schema[ $key ];
		}
		$rows = $this->query(
			'SELECT column_name AS c FROM information_schema.statistics WHERE table_schema = ' . $this->quote( $database )
			. ' AND table_name = ' . $this->quote( $table ) . " AND index_name = 'PRIMARY' ORDER BY seq_in_index"
		);
		return $this->schema[ $key ] = array_column( $rows, 'c' );
	}

	/**
	 * 行の名前（`ID=12`、複数列の主キーなら `a=1,b=x`、wp_options は `option_name=blogname`）を、列と値にする。
	 * 値は引用されていないので、主キーの次の列の名前（`,b=`）を手がかりに区切る。主キーの最後の列と、主キーでない列
	 * （`option_name`）の値は、残りすべて（値に `,b=` があっても区切らない）。
	 *
	 * @param string[] $columns     テーブルの列。
	 * @param string[] $key_columns 主キーの列（区切りの手がかり）。
	 * @return array<string,string>
	 * @throws Error 読めない。
	 */
	public static function parse_row_id( string $id, array $columns, array $key_columns = array() ): array {
		$lower = array_map( 'strtolower', $columns );
		$out   = array();
		$rest  = $id;
		while ( '' !== $rest ) {
			$eq = strpos( $rest, '=' );
			if ( false === $eq ) {
				break;
			}
			$column = substr( $rest, 0, $eq );
			$index  = array_search( strtolower( $column ), $lower, true );
			if ( false === $index ) {
				break;
			}
			$rest = substr( $rest, $eq + 1 );
			// 値の終わりは、主キーでこの列の次にある列の「,<列>=」。
			$key = array_search( strtolower( $column ), array_map( 'strtolower', $key_columns ), true );
			$end = strlen( $rest );
			if ( false !== $key && isset( $key_columns[ $key + 1 ] ) ) {
				$pos = stripos( $rest, ',' . $key_columns[ $key + 1 ] . '=' );
				if ( false !== $pos ) {
					$end = $pos;
				}
			}
			$out[ $columns[ $index ] ] = substr( $rest, 0, $end );
			$rest                      = (string) substr( $rest, $end + 1 );
		}
		if ( ! $out || '' !== $rest ) {
			/* translators: %s: row id such as ID=12 */
			throw new Error( sprintf( __( 'Cannot read the row id "%s".', 'heimdb' ), $id ) );
		}
		return $out;
	}

	// ---------------------------------------------------------------- 運用

	/** @return array<string,mixed> 項目ごとの値。 */
	public function status(): array {
		return array_column( $this->call( 'status' ), 'value', 'name' );
	}

	/** @return array<string,mixed> */
	public function backup( ?string $name = null ): array {
		$rows = $this->call( 'backup', array( self::empty_to_null( $name ) ) );
		return $rows[0] ?? array();
	}

	/** @return array<string,mixed> */
	public function gc(): array {
		$rows = $this->call( 'gc' );
		return $rows[0] ?? array();
	}

	/**
	 * 古い HeimDB（DECIMAL、FLOAT、DOUBLE、ENUM の列は 0.2.38 以前、SET と BIT の列は 0.2.40 以前）で作った、
	 * エントリのないインデックスを作り直す（HeimDB 0.2.39 から）。手続きは接続中のデータベースのブランチに効くので、
	 * 別のブランチなら、その間だけそのデータベースに切り替える。main は 0.2.42 から起動の後に裏で作り直される。
	 *
	 * @param string|null $branch 省けばこのサイトのブランチ。
	 * @return array<int,array<string,mixed>> 作り直したインデックス（`table`、`index`）。なければ空。
	 */
	public function rebuild_indexes( ?string $branch = null ): array {
		$branch   = $branch ?? self::site_branch();
		$database = (string) $this->branch( $branch )['database'];
		$switch   = (string) DB_NAME !== $database;
		try {
			if ( $switch ) {
				$this->query( 'USE ' . self::quote_identifier( $database ) );
			}
			return $this->call( 'rebuild_indexes' );
		} finally {
			if ( $switch ) {
				$this->query( 'USE ' . self::quote_identifier( (string) DB_NAME ) );
			}
		}
	}

	/**
	 * 管理者（SUPER 権限）でつないでいるか。ブランチの一覧を呼んで確かめる（拒否されたら管理者でない）。
	 *
	 * @throws Error つなげないなど、拒否された以外のエラー。
	 */
	public function is_admin(): bool {
		if ( null === $this->admin ) {
			try {
				$this->branches();
				$this->admin = true;
			} catch ( Error $e ) {
				if ( ! self::is_denied( $e ) ) {
					throw $e;
				}
				$this->admin  = false;
				$this->denied = $e;
			}
		}
		return $this->admin;
	}

	/**
	 * ブランチだけの権限のユーザーとして手続きを呼ぶか（管理者でなく、このサイトがブランチにつないでいる）。
	 */
	public function is_branch_user(): bool {
		return 'main' !== self::site_branch() && ! $this->is_admin();
	}

	/**
	 * 管理者だけの手続きを拒否されたエラーか。
	 */
	public static function is_denied( Error $e ): bool {
		return false !== stripos( $e->getMessage(), 'command denied' );
	}

	/**
	 * 接続が使えるか（つながり、手続きを呼べる）。ブランチのユーザーなら、自分のブランチの手続きを呼べるか
	 * （HeimDB の -branch-procedures）を、今からの変更の一覧で確かめる。
	 *
	 * @return true|Error
	 */
	public function check() {
		try {
			if ( $this->is_admin() ) {
				return true;
			}
			if ( 'main' === self::site_branch() ) {
				return $this->denied;
			}
			$this->changes( self::site_branch(), Time::to_db( 'now' ) );
			return true;
		} catch ( Error $e ) {
			if ( self::is_denied( $e ) ) {
				/* translators: %s: database user */
				return new Error( sprintf( __( 'This site connects to HeimDB as %s, a user of this branch only. To see the diff, resolve conflicts and restore from this site, start HeimDB 0.2.15 or later with -branch-procedures. Merging and managing branches are done from the production site.', 'heimdb' ), self::admin_user() ), $e->getCode() );
			}
			return $e;
		}
	}

	private static function empty_to_null( ?string $value ): ?string {
		return null === $value || '' === trim( $value ) ? null : trim( $value );
	}
}
