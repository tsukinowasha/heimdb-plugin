<?php
namespace Heimdb;

defined( 'ABSPATH' ) || exit;

/**
 * 時刻の変換。
 *
 * HeimDB には、いつもオフセット付きの UTC（`2026-10-10 12:31:10.396594+00:00`）で渡す。セッションの time_zone にも
 * サイトの設定にもよらず、その時刻そのものとして読まれる（夏時間の境目でもずれない）。
 */
class Time {
	/**
	 * 人の書いた時刻を、HeimDB に渡す形にする。
	 *
	 * オフセットのない時刻はサイトのタイムゾーン（設定 > 一般）の時刻として読む。`2 hours ago`、`yesterday 18:00`
	 * のような相対の書き方も、PHP の strtotime と同じく受け付ける。
	 *
	 * @throws Error 読めない時刻。
	 */
	public static function to_db( string $input ): string {
		$input = trim( $input );
		if ( '' === $input ) {
			throw new Error( __( 'Time is empty.', 'heimdb' ) );
		}
		// <input type="datetime-local"> は `2026-10-18T09:00` の形で送る。
		$input = preg_replace( '/^(\d{4}-\d\d-\d\d)T(\d\d:\d\d)/', '$1 $2', $input );
		try {
			$t = new \DateTimeImmutable( $input, wp_timezone() );
		} catch ( \Exception $e ) {
			/* translators: %s: the time the user typed */
			throw new Error( sprintf( __( 'Cannot read the time "%s".', 'heimdb' ), $input ) );
		}
		return $t->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.uP' );
	}

	/**
	 * HeimDB が返した時刻（オフセット付き、またはセッションの time_zone である UTC）を、サイトのタイムゾーンで表示する。
	 */
	public static function display( ?string $db_time, bool $with_micro = false ): string {
		if ( null === $db_time || '' === $db_time ) {
			return '';
		}
		$t = self::parse( $db_time );
		if ( ! $t ) {
			return $db_time;
		}
		$with_micro = $with_micro && '000000' !== $t->format( 'u' );
		return $t->setTimezone( wp_timezone() )->format( $with_micro ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s' );
	}

	/**
	 * HeimDB の時刻を、<input type="datetime-local"> に入れる値（サイトのタイムゾーン、秒まで）にする。
	 */
	public static function to_input( ?string $db_time ): string {
		$t = $db_time ? self::parse( $db_time ) : new \DateTimeImmutable( 'now', wp_timezone() );
		return $t ? $t->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:s' ) : '';
	}

	/**
	 * 結果の時刻を読む。オフセットがなければ UTC（このプラグインは接続の time_zone を UTC にする）。
	 */
	public static function parse( string $db_time ): ?\DateTimeImmutable {
		try {
			return new \DateTimeImmutable( $db_time, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
