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

// fetch plugin data from json file with secure handling and fallback for missing keys
function repo_man_get_plugins_data() {
    // define the file path securely
    $plugin_dir = plugin_dir_path( __FILE__ );
    $file       = realpath( $plugin_dir . 'plugin-repos.json' );

    // ensure the file is within the plugin directory
    if ( ! $file || strpos( $file, realpath( $plugin_dir ) ) !== 0 || ! is_readable( $file ) ) {
        return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing or unreadable.', 'repo-man' ) );
    }

    // attempt to read json contents
    $content = file_get_contents( $file );
    if ( false === $content ) {
        return new WP_Error( 'file_unreadable', __( 'Error: The plugin-repos.json file could not be read.', 'repo-man' ) );
    }

    // attempt to decode json content
    $plugins = json_decode( $content, true );

    // handle json decoding errors
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'file_malformed', sprintf( __( 'Error: The plugin-repos.json file is malformed (%s).', 'repo-man' ), json_last_error_msg() ) );
    }

    // check if the file is empty or contains no data
    if ( empty( $plugins ) ) {
        return new WP_Error( 'file_empty', __( 'Error: The plugin-repos.json file is empty or contains no plugins.', 'repo-man' ) );
    }

    // ensure each plugin has the required keys with default values as fallback
    foreach ( $plugins as &$plugin ) {
        $plugin['slug']            = isset( $plugin['slug'] ) ? sanitize_title( $plugin['slug'] ) : 'unknown-slug';
        $plugin['url']             = isset( $plugin['url'] ) ? esc_url_raw( $plugin['url'] ) : '#';
        $plugin['name']            = isset( $plugin['name'] ) ? sanitize_text_field( $plugin['name'] ) : __( 'Unknown Plugin', 'repo-man' );
        $plugin['icon_url']        = isset( $plugin['icon_url'] ) ? esc_url_raw( $plugin['icon_url'] ) : '';
        $plugin['description']     = isset( $plugin['description'] ) ? wp_kses_post( $plugin['description'] ) : __( 'No description available.', 'repo-man' );
        $plugin['author']          = isset( $plugin['author'] ) ? sanitize_text_field( $plugin['author'] ) : __( 'Unknown Author', 'repo-man' );
        $plugin['author_url']      = isset( $plugin['author_url'] ) ? esc_url_raw( $plugin['author_url'] ) : '#';
        $plugin['rating']          = isset( $plugin['rating'] ) ? intval( $plugin['rating'] ) : 0;
        $plugin['num_ratings']     = isset( $plugin['num_ratings'] ) ? intval( $plugin['num_ratings'] ) : 0;
        $plugin['last_updated']    = isset( $plugin['last_updated'] ) ? sanitize_text_field( $plugin['last_updated'] ) : __( 'Unknown', 'repo-man' );
        $plugin['active_installs'] = isset( $plugin['active_installs'] ) ? intval( $plugin['active_installs'] ) : 0;
        $plugin['compatible']      = isset( $plugin['compatible'] ) ? (bool) $plugin['compatible'] : false;
    }
    unset( $plugin ); // break reference with the last element

    // return cleaned-up plugins array
    return $plugins;
}

// function to display admin notices
function repo_man_display_admin_notice( $message ) {
    ?>
    <div class="notice notice-error">
        <p><strong><?php echo wp_kses_post( $message ); ?></strong></p>
    </div>
    <?php
}

