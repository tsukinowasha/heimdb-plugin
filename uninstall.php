<?php
/**
 * プラグインを削除するときの後始末。
 *
 * 0.2.1 までは、マージと復元の記録をユーザーのメタ heimdb_history に持っていた。残っていれば消す。
 * 通知（transient の heimdb_notice_*）は 5 分ほどで切れるので、消さない。
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, 'heimdb_history', '', true );
