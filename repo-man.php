<?php
/*
Plugin Name: Repo Man
Plugin URI: https://www.littlebizzy.com/plugins/repo-man
Description: Install public repos to WordPress
Version: 1.4.0
Author: LittleBizzy
Author URI: https://www.littlebizzy.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: repo-man
GitHub Plugin URI: littlebizzy/repo-man
Primary Branch: master
*/

// prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// disable wordpress.org updates for this plugin
add_filter( 'gu_override_dot_org', function( $overrides ) {
    $overrides[] = 'repo-man/repo-man.php';
    return $overrides;
}, 999 );

// load plugin textdomain for translations
function repo_man_load_textdomain() {
    load_plugin_textdomain( 'repo-man', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'repo_man_load_textdomain' );

// fetch plugin data from json file with secure handling and fallback for missing keys
function repo_man_get_plugins_data() {
    $plugin_dir = plugin_dir_path( __FILE__ ); // define plugin directory
    $file       = realpath( $plugin_dir . 'plugin-repos.json' ); // get absolute path to json file

    // check if file exists and is readable
    if ( ! $file || strpos( $file, realpath( $plugin_dir ) ) !== 0 || ! is_readable( $file ) ) {
        return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing or unreadable.', 'repo-man' ) );
    }

    $content = file_get_contents( $file ); // read file contents
    if ( false === $content ) {
        return new WP_Error( 'file_unreadable', __( 'Error: The plugin-repos.json file could not be read.', 'repo-man' ) );
    }

    $plugins = json_decode( $content, true ); // decode json content

    // check for json errors
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'file_malformed', sprintf( __( 'Error: The plugin-repos.json file is malformed (%s).', 'repo-man' ), json_last_error_msg() ) );
    }

    // check if plugins data is empty
    if ( empty( $plugins ) ) {
        return new WP_Error( 'file_empty', __( 'Error: The plugin-repos.json file is empty or contains no plugins.', 'repo-man' ) );
    }

    // sanitize plugin data fields
    foreach ( $plugins as &$plugin ) {
        $plugin['slug']            = isset( $plugin['slug'] ) ? sanitize_title( $plugin['slug'] ) : 'unknown-slug';
        $plugin['repo']            = isset( $plugin['repo'] ) ? sanitize_text_field( $plugin['repo'] ) : '';
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

    return $plugins; // return cleaned up plugins array
}

// fetch plugin data with caching via transients
function repo_man_get_plugins_data_with_cache() {
    $plugins = get_transient( 'repo_man_plugins' ); // check if cached
    if ( false === $plugins ) {
        $plugins = repo_man_get_plugins_data(); // get fresh data if cache is expired
        if ( ! is_wp_error( $plugins ) ) {
            set_transient( 'repo_man_plugins', $plugins, HOUR_IN_SECONDS ); // cache for an hour
        } else {
            error_log( 'Repo Man error: ' . $plugins->get_error_message() ); // log error if any
        }
    }
    return $plugins; // return cached or fresh plugins data
}

// handle the plugin information display
add_filter( 'plugins_api', 'repo_man_plugins_api_handler', 99, 3 );
function repo_man_plugins_api_handler( $result, $action, $args ) {
    if ( 'plugin_information' !== $action ) {
        return $result; // return if not fetching plugin information
    }

    $plugins = repo_man_get_plugins_data_with_cache(); // get plugin data

    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $result; // return original result if there is an error
    }

    foreach ( $plugins as $plugin ) {
        if ( $plugin['slug'] === $args->slug ) { // check if plugin matches the requested slug
            $plugin_info = repo_man_prepare_plugin_information( $plugin );

            // store the plugin slug in a transient for later use
            set_transient( 'repo_man_installing_plugin', $plugin['slug'], 15 * MINUTE_IN_SECONDS );

            return (object) $plugin_info; // return plugin information
        }
    }

    return $result; // return original result if no match found
}

// prepare plugin information for the plugin installer
function repo_man_prepare_plugin_information( $plugin ) {
    $version       = isset( $plugin['version'] ) ? sanitize_text_field( $plugin['version'] ) : '1.0.0'; // default version
    $download_link = repo_man_get_plugin_download_link( $plugin ); // get download link

    // prepare plugin data array
    $plugin_data = array(
        'id'                => $plugin['slug'],
        'type'              => 'plugin',
        'name'              => sanitize_text_field( $plugin['name'] ),
        'slug'              => sanitize_title( $plugin['slug'] ),
        'version'           => $version,
        'author'            => wp_kses_post( $plugin['author'] ),
        'author_profile'    => $plugin['author_url'],
        'requires'          => '5.0',
        'tested'            => get_bloginfo( 'version' ),
        'requires_php'      => '7.0',
        'sections'          => array(
            'description' => wp_kses_post( $plugin['description'] ),
        ),
        'download_link'     => $download_link,
        'package'           => $download_link,
        'last_updated'      => sanitize_text_field( $plugin['last_updated'] ),
        'homepage'          => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'short_description' => wp_kses_post( $plugin['description'] ),
        'icons'             => array(
            'default' => ! empty( $plugin['icon_url'] ) ? esc_url( $plugin['icon_url'] ) : '',
        ),
        'external'          => false,
        'plugin'            => $plugin['slug'] . '/' . $plugin['slug'] . '.php',
    );

    return (object) $plugin_data; // return plugin data as object
}