// extend search results to include plugins from the json file and prioritize them when relevant
add_filter( 'plugins_api_result', 'repo_man_extend_search_results', 12, 3 );
function repo_man_extend_search_results( $res, $action, $args ) {
    // return early if not searching for plugins
    if ( 'query_plugins' !== $action || empty( $args->search ) ) {
        return $res;
    }

    // sanitize the search query and preserve spaces
    $search_query = sanitize_text_field( urldecode( $args->search ) );

    // fetch plugin data from transient cache
    $plugins = repo_man_get_plugins_data_with_cache();

    // return original results if an error occurred or there are no plugins
    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $res;
    }

    // normalize plugin data
    $plugins = array_map( 'repo_man_normalize_plugin_data', $plugins );

    // initialize an array to hold matching plugins
    $matching_plugins = array();

    // loop through each plugin and score it based on search query
    foreach ( $plugins as $plugin ) {
        $score = repo_man_calculate_match_score( $plugin, $search_query );
        if ( $score > 0 ) {
            $plugin['match_score'] = $score;
            $matching_plugins[]    = $plugin;
        }
    }

    // if no matching plugins return original results
    if ( empty( $matching_plugins ) ) {
        return $res;
    }

    // sort matching plugins by score in descending order
    usort( $matching_plugins, function( $a, $b ) {
        return $b['match_score'] - $a['match_score'];
    } );

    // prepare matching plugins for display
    $formatted_plugins = array_map( 'repo_man_prepare_plugin_for_display', $matching_plugins );

    // remove duplicates from original results
    $original_plugins = $res->plugins;
    $original_plugins = array_filter( $original_plugins, function( $plugin ) use ( $formatted_plugins ) {
        return ! in_array( $plugin['slug'], wp_list_pluck( $formatted_plugins, 'slug' ), true );
    } );

    // merge and boost matching plugins
    $res->plugins         = array_merge( $formatted_plugins, $original_plugins );
    $res->info['results'] = count( $res->plugins );

    return $res;
}

// calculate match score based on search query
function repo_man_calculate_match_score( $plugin, $search_query ) {
    $score = 0;

    // prepare plugin data and search query for matching
    $plugin_name        = strtolower( $plugin['name'] );
    $plugin_slug        = strtolower( $plugin['slug'] );
    $plugin_description = strtolower( $plugin['description'] );
    $search_query       = strtolower( $search_query );
    $search_terms       = array_filter( explode( ' ', $search_query ) );

    // exact match of full search query in plugin name (highest score)
    if ( $plugin_name === $search_query ) {
        $score += 100;
    }

    // partial match of search query in plugin name
    if ( false !== strpos( $plugin_name, $search_query ) ) {
        $score += 50;
    }

    // exact match of search query with plugin slug
    if ( $plugin_slug === sanitize_title( $search_query ) ) {
        $score += 80;
    }

    // partial match of search query in plugin slug
    if ( false !== strpos( $plugin_slug, sanitize_title( $search_query ) ) ) {
        $score += 40;
    }

    // match individual search terms in plugin slug
    foreach ( $search_terms as $term ) {
        $sanitized_term = sanitize_title( $term );
        if ( false !== strpos( $plugin_slug, $sanitized_term ) ) {
            $score += 15;
        }
    }

    // match individual search terms in plugin name
    foreach ( $search_terms as $term ) {
        if ( false !== strpos( $plugin_name, $term ) ) {
            $score += 10;
        }
    }

    // match individual search terms in plugin description
    foreach ( $search_terms as $term ) {
        if ( false !== strpos( $plugin_description, $term ) ) {
            $score += 5;
        }
    }

    return $score;
}

// normalize plugin data
function repo_man_normalize_plugin_data( $plugin ) {
    $defaults = array(
        'name'            => __( 'Unknown Plugin', 'repo-man' ),
        'slug'            => 'unknown-slug',
        'author'          => __( 'Unknown Author', 'repo-man' ),
        'author_url'      => '',
        'version'         => '1.0.0',
        'rating'          => 0,
        'num_ratings'     => 0,
        'url'             => '',
        'last_updated'    => __( 'Unknown', 'repo-man' ),
        'active_installs' => 0,
        'description'     => __( 'No description available.', 'repo-man' ),
        'icon_url'        => '',
    );
    return wp_parse_args( $plugin, $defaults );
}

// prepare plugin tiles for display
function repo_man_prepare_plugin_for_display( $plugin ) {
    // ensure plugin data is normalized first
    $plugin = repo_man_normalize_plugin_data( $plugin );

    // sanitize and escape plugin data
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
        'rating'            => intval( $plugin['rating'] ) * 20, // convert rating to a percentage
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

// fetch plugin data via transients
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
