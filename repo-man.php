<?php
/*
Plugin Name: Repo Man
Plugin URI: https://www.littlebizzy.com/plugins/repo-man
Description: Install public repos to WordPress
Version: 1.2.2
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
}, 999 );

// Add the Repos tab and adjust its position based on search activity
// Priority 12 is used to ensure it loads after the Plugin Blacklist plugin
add_filter( 'install_plugins_tabs', 'repo_man_adjust_repos_tab_position', 12 );
function repo_man_adjust_repos_tab_position( $tabs ) {
    // Define the "Public Repos" tab
    $public_repos_tab = array( 'repos' => _x( 'Public Repos', 'Tab title', 'repo-man' ) );

    // Check if a search query is active, sanitize the input
    if ( isset( $_GET['s'] ) && ! empty( sanitize_text_field( $_GET['s'] ) ) ) {
        // Place "Public Repos" tab after the Search Results tab
        return array_merge( array_slice( $tabs, 0, 1 ), $public_repos_tab, array_slice( $tabs, 1 ) );
    }

    // Prepend "Public Repos" as the first tab when no search is active
    return array_merge( $public_repos_tab, $tabs );
}

// Display content for the Repos tab
add_action( 'install_plugins_repos', 'repo_man_display_repos_plugins', 12 );
function repo_man_display_repos_plugins() {
    $plugins = repo_man_get_plugins_data();
    $plugins_per_page = 36;

    // Display error message if plugins data retrieval fails
    if ( is_wp_error( $plugins ) ) {
        repo_man_display_admin_notice( $plugins->get_error_message() );
        return;
    }

    // Handle pagination
    $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $total_plugins = count( $plugins );
    $total_pages = ceil( $total_plugins / $plugins_per_page );
    $offset = ( $paged - 1 ) * $plugins_per_page;
    $paged_plugins = array_slice( $plugins, $offset, $plugins_per_page );

    // Show message if no plugins are available
    if ( empty( $paged_plugins ) ) {
        echo '<p>' . esc_html__( 'No plugins available to display.', 'repo-man' ) . '</p>';
        return;
    }

    // Display a description for the Repos tab
    echo '<p>' . esc_html__( 'These are hand-picked WordPress plugins hosted on public repositories, including GitHub, GitLab, and beyond.', 'repo-man' ) . '</p>';

    // Start the form for filtering and pagination
    ?>
    <form id="plugin-filter" method="post">
        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $_SERVER['REQUEST_URI'] ); ?>">

        <?php
        // Display pagination at the top of the table
        repo_man_render_pagination( $paged, $total_plugins, $total_pages, 'top' );
        ?>

        <div class="wp-list-table widefat plugin-install">
            <h2 class="screen-reader-text"><?php esc_html_e( 'Plugins list', 'repo-man' ); ?></h2>
            <div id="the-list">
                <?php foreach ( $paged_plugins as $plugin ) : ?>
                    <?php repo_man_render_plugin_card( $plugin ); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        // Display pagination at the bottom of the table
        repo_man_render_pagination( $paged, $total_plugins, $total_pages, 'bottom' );
        ?>
    </form>
    <?php
}

// Function to render pagination with appropriate classes for top or bottom
function repo_man_render_pagination( $paged, $total_plugins, $total_pages, $position ) {
    ?>
    <div class="tablenav <?php echo esc_attr( $position ); ?>">
        <div class="alignleft actions"></div>
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( $total_plugins ); ?> items</span>
            <span class="pagination-links">
                <?php if ( $paged > 1 ) : ?>
                    <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>" aria-hidden="true">«</a>
                    <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" aria-hidden="true">‹</a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                <?php endif; ?>

                <span class="paging-input">
                    <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'repo-man' ); ?></label>
                    <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( $paged ); ?>" size="1">
                    <span class="tablenav-paging-text"><?php esc_html_e( 'of', 'repo-man' ); ?> <span class="total-pages"><?php echo esc_html( $total_pages ); ?></span></span>
                </span>

                <?php if ( $paged < $total_pages ) : ?>
                    <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" aria-hidden="true">›</a>
                    <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>" aria-hidden="true">»</a>
                <?php else : ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                <?php endif; ?>
            </span>
        </div>
        <br class="clear">
    </div>
    <?php
}

// Function to render each plugin card
function repo_man_render_plugin_card( $plugin ) {
    ?>
    <div class="plugin-card plugin-card-<?php echo esc_attr( sanitize_title( $plugin['slug'] ) ); ?>">
        <div class="plugin-card-top">
            <div class="name column-name">
                <h3>
                    <a href="<?php echo ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '#'; ?>" class="thickbox open-plugin-details-modal">
                        <?php echo esc_html( $plugin['name'] ); ?>
                        <?php if ( ! empty( $plugin['icon_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $plugin['icon_url'] ); ?>" class="plugin-icon" alt="<?php echo esc_attr( $plugin['name'] ); ?>">
                        <?php endif; ?>
                    </a>
                </h3>
            </div>
            <div class="action-links">
                <ul class="plugin-action-buttons">
                    <li><a class="button" href="<?php echo ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '#'; ?>" target="_blank"><?php esc_html_e( 'View on GitHub', 'repo-man' ); ?></a></li>
                    <li><a href="<?php echo ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '#'; ?>" class="thickbox open-plugin-details-modal"><?php esc_html_e( 'More Details', 'repo-man' ); ?></a></li>
                </ul>
            </div>
            <div class="desc column-description">
                <p><?php echo esc_html( $plugin['description'] ); ?></p>
                <p class="authors">
                    <cite><?php esc_html_e( 'By', 'repo-man' ); ?>
                        <a href="<?php echo ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '#'; ?>">
                            <?php echo esc_html( $plugin['author'] ); ?>
                        </a>
                    </cite>
                </p>
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

// Fetch plugin data from the custom file with secure handling and fallback for missing keys
function repo_man_get_plugins_data() {
    // Define the file path securely and check its existence and readability
    $file = realpath( plugin_dir_path( __FILE__ ) . 'plugin-repos.json' );

    // Ensure the file is within the expected directory (security check)
    if ( ! $file || strpos( $file, plugin_dir_path( __FILE__ ) ) !== 0 || ! is_readable( $file ) ) {
        return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing or unreadable.', 'repo-man' ) );
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

    // Ensure each plugin has the required keys with default values as fallback
    foreach ( $plugins as &$plugin ) {
        $plugin['slug'] = $plugin['slug'] ?? 'unknown-slug';
        $plugin['url'] = $plugin['url'] ?? '#';
        $plugin['name'] = $plugin['name'] ?? __( 'Unknown Plugin', 'repo-man' );
        $plugin['icon_url'] = $plugin['icon_url'] ?? '';
        $plugin['description'] = $plugin['description'] ?? __( 'No description available.', 'repo-man' );
        $plugin['author'] = $plugin['author'] ?? __( 'Unknown Author', 'repo-man' );
        $plugin['author_url'] = $plugin['author_url'] ?? '#';
        $plugin['rating'] = $plugin['rating'] ?? 0;
        $plugin['ratings_count'] = $plugin['ratings_count'] ?? 0;
        $plugin['last_updated'] = $plugin['last_updated'] ?? __( 'Unknown', 'repo-man' );
        $plugin['active_installs'] = $plugin['active_installs'] ?? 0;
        $plugin['compatible'] = $plugin['compatible'] ?? false;
    }

    // Return the cleaned up plugins array
    return $plugins;
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
    // Ensure $rating is sanitized
    $rating = floatval( $rating );
    $full_stars = floor( $rating );
    $half_star = ( $rating - $full_stars ) >= 0.5;
    $html = [];

    // Add full stars
    for ( $i = 0; $i < $full_stars; $i++ ) {
        $html[] = '<div class="star star-full" aria-hidden="true"></div>';
    }

    // Add half star if applicable
    if ( $half_star ) {
        $html[] = '<div class="star star-half" aria-hidden="true"></div>';
    }

    // Add empty stars
    $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
    for ( $i = 0; $i < $empty_stars; $i++ ) {
        $html[] = '<div class="star star-empty" aria-hidden="true"></div>';
    }

    return implode( '', $html );
}

// Extend the search results to include plugins from the JSON file and prioritize them when relevant
add_filter( 'plugins_api_result', 'repo_man_extend_search_results', 12, 3 );
function repo_man_extend_search_results( $res, $action, $args ) {
    // Return early if not searching for plugins
    if ( 'query_plugins' !== $action || ! isset( $args->search ) || empty( $args->search ) ) {
        return $res;
    }

    // Sanitize the search query, preserving spaces
    $search_query = sanitize_text_field( $args->search );

    // Split the search query into individual words
    $search_terms = array_filter( explode( ' ', $search_query ) );

    // Fetch plugins data from the transient cache
    $plugins = repo_man_get_plugins_data_with_cache();

    // Return the original results if an error occurred or there are no plugins
    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $res;
    }

    // Normalize the plugins data
    $plugins = array_map( 'repo_man_normalize_plugin_data', $plugins );

    // Step 1: Exact match of the full search term in the name or description (priority)
    $matching_plugins = array_filter( $plugins, function( $plugin ) use ( $search_query ) {
        return stripos( $plugin['name'], $search_query ) !== false || stripos( $plugin['description'], $search_query ) !== false;
    });

    // Step 2: If no exact match, fallback to word-based matching
    if ( empty( $matching_plugins ) ) {
        // Try to find plugins that match ALL search terms (AND logic)
        $matching_plugins = array_filter( $plugins, function( $plugin ) use ( $search_terms ) {
            foreach ( $search_terms as $term ) {
                if ( stripos( $plugin['name'], $term ) === false && stripos( $plugin['description'], $term ) === false ) {
                    return false; // If one term doesn't match, skip this plugin
                }
            }
            return true;
        });

        // If no plugins match all terms, fallback to partial matches (OR logic)
        if ( empty( $matching_plugins ) ) {
            $matching_plugins = array_filter( $plugins, function( $plugin ) use ( $search_terms ) {
                foreach ( $search_terms as $term ) {
                    if ( stripos( $plugin['name'], $term ) !== false || stripos( $plugin['description'], $term ) !== false ) {
                        return true; // Return plugins matching at least one term
                    }
                }
                return false;
            });
        }
    }

    // Step 3: Boost relevance of matching plugins from the JSON data
    if ( ! empty( $matching_plugins ) ) {
        $json_plugins = array_filter( $matching_plugins, function( $plugin ) {
            return isset( $plugin['source'] ) && $plugin['source'] === 'json'; // Assuming your JSON plugins have a 'source' key
        });

        // Boost JSON plugins by placing them at the top of the results
        $matching_plugins = array_merge( $json_plugins, $matching_plugins );
    }

    // Format each matching plugin for WordPress's expected structure
    $formatted_plugins = array_map( 'repo_man_prepare_plugin_for_display', $matching_plugins );

    // Prepend matching plugins to the WordPress search results
    $res->plugins = array_merge( $formatted_plugins, $res->plugins );
    $res->info['results'] += count( $formatted_plugins );

    return $res;
}

// Normalize the plugin data to ensure all required keys are present
function repo_man_normalize_plugin_data( $plugin ) {
    $defaults = [
        'name'              => _x( 'Unknown Plugin', 'Default plugin name', 'repo-man' ),
        'slug'              => 'unknown-slug',
        'author'            => _x( 'Unknown Author', 'Default author name', 'repo-man' ),
        'author_url'        => '',
        'version'           => '1.0.0',
        'rating'            => 0,
        'num_ratings'       => 0,
        'url'               => '',
        'last_updated'      => _x( 'Unknown', 'Default last updated', 'repo-man' ),
        'active_installs'   => 0,
        'description'       => _x( 'No description available.', 'Default description', 'repo-man' ),
        'icon_url'          => '',
    ];
    return array_merge( $defaults, $plugin );
}

// Prepare the plugin for display by WordPress
function repo_man_prepare_plugin_for_display( $plugin ) {
    // Ensure the plugin data is normalized first
    $plugin = repo_man_normalize_plugin_data( $plugin );

    return [
        'name'              => esc_html( $plugin['name'] ),
        'slug'              => esc_attr( $plugin['slug'] ),
        'author'            => esc_html( $plugin['author'] ),
        // Only add the author profile link if a valid URL is provided, otherwise return an empty string
        'author_profile'    => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'version'           => esc_html( $plugin['version'] ),
        'rating'            => intval( $plugin['rating'] ) * 20, // Convert rating to a percentage
        'num_ratings'       => intval( $plugin['num_ratings'] ),
        // Only add the homepage and download link if a valid URL is provided, otherwise return an empty string
        'homepage'          => ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '',
        'download_link'     => ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '',
        'last_updated'      => esc_html( $plugin['last_updated'] ),
        'active_installs'   => intval( $plugin['active_installs'] ),
        'short_description' => esc_html( $plugin['description'] ),
        'icons'             => [
            // Return an empty string if no icon URL is available
            'default' => ! empty( $plugin['icon_url'] ) ? esc_url( $plugin['icon_url'] ) : '',
        ],
    ];
}

// Fetch plugin data with caching via transients, only define once
if ( ! function_exists( 'repo_man_get_plugins_data_with_cache' ) ) {
    function repo_man_get_plugins_data_with_cache() {
        $plugins = get_transient( 'repo_man_plugins' );
        if ( false === $plugins ) {
            $plugins = repo_man_get_plugins_data();
            if ( ! is_wp_error( $plugins ) ) {
                set_transient( 'repo_man_plugins', $plugins, HOUR_IN_SECONDS );
            }
        }
        return $plugins;
    }
}

// Fetch plugin data securely and with fallback handling, only define once
if ( ! function_exists( 'repo_man_get_plugins_data' ) ) {
    function repo_man_get_plugins_data() {
        $file = realpath( plugin_dir_path( __FILE__ ) . 'plugin-repos.json' );
        
        // Check if file exists and is readable
        if ( ! $file || strpos( $file, plugin_dir_path( __FILE__ ) ) !== 0 || ! is_readable( $file ) ) {
            return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing or unreadable.', 'repo-man' ) );
        }

        // Read the file contents
        $content = file_get_contents( $file );
        if ( false === $content ) {
            return new WP_Error( 'file_unreadable', __( 'Error: The plugin-repos.json file could not be read.', 'repo-man' ) );
        }

        // Decode the JSON content
        $plugins = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'file_malformed', sprintf( __( 'Error: The plugin-repos.json file is malformed (%s).', 'repo-man' ), json_last_error_msg() ) );
        }

        // Check if the file is empty or contains no data
        if ( empty( $plugins ) ) {
            return new WP_Error( 'file_empty', __( 'Error: The plugin-repos.json file is empty or contains no plugins.', 'repo-man' ) );
        }

        return $plugins;
    }
}

// Ref: ChatGPT
