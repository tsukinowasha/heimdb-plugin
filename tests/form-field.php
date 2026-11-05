<?php
// 管理画面の HTML（標準入力）から、操作 <op> の最初の form の欄 <field> の値を出す（E2E で nonce を取り出す）。
//   curl ... | php tests/form-field.php restore _wpnonce
list( , $op, $field ) = $argv;
foreach ( explode( '<form', stream_get_contents( STDIN ) ) as $form ) {
	$form = explode( '</form>', $form )[0];
	if ( false === strpos( $form, 'name="op" value="' . $op . '"' ) ) {
		continue;
	}
	if ( preg_match( '/name="' . preg_quote( $field, '/' ) . '" value="([^"]*)"/', $form, $m ) ) {
		echo html_entity_decode( $m[1], ENT_QUOTES );
		exit( 0 );
	}
}
exit( 1 );
