<?php
/**
 * Plugin Name:       HeimDB
 * Plugin URI:        https://github.com/tsukinowasha/heimdb-plugin
 * Description:       Branches, diffs, conflict resolution, merges, time travel and restores of HeimDB, from wp-admin and wp-cli.
 * Version:           0.3.2
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Tsukinowa Inc.
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       heimdb
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HEIMDB_PLUGIN_VERSION', '0.3.2' );
define( 'HEIMDB_PLUGIN_FILE', __FILE__ );
define( 'HEIMDB_PLUGIN_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-error.php';
require_once __DIR__ . '/includes/class-time.php';
require_once __DIR__ . '/includes/class-client.php';

// 同梱の翻訳（languages/）。wordpress.org から配られる翻訳（wp-content/languages/plugins/）があれば、そちらが先。
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'heimdb', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	add_action( 'plugins_loaded', array( 'Heimdb\\Admin', 'init' ) );
}

// 管理バーに、このサイトがつないでいるブランチを出す（本番とステージングの取り違えを防ぐ）。
add_action(
	'admin_bar_menu',
	function ( WP_Admin_Bar $bar ) {
		if ( ! current_user_can( heimdb_capability() ) ) {
			return;
		}
		$branch = Heimdb\Client::site_branch();
		$bar->add_node(
			array(
				'id'    => 'heimdb-branch',
				'title' => '<span class="ab-icon dashicons dashicons-randomize" style="margin-top:2px"></span>' . esc_html( $branch ),
				'href'  => admin_url( 'admin.php?page=heimdb' ),
				'meta'  => array(
					/* translators: %s: branch name */
					'title' => sprintf( __( 'HeimDB branch: %s', 'heimdb' ), $branch ),
					'class' => 'main' === $branch ? 'heimdb-main' : 'heimdb-branch',
				),
			)
		);
	},
	100
);

add_action( 'admin_head', 'heimdb_admin_bar_style' );
add_action( 'wp_head', 'heimdb_admin_bar_style' );

/**
 * 管理バーのブランチの表示。本番以外は目立たせる（管理画面でもサイトの表のページでも）。
 */
function heimdb_admin_bar_style(): void {
	if ( is_admin_bar_showing() && current_user_can( heimdb_capability() ) ) {
		echo '<style>#wpadminbar .heimdb-branch > .ab-item{background:#b32d2e;color:#fff}#wpadminbar .heimdb-branch .ab-icon:before{color:#fff}</style>';
	}
}

/**
 * HeimDB を操作できる権限。
 *
 * HeimDB の操作はデータベース全体（マルチサイトなら全サイト）に効くので、マルチサイトではネットワークの管理者だけ。
 */
function heimdb_capability(): string {
	/**
	 * HeimDB の画面を使える権限（既定 manage_options、マルチサイトなら manage_network_options）。
	 *
	 * @param string $capability
	 */
	return (string) apply_filters( 'heimdb_capability', is_multisite() ? 'manage_network_options' : 'manage_options' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
	\WP_CLI::add_command( 'heimdb', 'Heimdb\\CLI' );
	\WP_CLI::add_command( 'heimdb branch', 'Heimdb\\CLI_Branch' );
	\WP_CLI::add_command( 'heimdb exclude', 'Heimdb\\CLI_Exclude' );
}
