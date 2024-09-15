<?php
/*
Plugin Name: Repo Man
Plugin URI: https://www.littlebizzy.com/plugins/repo-man
Description: Install public repos to WordPress
Version: 1.0.0
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
add_filter('gu_override_dot_org', function ($overrides) {
    $overrides[] = 'repo-man/repo-man.php';
    return $overrides;
});

// Force Repos tab as the default tab if no other tab is set
add_action( 'load-plugin-install.php', 'repo_man_force_repos_tab' );
function repo_man_force_repos_tab() {
    $allowed_tabs = array( 'featured', 'popular', 'recommended', 'favorites', 'search', 'upload', 'repos' );

    if ( ! isset( $_GET['tab'] ) || ! in_array( $_GET['tab'], $allowed_tabs ) ) {
        if ( empty( $_GET['TB_iframe'] ) && ( ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'plugin-information' ) ) {
            wp_redirect( admin_url( 'plugin-install.php?tab=repos' ) );
            exit;
        }
    }
}

// Add the Repos tab and make it appear first
add_filter( 'install_plugins_tabs', 'repo_man_add_repos_tab' );
function repo_man_add_repos_tab( $tabs ) {
    $new_tabs = array( 'repos' => __( 'Public Repos', 'repo-man' ) );
    $tabs = $new_tabs + $tabs; // Prepend the new Repos tab
    return $tabs;
}

// Display content for the Repos tab
add_action( 'install_plugins_repos', 'repo_man_display_repos_plugins' );
function repo_man_display_repos_plugins() {
    $plugins = repo_man_get_plugins_data();

    if ( empty( $plugins ) ) {
        echo '<p>' . __( 'No plugins available to display.', 'repo-man' ) . '</p>';
        return;
    }

    ?>
    <div class="wrap">
        <p><?php _e( 'These are hand-picked WordPress plugins hosted on public repositories, including GitHub, GitLab, and beyond.', 'repo-man' ); ?></p>
        <div class="plugin-install-plugins">
            <?php foreach ( $plugins as $plugin ) : ?>
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
                                <li><a class="button" href="<?php echo esc_url( $plugin['url'] ); ?>" target="_blank"><?php _e( 'View on GitHub', 'repo-man' ); ?></a></li>
                                <li><a href="<?php echo esc_url( $plugin['url'] ); ?>" class="thickbox open-plugin-details-modal"><?php _e( 'More Details', 'repo-man' ); ?></a></li>
                            </ul>
                        </div>
                        <div class="desc column-description">
                            <p><?php echo esc_html( $plugin['description'] ); ?></p>
                            <p class="authors"><cite><?php _e( 'By', 'repo-man' ); ?> <a href="<?php echo esc_url( $plugin['author_url'] ); ?>"><?php echo esc_html( $plugin['author'] ); ?></a></cite></p>
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
                            <strong><?php _e( 'Last Updated:', 'repo-man' ); ?></strong> <?php echo esc_html( $plugin['last_updated'] ); ?>
                        </div>
                        <div class="column-downloaded">
                            <?php echo esc_html( $plugin['active_installs'] ); ?> <?php _e( 'Active Installations', 'repo-man' ); ?>
                        </div>
                        <div class="column-compatibility">
                            <span class="compatibility-<?php echo esc_attr( $plugin['compatible'] ? 'compatible' : 'incompatible' ); ?>">
                                <strong><?php echo $plugin['compatible'] ? __( 'Compatible', 'repo-man' ) : __( 'Incompatible', 'repo-man' ); ?></strong>
                                <?php _e( 'with your version of WordPress', 'repo-man' ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

// Fetch plugin data from the custom JSON file
function repo_man_get_plugins_data() {
    $file = plugin_dir_path( __FILE__ ) . 'plugin-repos.json';

    if ( ! file_exists( $file ) ) {
        return [];
    }

    $content = file_get_contents( $file );
    $plugins = json_decode( $content, true );

    return is_array( $plugins ) ? $plugins : [];
}

// Display star ratings
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
