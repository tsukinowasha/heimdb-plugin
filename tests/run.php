<?php
// WordPress なしで動く部分（行の名前、undo の文の読み取り、時刻の変換）のテスト。php tests/run.php
define( 'ABSPATH', __DIR__ );
function __( $s ) { return $s; }
function wp_timezone() { return new DateTimeZone( 'Asia/Tokyo' ); } // サイトのタイムゾーン
require __DIR__ . '/../includes/class-error.php';
require __DIR__ . '/../includes/class-time.php';
require __DIR__ . '/../includes/class-client.php';

use Heimdb\Client;
use Heimdb\Error;
use Heimdb\Time;

$failures = 0;
function check( $name, $got, $want ) {
	global $failures;
	if ( $got !== $want ) {
		$failures++;
		echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
	} else {
		echo "ok   $name\n";
	}
}

$posts = array( 'ID', 'post_title', 'post_content' );
check( 'single key', Client::parse_row_id( 'ID=12', $posts, array( 'ID' ) ), array( 'ID' => '12' ) );
check( 'case-insensitive column', Client::parse_row_id( 'id=12', $posts, array( 'ID' ) ), array( 'ID' => '12' ) );
$options = array( 'option_id', 'option_name', 'option_value', 'autoload' );
check( 'wp_options by option_name', Client::parse_row_id( 'option_name=blogname', $options, array( 'option_id' ) ), array( 'option_name' => 'blogname' ) );
check( 'value with = and ,', Client::parse_row_id( 'option_name=a=b,c', $options, array( 'option_id' ) ), array( 'option_name' => 'a=b,c' ) );
$multi = array( 'a', 'b', 'v' );
check( 'two-column key', Client::parse_row_id( 'a=1,b=x,y', $multi, array( 'a', 'b' ) ), array( 'a' => '1', 'b' => 'x,y' ) );
check( 'last key column keeps ",a="', Client::parse_row_id( 'a=1,b=x,a=2', $multi, array( 'a', 'b' ) ), array( 'a' => '1', 'b' => 'x,a=2' ) );
check( 'non-key column keeps ",<key>="', Client::parse_row_id( 'option_name=x,option_id=3', $options, array( 'option_id' ) ), array( 'option_name' => 'x,option_id=3' ) );
foreach ( array( 'nope', 'ID', 'missing=1', '' ) as $bad ) {
	try {
		Client::parse_row_id( $bad, $posts, array( 'ID' ) );
		check( "reject '$bad'", 'accepted', 'Error' );
	} catch ( Error $e ) {
		check( "reject '$bad'", 'Error', 'Error' );
	}
}

$merge = array(
	array( 'table_name' => 'wp_posts', 'status' => 'merged', 'detail' => null ),
	array( 'table_name' => '*', 'status' => 'undo', 'detail' => "CALL heimdb_restore('main', '2026-10-14 01:23:45.678901+00:00', 'wp_options,wp_posts')" ),
);
check( 'merge undo', Client::undo_args( $merge ), array( 'branch' => 'main', 'time' => '2026-10-14 01:23:45.678901+00:00', 'tables' => 'wp_options,wp_posts' ) );
$restore = array( array( 'table_name' => '*', 'action' => 'undo', 'detail' => "CALL heimdb_restore('main', '2026-10-10 12:31:10.396594+00:00')" ) );
check( 'restore undo without tables', Client::undo_args( $restore ), array( 'branch' => 'main', 'time' => '2026-10-10 12:31:10.396594+00:00', 'tables' => null ) );
check( 'dry run has no undo', Client::undo_args( array( array( 'table_name' => '*', 'status' => 'dry run', 'detail' => null ) ) ), null );

$preview = array(
	array( 'table_name' => 'wp_posts', 'action' => 'restore rows', 'detail' => "post 1 also changed in wp_postmeta after the restore time, which is not restored; to restore them together: CALL heimdb_restore('main', '2026-10-18 09:57:35.000000+00:00', 'wp_posts,wp_postmeta')" ),
	array( 'table_name' => 'wp_comments', 'action' => 'restore rows', 'detail' => "comment 3 also changed in wp_commentmeta after the restore time, which is not restored; to restore them together: CALL heimdb_restore('main', '2026-10-18 09:57:35.000000+00:00', 'wp_posts,wp_comments,wp_commentmeta')" ),
	array( 'table_name' => '*', 'action' => 'undo', 'detail' => "CALL heimdb_restore('main', '2026-10-18 10:00:00.000000+00:00', 'wp_posts')" ),
);
check( 'restore suggestion joins the tables', Client::restore_suggestion( $preview ), array( 'branch' => 'main', 'time' => '2026-10-18 09:57:35.000000+00:00', 'tables' => 'wp_posts,wp_postmeta,wp_comments,wp_commentmeta' ) );
$users_too = array(
	array( 'table_name' => 'wp_posts', 'action' => 'restore rows', 'detail' => null ),
	array( 'table_name' => 'wp_users', 'action' => 'not restored', 'detail' => "user added: \"evil\" (ID 7); to restore users too: CALL heimdb_restore('main', '2026-10-12 03:00:00.000000+00:00', '*')" ),
);
check( 'restoring users too is not a pair suggestion', Client::restore_suggestion( $users_too ), null );
check( 'pair suggestion next to users too', Client::restore_suggestion( array_merge( $users_too, array( $preview[0] ) ) )['tables'] ?? null, 'wp_posts,wp_postmeta' );
check( "'*' is never a pair suggestion", Client::restore_suggestion( array( array( 'table_name' => 'wp_posts', 'action' => 'restore rows', 'detail' => "x; to restore them together: CALL heimdb_restore('main', '2026-10-12 03:00:00.000000+00:00', '*')" ) ) ), null );
check( 'undo row is not a suggestion', Client::restore_suggestion( array( $preview[2] ) ), null );
check( 'no suggestion', Client::restore_suggestion( array( array( 'table_name' => 'wp_posts', 'action' => 'restore rows', 'detail' => null ) ) ), null );