// get the download link for the plugin from github with automatic branch detection
function repo_man_get_plugin_download_link( $plugin ) {
    if ( empty( $plugin['repo'] ) ) { // check if repo is empty
        error_log( 'Repo Man error: repository owner/repo is empty for plugin ' . $plugin['slug'] );
        return '';
    }

    // split owner and repo from the repo field
    $parts = explode( '/', $plugin['repo'] );
    if ( count( $parts ) < 2 ) { // validate repo format
        error_log( 'Repo Man error: Invalid repository owner/repo format for plugin ' . $plugin['slug'] );
        return '';
    }

    $owner = $parts[0]; // github repo owner
    $repo  = $parts[1]; // github repo name

    // check if default branch is cached
    $cache_key = 'repo_man_default_branch_' . $owner . '_' . $repo;
    $default_branch = get_transient( $cache_key );

    if ( false === $default_branch ) {
        // attempt to retrieve the default branch from github api
        $api_url = "https://api.github.com/repos/{$owner}/{$repo}";
        $response = wp_remote_get( $api_url, array(
            'headers' => array(
                'User-Agent' => 'Repo Man Plugin', // github api requires a user-agent header
            ),
            'timeout' => 30, // increased timeout for api request
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Repo Man error: Unable to connect to GitHub API for plugin ' . $plugin['slug'] . '. Error: ' . $response->get_error_message() );
            $default_branch = 'master'; // fallback to master branch
        } else {
            $body = wp_remote_retrieve_body( $response ); // get response body
            $data = json_decode( $body, true ); // decode response body

            if ( json_last_error() === JSON_ERROR_NONE && isset( $data['default_branch'] ) ) {
                $default_branch = sanitize_text_field( $data['default_branch'] ); // set default branch
            } else {
                error_log( 'Repo Man error: Unable to retrieve default branch for plugin ' . $plugin['slug'] . '. JSON Error: ' . json_last_error_msg() );
                $default_branch = 'master'; // fallback to master
            }
        }

        // cache default branch for 12 hours
        set_transient( $cache_key, $default_branch, 12 * HOUR_IN_SECONDS );
    }

    // construct download link using the default branch
    $download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$default_branch}.zip";

    // fetch content to verify link existence and handle redirects
    $get_response = wp_remote_get( $download_link, array(
        'headers' => array(
            'User-Agent' => 'Repo Man Plugin',
        ),
        'timeout' => 30, // increased timeout for download link verification
    ) );

    if ( is_wp_error( $get_response ) || wp_remote_retrieve_response_code( $get_response ) !== 200 ) {
        $error_message = is_wp_error( $get_response ) ? $get_response->get_error_message() : wp_remote_retrieve_response_message( $get_response );
        error_log( "Repo Man error: Unable to access ZIP file at {$download_link} for plugin {$plugin['slug']}. Response: " . print_r( $error_message, true ) );

        // fallback to master or main branch
        return repo_man_fallback_download_link( $owner, $repo, $default_branch, $plugin['slug'] );
    }

    return esc_url_raw( $download_link ); // return the download link
}

// fallback to master or main branch if default branch download fails
function repo_man_fallback_download_link( $owner, $repo, $default_branch, $slug ) {
    if ( 'master' !== $default_branch ) {
        $fallback_branch = 'master';
        $fallback_download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$fallback_branch}.zip";

        $fallback_get_response = wp_remote_get( $fallback_download_link, array(
            'headers' => array(
                'User-Agent' => 'Repo Man Plugin',
            ),
            'timeout' => 30, // timeout for fallback download link
        ) );

        if ( ! is_wp_error( $fallback_get_response ) && wp_remote_retrieve_response_code( $fallback_get_response ) === 200 ) {
            // update the cached branch and return fallback link
            set_transient( 'repo_man_default_branch_' . $owner . '_' . $repo, $fallback_branch, 12 * HOUR_IN_SECONDS );
            return esc_url_raw( $fallback_download_link );
        }
    }

    // final fallback to main if master doesn't work
    $fallback_branch = 'main';
    $fallback_download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$fallback_branch}.zip";
    $fallback_get_response = wp_remote_get( $fallback_download_link, array(
        'headers' => array(
            'User-Agent' => 'Repo Man Plugin',
        ),
        'timeout' => 30,
    ) );

    if ( ! is_wp_error( $fallback_get_response ) && wp_remote_retrieve_response_code( $fallback_get_response ) === 200 ) {
        return esc_url_raw( $fallback_download_link );
    }

    error_log( "Repo Man error: Unable to access ZIP file at {$fallback_download_link} for plugin {$slug}." );
    return ''; // return empty string if all fallbacks fail
}

