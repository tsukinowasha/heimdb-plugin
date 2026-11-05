<?php
namespace Heimdb;

defined( 'ABSPATH' ) || exit;

/**
 * HeimDB が返したエラー、またはつなげなかったこと。
 *
 * マージ、取り込み、差分、解決の記録、除外の追加を断るときは、HeimDB はエラー番号（51001〜）と理由の語
 * （`HeimDB: merge refused (<語>): ...`。取り込みは `HeimDB: sync refused (<語>): ...`）を付ける。語と番号は変わらないので、分岐にはこちらを使う。
 */
class Error extends \RuntimeException {
	const BRANCH_NOT_FOUND     = 51001;
	const NO_PARENT            = 51002;
	const IN_TRANSACTION       = 51003;
	const UNRESOLVED_CONFLICTS = 51004;
	const DB_VERSION_DIFFERS   = 51005;
	const AMBIGUOUS_PREFIX     = 51006;
	const PARENT_CHANGED       = 51007;
	const READ_ONLY_REPLICA    = 51008;
	const CORE_TABLE           = 51009;
	const SCHEMA_DIFFERS       = 51010;

	/** @var string|null 理由の語（`unresolved_conflicts` など）。語のないエラーなら null。 */
	public $reason;

	public function __construct( string $message, int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		if ( preg_match( '/(?:merge|sync) refused \(([a-z_]+)\)/', $message, $m ) ) {
			$this->reason = $m[1];
		}
	}

	public static function from_mysqli( \mysqli_sql_exception $e ): self {
		return new self( $e->getMessage(), (int) $e->getCode(), $e );
	}
}