check( 'parent only', Client::parent_only( "author 20 does not exist on the parent; only 'parent' can resolve this: the author does not exist on the parent" ), 'the author does not exist on the parent' );
check( 'parent only in a merge error', Client::parent_only( "unresolved conflicts: wp_posts ID=20 (only 'parent' can resolve this: the author does not exist on the parent); resolve them" ), 'the author does not exist on the parent' );
check( 'not parent only', Client::parent_only( 'author 20 does not exist on the parent' ), null );
check( 'no detail', Client::parent_only( null ), null );
check( 'parent duplicate (conflict)', Client::parent_duplicate( 'post_type=wp_navigation,post_name=navigation was also added on the parent as ID=22' ), 'ID=22' );
check( 'parent duplicate (skip)', Client::parent_duplicate( "same as the parent's ID=10" ), 'ID=10' );
check( 'no parent duplicate', Client::parent_duplicate( 'transient' ), null );
check( 'hidden row id', Client::is_hidden_row( '__heimdb_row_id=20000000000' ), true );
check( 'not a hidden row id', Client::is_hidden_row( 'ID=1' ), false );
check( 'a column that only starts like it', Client::is_hidden_row( '__heimdb_row_id=1,x=2' ), false );

// 時刻: オフセットがなければサイトのタイムゾーン（ここでは Asia/Tokyo）、HeimDB にはオフセット付きの UTC
check( 'to_db site time', Time::to_db( '2026-10-18 09:00' ), '2026-10-18 00:00:00.000000+00:00' );
check( 'to_db datetime-local', Time::to_db( '2026-10-18T09:00:30' ), '2026-10-18 00:00:30.000000+00:00' );
check( 'to_db with offset', Time::to_db( '2026-10-18 09:00:00.5+02:00' ), '2026-10-18 07:00:00.500000+00:00' );
check( 'to_db is idempotent', Time::to_db( Time::to_db( '2026-10-18 09:00' ) ), '2026-10-18 00:00:00.000000+00:00' );
check( 'to_db date only', Time::to_db( '2026-10-18' ), '2026-10-17 15:00:00.000000+00:00' );
$ago = Time::parse( Time::to_db( '1 hour ago' ) )->getTimestamp();
check( 'to_db relative', abs( $ago - ( time() - 3600 ) ) <= 5, true );
foreach ( array( '', '   ', 'not a time' ) as $bad ) {
	try {
		Time::to_db( $bad );
		check( "to_db rejects '$bad'", 'accepted', 'Error' );
	} catch ( Error $e ) {
		check( "to_db rejects '$bad'", 'Error', 'Error' );
	}
}
check( 'display UTC without offset', Time::display( '2026-10-18 00:00:00' ), '2026-10-18 09:00:00' );
check( 'display drops zero micro', Time::display( '2026-10-18 00:00:00.000000+00:00', true ), '2026-10-18 09:00:00' );
check( 'display keeps micro', Time::display( '2026-10-18 00:00:00.123456+00:00', true ), '2026-10-18 09:00:00.123456' );
check( 'display empty', Time::display( null ), '' );
check( 'display unreadable', Time::display( 'garbage' ), 'garbage' );
check( 'to_input', Time::to_input( '2026-10-17 15:00:00' ), '2026-10-18T00:00:00' );
check( 'to_input round trip', Time::to_db( Time::to_input( '2026-10-18 00:00:30.000000+00:00' ) ), '2026-10-18 00:00:30.000000+00:00' );

$e = new Error( 'HeimDB: merge refused (unresolved_conflicts): unresolved conflicts: wp_posts ID=1', 51004 );
check( 'error reason', $e->reason, 'unresolved_conflicts' );
check( 'error code', $e->getCode(), Error::UNRESOLVED_CONFLICTS );
check( 'no reason', ( new Error( 'HeimDB: backups are disabled', 1105 ) )->reason, null );
check( 'denied', Client::is_denied( new Error( "command denied to user 'staging'@'%'", 1105 ) ), true );
check( 'refused by HeimDB is not denied', Client::is_denied( new Error( "HeimDB: a branch user must name a row; only an administrator can resolve with '*'", 1105 ) ), false );

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