// extend search results to include plugins from json file and prioritize them when relevant
add_filter( 'plugins_api_result', 'repo_man_extend_search_results', 12, 3 );
function repo_man_extend_search_results( $res, $action, $args ) {
    if ( 'query_plugins' !== $action || empty( $args->search ) ) {
        return $res; // return original result if not searching plugins
    }

    $search_query = sanitize_text_field( urldecode( $args->search ) ); // sanitize search query
    $plugins      = repo_man_get_plugins_data_with_cache(); // get plugin data

    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $res; // return original result if no plugin data
    }

    $plugins          = array_map( 'repo_man_normalize_plugin_data', $plugins ); // normalize plugin data
    $matching_plugins = array();

    foreach ( $plugins as $plugin ) {
        $score = repo_man_calculate_match_score( $plugin, $search_query ); // calculate match score
        if ( $score > 0 ) {
            $plugin['match_score'] = $score;
            $matching_plugins[]    = $plugin;
        }
    }

    if ( empty( $matching_plugins ) ) {
        return $res; // return original result if no matches
    }

    // sort matching plugins by score
    usort( $matching_plugins, function( $a, $b ) {
        return $b['match_score'] - $a['match_score'];
    } );

    $formatted_plugins = array_map( 'repo_man_prepare_plugin_for_display', $matching_plugins ); // format plugins

    // remove duplicates and merge new results with originals
    $original_plugins = $res->plugins;
    $original_plugins = array_filter( $original_plugins, function( $plugin ) use ( $formatted_plugins ) {
        return ! in_array( $plugin['slug'], wp_list_pluck( $formatted_plugins, 'slug' ), true );
    } );

    $res->plugins        = array_merge( $formatted_plugins, $original_plugins );
    $res->info['results'] = count( $res->plugins ); // update result count

    return $res;
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
        'repo'            => '',
        'last_updated'    => __( 'Unknown', 'repo-man' ),
        'active_installs' => 0,
        'description'     => __( 'No description available.', 'repo-man' ),
        'icon_url'        => '',
    );
    return wp_parse_args( $plugin, $defaults ); // merge defaults with plugin data
}

// prepare plugin tiles for display
function repo_man_prepare_plugin_for_display( $plugin ) {
    $plugin        = repo_man_normalize_plugin_data( $plugin ); // normalize plugin data
    $download_link = repo_man_get_plugin_download_link( $plugin ); // get download link

    // prepare sanitized plugin data
    return array(
        'id'                            => $plugin['slug'],
        'type'                          => 'plugin',
        'name'                          => sanitize_text_field( $plugin['name'] ),
        'slug'                          => sanitize_title( $plugin['slug'] ),
        'version'                       => sanitize_text_field( $plugin['version'] ),
        'author'                        => sanitize_text_field( $plugin['author'] ),
        'author_profile'                => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'rating'                        => intval( $plugin['rating'] ) * 20, // convert rating to percentage
        'num_ratings'                   => intval( $plugin['num_ratings'] ),
        'active_installs'               => intval( $plugin['active_installs'] ),
        'short_description'             => wp_kses_post( $plugin['description'] ),
        'sections'                      => array(
            'description' => wp_kses_post( $plugin['description'] ),
        ),
        'download_link'                 => $download_link,
        'homepage'                      => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'icons'                         => array(
            'default' => ! empty( $plugin['icon_url'] ) ? esc_url( $plugin['icon_url'] ) : '',
        ),
        'last_updated'                  => sanitize_text_field( $plugin['last_updated'] ),
        'plugin'                        => $plugin['slug'] . '/' . $plugin['slug'] . '.php',
    );
}

// handle the renaming of the plugin folder after installation
add_filter( 'upgrader_post_install', 'repo_man_rename_plugin_folder', 10, 3 );

function repo_man_rename_plugin_folder( $response, $hook_extra, $result ) {
    // only proceed if installing a plugin
    if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {

        // retrieve the desired slug from transient
        $plugin_slug = get_transient( 'repo_man_installing_plugin' );

        if ( ! $plugin_slug ) {
            return $response; // nothing to do
        }

        // extract the destination from the result array
        if ( is_array( $result ) && isset( $result['destination'] ) ) {
            $plugin_path = $result['destination'];
        } else {
            error_log( 'Repo Man error: Invalid result format for plugin installation.' );
            return $response;
        }

        // define the new plugin folder path
        $new_plugin_path = trailingslashit( dirname( $plugin_path ) ) . $plugin_slug;

        // check if the source is already correctly named to prevent multiple renames
        if ( basename( $plugin_path ) !== $plugin_slug ) {

            // rename the source directory to the desired slug
            if ( rename( $plugin_path, $new_plugin_path ) ) {
                error_log( 'Renamed plugin folder from ' . $plugin_path . ' to ' . $new_plugin_path );

                // update the response to reflect the new path
                $response = $new_plugin_path;
            } else {
                error_log( 'Failed to rename plugin folder from ' . $plugin_path . ' to ' . $new_plugin_path );
                return new WP_Error( 'rename_failed', __( 'Could not rename plugin directory.', 'repo-man' ) );
            }
        }
    }

    // delete the transient as it's no longer needed
    delete_transient( 'repo_man_installing_plugin' );

    return $response;
}

// Ref: ChatGPT
