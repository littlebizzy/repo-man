<?php
/*
Plugin Name: Repo Man
Plugin URI: https://www.littlebizzy.com/plugins/repo-man
Description: Install public repos to WordPress
Version: 1.3.0
Author: LittleBizzy
Author URI: https://www.littlebizzy.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
GitHub Plugin URI: littlebizzy/repo-man
Primary Branch: master
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Disable WordPress.org updates for this plugin
add_filter( 'gu_override_dot_org', function( $overrides ) {
    $overrides[] = 'repo-man/repo-man.php';
    return $overrides;
}, 999 );

// Fetch plugin data from the custom file with secure handling and fallback for missing keys
function repo_man_get_plugins_data() {
    // Define the file path securely and check its existence and readability
    $plugin_dir = realpath( plugin_dir_path( __FILE__ ) );
    $file = realpath( $plugin_dir . '/plugin-repos.json' );

    // Ensure the file is within the plugin directory (security check)
    if ( ! $file || 0 !== strpos( $file, $plugin_dir . DIRECTORY_SEPARATOR ) || ! is_readable( $file ) ) {
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

    // Check if the file is empty or contains no data
    if ( empty( $plugins ) ) {
        return new WP_Error( 'file_empty', __( 'Error: The plugin-repos.json file is empty or contains no plugins.', 'repo-man' ) );
    }

    // Ensure each plugin has the required keys with default values as fallback
    foreach ( $plugins as &$plugin ) {
        $plugin['slug']            = $plugin['slug'] ?? 'unknown-slug';
        $plugin['url']             = $plugin['url'] ?? '#';
        $plugin['name']            = $plugin['name'] ?? _x( 'Unknown Plugin', 'Default plugin name', 'repo-man' );
        $plugin['icon_url']        = $plugin['icon_url'] ?? '';
        $plugin['description']     = $plugin['description'] ?? _x( 'No description available.', 'Default plugin description', 'repo-man' );
        $plugin['author']          = $plugin['author'] ?? _x( 'Unknown Author', 'Default author name', 'repo-man' );
        $plugin['author_url']      = $plugin['author_url'] ?? '#';
        $plugin['rating']          = $plugin['rating'] ?? 0;
        $plugin['ratings_count']   = $plugin['ratings_count'] ?? 0;
        $plugin['last_updated']    = $plugin['last_updated'] ?? _x( 'Unknown', 'Default last updated', 'repo-man' );
        $plugin['active_installs'] = $plugin['active_installs'] ?? 0;
        $plugin['compatible']      = $plugin['compatible'] ?? false;
    }

    // Return the cleaned-up plugins array
    return $plugins;
}

// Function to display admin notices
function repo_man_display_admin_notice( $message ) {
    ?>
    <div class="notice notice-error">
        <p><strong><?php echo wp_kses_post( $message ); ?></strong></p>
    </div>
    <?php
}

// Extend the search results to include plugins from the JSON file and prioritize them when relevant
add_filter( 'plugins_api_result', 'repo_man_extend_search_results', 12, 3 );
function repo_man_extend_search_results( $res, $action, $args ) {
    // Return early if not searching for plugins
    if ( 'query_plugins' !== $action || empty( $args->search ) ) {
        return $res;
    }

    // Sanitize the search query, preserving spaces
    $search_query = sanitize_text_field( urldecode( $args->search ) );

    // Fetch plugins data from the transient cache
    $plugins = repo_man_get_plugins_data_with_cache();

    // Return the original results if an error occurred or there are no plugins
    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $res;
    }

    // Normalize the plugins data
    $plugins = array_map( 'repo_man_normalize_plugin_data', $plugins );

    // Initialize an array to hold matching plugins
    $matching_plugins = array();

    // Loop through each plugin and score it based on the search query
    foreach ( $plugins as $plugin ) {
        $score = repo_man_calculate_match_score( $plugin, $search_query );
        if ( $score > 0 ) {
            $plugin['match_score'] = $score;
            $matching_plugins[] = $plugin;
        }
    }

    // If no matching plugins, return the original results
    if ( empty( $matching_plugins ) ) {
        return $res;
    }

    // Sort matching plugins by score in descending order
    usort( $matching_plugins, function( $a, $b ) {
        return $b['match_score'] - $a['match_score'];
    } );

    // Prepare matching plugins for display
    $formatted_plugins = array_map( 'repo_man_prepare_plugin_for_display', $matching_plugins );

    // Remove duplicates from the original results
    $original_plugins = $res->plugins;
    $original_plugins = array_filter( $original_plugins, function( $plugin ) use ( $formatted_plugins ) {
        return ! in_array( $plugin['slug'], array_column( $formatted_plugins, 'slug' ) );
    } );

    // Merge and boost matching plugins
    $res->plugins = array_merge( $formatted_plugins, $original_plugins );
    $res->info['results'] = count( $res->plugins );

    return $res;
}

// Function to calculate match score based on search query
function repo_man_calculate_match_score( $plugin, $search_query ) {
    $score = 0;

    // Prepare plugin data and search query for matching
    $plugin_name        = strtolower( $plugin['name'] );
    $plugin_slug        = strtolower( $plugin['slug'] );
    $plugin_description = strtolower( $plugin['description'] );
    $search_query       = strtolower( $search_query );
    $search_terms       = array_filter( explode( ' ', $search_query ) );

    // Exact match of the full search query in the plugin name (highest score)
    if ( $plugin_name === $search_query ) {
        $score += 100;
    }

    // Partial match of the search query in the plugin name
    if ( strpos( $plugin_name, $search_query ) !== false ) {
        $score += 50;
    }

    // Exact match of the search query with the plugin slug
    if ( $plugin_slug === sanitize_title( $search_query ) ) {
        $score += 80;
    }

    // Partial match of the search query in the plugin slug
    if ( strpos( $plugin_slug, sanitize_title( $search_query ) ) !== false ) {
        $score += 40;
    }

    // Match individual search terms in the plugin slug
    foreach ( $search_terms as $term ) {
        $sanitized_term = sanitize_title( $term );
        if ( strpos( $plugin_slug, $sanitized_term ) !== false ) {
            $score += 15;
        }
    }

    // Match individual search terms in the plugin name
    foreach ( $search_terms as $term ) {
        if ( strpos( $plugin_name, $term ) !== false ) {
            $score += 10;
        }
    }

    // Match individual search terms in the plugin description
    foreach ( $search_terms as $term ) {
        if ( strpos( $plugin_description, $term ) !== false ) {
            $score += 5;
        }
    }

    return $score;
}

// Normalize the plugin data to ensure all required keys are present
function repo_man_normalize_plugin_data( $plugin ) {
    $defaults = array(
        'name'            => 'Unknown Plugin',
        'slug'            => 'unknown-slug',
        'author'          => 'Unknown Author',
        'author_url'      => '',
        'version'         => '1.0.0',
        'rating'          => 0,
        'num_ratings'     => 0,
        'url'             => '',
        'last_updated'    => 'Unknown',
        'active_installs' => 0,
        'description'     => 'No description available.',
        'icon_url'        => '',
    );
    return array_merge( $defaults, $plugin );
}

// Prepare the plugin for display by WordPress
function repo_man_prepare_plugin_for_display( $plugin ) {
    // Ensure the plugin data is normalized first
    $plugin = repo_man_normalize_plugin_data( $plugin );

    // Sanitize and escape the plugin data
    return array(
        'name'              => sanitize_text_field( $plugin['name'] ),
        'slug'              => sanitize_title( $plugin['slug'] ),
        'version'           => sanitize_text_field( $plugin['version'] ),
        'author'            => sanitize_text_field( $plugin['author'] ),
        'author_profile'    => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'contributors'      => array(),
        'requires'          => '',
        'tested'            => '',
        'requires_php'      => '',
        'rating'            => intval( $plugin['rating'] ) * 20, // Convert rating to a percentage
        'num_ratings'       => intval( $plugin['num_ratings'] ),
        'support_threads'   => 0,
        'support_threads_resolved' => 0,
        'active_installs'   => intval( $plugin['active_installs'] ),
        'short_description' => wp_kses_post( $plugin['description'] ),
        'sections'          => array(
            'description' => wp_kses_post( $plugin['description'] ),
        ),
        'download_link'     => ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '',
        'homepage'          => ! empty( $plugin['url'] ) ? esc_url( $plugin['url'] ) : '',
        'tags'              => array(),
        'donate_link'       => '',
        'icons'             => array(
            'default' => ! empty( $plugin['icon_url'] ) ? esc_url( $plugin['icon_url'] ) : '',
        ),
        'banners'           => array(),
        'banners_rtl'       => array(),
        'last_updated'      => sanitize_text_field( $plugin['last_updated'] ),
        'added'             => '',
        'external'          => true,
    );
}

// Fetch plugin data with caching via transients
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

// Ref: ChatGPT
