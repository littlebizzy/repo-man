<?php
/*
Plugin Name: Repo Man
Plugin URI: https://www.littlebizzy.com/plugins/repo-man
Description: Install public repos to WordPress
Version: 1.1.0
Author: LittleBizzy
Author URI: https://www.littlebizzy.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
GitHub Plugin URI: littlebizzy/repo-man
Primary Branch: master
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Disable WordPress.org updates for this plugin
add_filter( 'gu_override_dot_org', function( $overrides ) {
    $overrides[] = 'repo-man/repo-man.php';
    return $overrides;
});

// Force Repos tab as the default tab if no other tab is set
add_action( 'load-plugin-install.php', 'repo_man_force_repos_tab' );
function repo_man_force_repos_tab() {
    static $allowed_tabs = array( 'featured', 'popular', 'recommended', 'favorites', 'search', 'woo', 'upload', 'repos' );

    // Early return if in a ThickBox iframe or viewing plugin information
    if ( ! empty( $_GET['TB_iframe'] ) || ( isset( $_GET['tab'] ) && $_GET['tab'] === 'plugin-information' ) ) {
        return;
    }

    // If we're in a Network Admin area, skip forcing the 'repos' tab
    if ( is_network_admin() ) {
        return;
    }

    // Determine the appropriate admin URL based on whether we're in Network Admin
    $admin_url = is_network_admin() ? network_admin_url( 'plugin-install.php' ) : admin_url( 'plugin-install.php' );

    // If no valid tab is set, redirect to the 'repos' tab
    if ( ! isset( $_GET['tab'] ) || ! in_array( $_GET['tab'], $allowed_tabs, true ) ) {
        wp_safe_redirect( esc_url_raw( add_query_arg( 'tab', 'repos', $admin_url ) ) );
        exit;
    }
}

// Add the Repos tab and make it appear first
add_filter( 'install_plugins_tabs', 'repo_man_prepend_repos_tab' );
function repo_man_prepend_repos_tab( $tabs ) {
    $repos_tab = array( 'repos' => __( 'Public Repos', 'repo-man' ) );
    return array_merge( $repos_tab, $tabs );
}

// Display content for the Repos tab
add_action( 'install_plugins_repos', 'repo_man_display_repos_plugins' );
function repo_man_display_repos_plugins() {
    $plugins = repo_man_get_plugins_data();
    $plugins_per_page = 10;

    if ( is_wp_error( $plugins ) ) {
        repo_man_display_admin_notice( $plugins->get_error_message() );
        return;
    }

    $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $total_plugins = count( $plugins );
    $total_pages = ceil( $total_plugins / $plugins_per_page );
    $offset = ( $paged - 1 ) * $plugins_per_page;
    $paged_plugins = array_slice( $plugins, $offset, $plugins_per_page );

    if ( empty( $paged_plugins ) ) {
        echo '<p>' . esc_html__( 'No plugins available to display.', 'repo-man' ) . '</p>';
        return;
    }

    ?>
    <p><?php esc_html_e( 'These are hand-picked WordPress plugins hosted on public repositories, including GitHub, GitLab, and beyond.', 'repo-man' ); ?></p>
    <form id="plugin-filter" method="post">
        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $_SERVER['REQUEST_URI'] ); ?>">

        <div class="tablenav top">
            <div class="alignleft actions"></div>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html( $total_plugins ); ?> items</span>
                <span class="pagination-links">
                    <?php if ( $paged > 1 ): ?>
                        <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>" aria-hidden="true">«</a>
                        <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" aria-hidden="true">‹</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                    <?php endif; ?>
                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'repo-man' ); ?></label>
                        <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( $paged ); ?>" size="1">
                        <span class="tablenav-paging-text"><?php esc_html_e( 'of', 'repo-man' ); ?> <span class="total-pages"><?php echo esc_html( $total_pages ); ?></span></span>
                    </span>
                    <?php if ( $paged < $total_pages ): ?>
                        <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" aria-hidden="true">›</a>
                        <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>" aria-hidden="true">»</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                    <?php endif; ?>
                </span>
            </div>
            <br class="clear">
        </div>

        <div class="wp-list-table widefat plugin-install">
            <h2 class="screen-reader-text"><?php esc_html_e( 'Plugins list', 'repo-man' ); ?></h2>
            <div id="the-list">
                <?php foreach ( $paged_plugins as $plugin ) : ?>
                    <?php repo_man_render_plugin_card( $plugin ); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html( $total_plugins ); ?> items</span>
                <span class="pagination-links">
                    <?php if ( $paged > 1 ): ?>
                        <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>" aria-hidden="true">«</a>
                        <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" aria-hidden="true">‹</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                    <?php endif; ?>
                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'repo-man' ); ?></label>
                        <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( $paged ); ?>" size="1">
                        <span class="tablenav-paging-text"><?php esc_html_e( 'of', 'repo-man' ); ?> <span class="total-pages"><?php echo esc_html( $total_pages ); ?></span></span>
                    </span>
                    <?php if ( $paged < $total_pages ): ?>
                        <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" aria-hidden="true">›</a>
                        <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>" aria-hidden="true">»</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                    <?php endif; ?>
                </span>
            </div>
            <br class="clear">
        </div>
    </form>
    <?php
}

// Function to render each plugin card
function repo_man_render_plugin_card( $plugin ) {
    ?>
    <div class="plugin-card plugin-card-<?php echo sanitize_title( $plugin['slug'] ); ?>">
        <div class="plugin-card-top">
            <div class="name column-name">
                <h3>
                    <a href="<?php echo esc_url( $plugin['url'] ); ?>" class="thickbox open-plugin-details-modal">
                        <?php echo esc_html( $plugin['name'] ); ?>
                        <img src="<?php echo esc_url( $plugin['icon_url'] ); ?>" class="plugin-icon" alt="<?php echo esc_attr( $plugin['name'] ); ?>">
                    </a>
                </h3>
            </div>
            <div class="action-links">
                <ul class="plugin-action-buttons">
                    <li><a class="button" href="<?php echo esc_url( $plugin['url'] ); ?>" target="_blank"><?php esc_html_e( 'View on GitHub', 'repo-man' ); ?></a></li>
                    <li><a href="<?php echo esc_url( $plugin['url'] ); ?>" class="thickbox open-plugin-details-modal"><?php esc_html_e( 'More Details', 'repo-man' ); ?></a></li>
                </ul>
            </div>
            <div class="desc column-description">
                <p><?php echo esc_html( $plugin['description'] ); ?></p>
                <p class="authors"><cite><?php esc_html_e( 'By', 'repo-man' ); ?> <a href="<?php echo esc_url( $plugin['author_url'] ); ?>"><?php echo esc_html( $plugin['author'] ); ?></a></cite></p>
            </div>
        </div>
        <div class="plugin-card-bottom">
            <div class="vers column-rating">
                <div class="star-rating">
                    <span class="screen-reader-text"><?php echo esc_html( $plugin['rating'] ); ?> rating based on <?php echo esc_html( $plugin['ratings_count'] ); ?> ratings</span>
                    <?php echo repo_man_display_star_rating( $plugin['rating'] ); ?>
                </div>
                <span class="num-ratings" aria-hidden="true">(<?php echo esc_html( $plugin['ratings_count'] ); ?>)</span>
            </div>
            <div class="column-updated">
                <strong><?php esc_html_e( 'Last Updated:', 'repo-man' ); ?></strong> <?php echo esc_html( $plugin['last_updated'] ); ?>
            </div>
            <div class="column-downloaded">
                <?php echo esc_html( $plugin['active_installs'] ); ?> <?php esc_html_e( 'Active Installations', 'repo-man' ); ?>
            </div>
            <div class="column-compatibility">
                <span class="compatibility-<?php echo esc_attr( $plugin['compatible'] ? 'compatible' : 'incompatible' ); ?>">
                    <strong><?php echo $plugin['compatible'] ? esc_html__( 'Compatible', 'repo-man' ) : esc_html__( 'Incompatible', 'repo-man' ); ?></strong>
                    <?php esc_html_e( 'with your version of WordPress', 'repo-man' ); ?>
                </span>
            </div>
        </div>
    </div>
    <?php
}

// Fetch plugin data from the custom file
function repo_man_get_plugins_data() {
    $file = plugin_dir_path( __FILE__ ) . 'plugin-repos.json';

    // Check if the JSON file exists
    if ( ! file_exists( $file ) ) {
        return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing.', 'repo-man' ) );
    }

    // Attempt to read the file contents
    $content = file_get_contents( $file );

    if ( false === $content ) {
        return new WP_Error( 'file_unreadable', __( 'Error: The plugin-repos.json file could not be read.', 'repo-man' ) );
    }

    // Attempt to decode the JSON content
    $plugins = json_decode( $content, true );

    // Handle JSON decoding errors
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'file_malformed', sprintf( __( 'Error: The plugin-repos.json file is malformed (%s).', 'repo-man' ), json_last_error_msg() ) );
    }

    // Return the decoded array or an empty array if not valid
    return is_array( $plugins ) ? $plugins : [];
}

// Function to display admin notices
function repo_man_display_admin_notice( $message ) {
    ?>
    <div class="notice notice-error">
        <p><strong><?php echo esc_html( $message ); ?></strong></p>
    </div>
    <?php
}

// Function to display star ratings
function repo_man_display_star_rating( $rating ) {
    $full_stars = floor( $rating );
    $html = '';
    for ( $i = 0; $i < 5; $i++ ) {
        if ( $i < $full_stars ) {
            $html .= '<div class="star star-full" aria-hidden="true"></div>';
        } else {
            $html .= '<div class="star star-empty" aria-hidden="true"></div>';
        }
    }
    return $html;
}

// Ref: ChatGPT
