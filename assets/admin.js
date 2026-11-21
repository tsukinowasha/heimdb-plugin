( function () {
	// 取り返しのつきにくい操作（マージ、復元、削除、GC）は確かめてから送る。
	document.addEventListener( 'submit', function ( e ) {
		var message = e.target.getAttribute && e.target.getAttribute( 'data-heimdb-confirm' );
		// 空打ちのボタン（同じフォームの、書かない方）は確かめない。
		if ( e.submitter && e.submitter.hasAttribute( 'data-heimdb-no-confirm' ) ) {
			message = null;
		}
		// 復元は、選んだ範囲（下見のときと変えたかもしれない）も確かめる。
		if ( message && e.target.hasAttribute( 'data-heimdb-confirm-scope' ) ) {
			var scope = e.target.querySelector( 'input[name="scope"]:checked' );
			if ( scope ) {
				var text = scope.parentNode.textContent.trim();
				if ( 'tables' === scope.value ) {
					text += ' ' + Array.prototype.map.call( e.target.querySelectorAll( 'input[name="tables[]"]:checked' ), function ( c ) {
						return c.value;
					} ).join( ', ' );
				}
				message += '\n\n' + text;
			}
		}
		if ( message && ! window.confirm( message ) ) {
			e.preventDefault();
			return;
		}
		// 二度押しを防ぐ（マージの間、親への書き込みは待たされる）。
		var buttons = e.target.querySelectorAll( 'button' );
		window.setTimeout( function () {
			buttons.forEach( function ( b ) {
				b.disabled = true;
			} );
		}, 0 );
	} );

	// タイムトラベルの「1 時間前」などのボタン。
	document.addEventListener( 'click', function ( e ) {
		var preset = e.target.getAttribute && e.target.getAttribute( 'data-heimdb-preset' );
		var input = document.getElementById( 'heimdb-time-at' );
		if ( preset && input ) {
			input.value = preset;
		}
	} );
} )();
