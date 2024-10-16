/*
Plugin Name: RepoMan
Plugin URI: https://www.littlebizzy.com/plugins/repoman
Description: Install public repos to WordPress
Version: 1.6.0
Author: LittleBizzy
Author URI: https://www.littlebizzy.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: repoman
GitHub Plugin URI: littlebizzy/repoman
Primary Branch: master
*/

// prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// disable wordpress.org updates for this plugin
add_filter( 'gu_override_dot_org', function( $overrides ) {
    $overrides[] = 'repoman/repoman.php';
    return $overrides;
}, 999 );

// load plugin textdomain for translations
function repoman_load_textdomain() {
    load_plugin_textdomain( 'repoman', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'repoman_load_textdomain' );

// Scan the main plugin file for the 'GitHub Plugin URI' string
function scan_plugin_main_file_for_github_uri( $plugin_file ) {
    $github_uri_found = false;
    $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_file;

    // Check if the plugin file exists and is readable
    if ( file_exists( $plugin_file_path ) && is_readable( $plugin_file_path ) ) {
        $file_content = @file_get_contents( $plugin_file_path );
        
        // If the file couldn't be read, log the error and skip
        if ( false === $file_content ) {
            error_log("Failed to read plugin file: " . $plugin_file_path . ". This might be due to file permissions or corruption.");
        } else {
            // Check if the 'GitHub Plugin URI' string exists in the file (case-sensitive)
            if ( strpos( $file_content, 'GitHub Plugin URI' ) !== false ) {
                $github_uri_found = true;
            }
        }
    } else {
        // Log if the plugin file is not accessible
        error_log("Plugin file does not exist or is not readable: " . $plugin_file_path);
    }

    return $github_uri_found;
}

// dynamically block updates for plugins with 'GitHub Plugin URI'
function dynamic_block_plugin_updates( $overrides ) {
    // Get all installed plugins (active and inactive)
    $all_plugins = get_plugins();

    // Loop through each plugin and scan the main file for the 'GitHub Plugin URI' string
    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        // Scan the main plugin file for 'GitHub Plugin URI'
        if ( scan_plugin_main_file_for_github_uri( $plugin_file ) ) {
            $overrides[] = $plugin_file; // Add to overrides if 'GitHub Plugin URI' is found
        }
    }

    return $overrides;
}
add_filter( 'gu_override_dot_org', 'dynamic_block_plugin_updates', 999 );

// Ensure this applies even if plugins are deactivated
function dynamic_block_deactivated_plugin_updates( $transient ) {
    $overrides = apply_filters( 'gu_override_dot_org', [] );
    foreach ( $overrides as $plugin ) {
        if ( isset( $transient->response[ $plugin ] ) ) {
            unset( $transient->response[ $plugin ] );
        }
    }
    return $transient;
}
add_filter( 'site_transient_update_plugins', 'dynamic_block_deactivated_plugin_updates' );

// fetch plugin data from json file with secure handling and fallback for missing keys
function repoman_get_plugins_data() {
    // get the plugin directory path
    $plugin_dir = plugin_dir_path( __FILE__ );
    // resolve the full path of the json file
    $file = realpath( $plugin_dir . 'plugin-repos.json' );

    // check if the file exists and is within the plugin directory
    if ( ! $file || strpos( $file, realpath( $plugin_dir ) ) !== 0 ) {
        return new WP_Error( 'file_missing', __( 'Error: the plugin-repos.json file is missing or outside the plugin directory', 'repoman' ) );
    }

    // attempt to read the json file content directly
    $content = @file_get_contents( $file );
    if ( false === $content ) {
        return new WP_Error( 'file_unreadable', __( 'Error: the plugin-repos.json file could not be read', 'repoman' ) );
    }

    // decode the json content
    $plugins = json_decode( $content, true );

    // check for json decoding errors
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'file_malformed', sprintf( __( 'Error: the plugin-repos.json file is malformed (%s)', 'repoman' ), json_last_error_msg() ) );
    }

    // check if the decoded content is empty
    if ( empty( $plugins ) ) {
        return new WP_Error( 'file_empty', __( 'Error: the plugin-repos.json file is empty or contains no plugins', 'repoman' ) );
    }

    // loop through plugins to set defaults and sanitize data
    foreach ( $plugins as &$plugin ) {
        $plugin['slug']            = isset( $plugin['slug'] ) ? sanitize_title( $plugin['slug'] ) : 'unknown-slug';
        $plugin['repo']            = isset( $plugin['repo'] ) ? sanitize_text_field( $plugin['repo'] ) : '';
        $plugin['name']            = isset( $plugin['name'] ) ? sanitize_text_field( $plugin['name'] ) : __( 'unknown plugin', 'repoman' );
        $plugin['icon_url']        = isset( $plugin['icon_url'] ) ? esc_url_raw( $plugin['icon_url'] ) : '';
        $plugin['description']     = isset( $plugin['description'] ) ? wp_kses_post( $plugin['description'] ) : __( 'no description available', 'repoman' );
        $plugin['author']          = isset( $plugin['author'] ) ? sanitize_text_field( $plugin['author'] ) : __( 'unknown author', 'repoman' );
        $plugin['author_url']      = isset( $plugin['author_url'] ) ? esc_url_raw( $plugin['author_url'] ) : '#';
        $plugin['rating']          = isset( $plugin['rating'] ) ? intval( $plugin['rating'] ) : 0;
        $plugin['num_ratings']     = isset( $plugin['num_ratings'] ) ? intval( $plugin['num_ratings'] ) : 0;
        $plugin['last_updated']    = isset( $plugin['last_updated'] ) ? sanitize_text_field( $plugin['last_updated'] ) : __( 'unknown', 'repoman' );
        $plugin['active_installs'] = isset( $plugin['active_installs'] ) ? intval( $plugin['active_installs'] ) : 0;
        $plugin['compatible']      = isset( $plugin['compatible'] ) ? (bool) $plugin['compatible'] : false;
    }

    // return the plugin array
    return $plugins;
}

// fetch plugin data with caching via transients
function repoman_get_plugins_data_with_cache() {
    $plugins = get_transient( 'repoman_plugins' );

    // check if cached data exists
    if ( false === $plugins ) {
        $plugins = repoman_get_plugins_data();

        // set the transient cache if no errors
        if ( ! is_wp_error( $plugins ) ) {
            set_transient( 'repoman_plugins', $plugins, HOUR_IN_SECONDS );
        } else {
            // log error if fetching plugins failed
            error_log( 'RepoMan Error: ' . $plugins->get_error_message() );
        }
    }

    return $plugins;
}

// handle the plugin information display
function repoman_plugins_api_handler( $result, $action, $args ) {
    // check if action is for plugin information
    if ( 'plugin_information' !== $action ) {
        return $result;
    }

    // fetch plugin data with cache
    $plugins = repoman_get_plugins_data_with_cache();

    // return original result if there are errors or no plugins
    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $result;
    }

    // loop through plugins to find the matching slug
    foreach ( $plugins as $plugin ) {
        if ( $plugin['slug'] === $args->slug ) {
            // prepare plugin information
            $plugin_info = repoman_prepare_plugin_information( $plugin );

            // store the plugin slug in a transient
            set_transient( 'repoman_installing_plugin', $plugin['slug'], 15 * MINUTE_IN_SECONDS );

            return (object) $plugin_info;
        }
    }

    // return original result if no match is found
    return $result;
}
add_filter( 'plugins_api', 'repoman_plugins_api_handler', 99, 3 );

// prepare plugin information for the plugin installer
function repoman_prepare_plugin_information( $plugin ) {
    // set the plugin version and sanitize
    $version = isset( $plugin['version'] ) ? sanitize_text_field( $plugin['version'] ) : '1.0.0';
    // get the plugin download link
    $download_link = repoman_get_plugin_download_link( $plugin );

    // prepare the plugin data array
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

    // return plugin data as an object
    return (object) $plugin_data;
}

// get the download link for the plugin from github with automatic branch detection
function repoman_get_plugin_download_link( $plugin ) {
    // check if the repo field is empty
    if ( empty( $plugin['repo'] ) ) {
        error_log( 'RepoMan Error: repository owner/repo is empty for plugin ' . $plugin['slug'] );
        return '';
    }

    // split the owner and repo from the repo field
    $parts = explode( '/', $plugin['repo'] );
    if ( count( $parts ) < 2 ) {
        error_log( 'RepoMan Error: invalid repository owner/repo format for plugin ' . $plugin['slug'] );
        return '';
    }

    $owner = $parts[0];
    $repo  = $parts[1];

    // check if the default branch is already cached
    $cache_key = 'repoman_default_branch_' . $owner . '_' . $repo;
    $default_branch = get_transient( $cache_key );

    // if not cached, retrieve the default branch via github api
    if ( false === $default_branch ) {
        $api_url = "https://api.github.com/repos/{$owner}/{$repo}";

        $response = wp_remote_get( $api_url, array(
            'headers' => array(
                'user-agent' => 'RepoMan', // github api requires a user-agent header
            ),
            'timeout' => 30, // increased timeout
        ) );

        // handle connection errors
        if ( is_wp_error( $response ) ) {
            error_log( 'RepoMan Error: unable to connect to github api for plugin ' . $plugin['slug'] . '. error: ' . $response->get_error_message() );
            $default_branch = 'master'; // fallback to 'master'
        } else {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( json_last_error() === JSON_ERROR_NONE && isset( $data['default_branch'] ) ) {
                $default_branch = sanitize_text_field( $data['default_branch'] );
            } else {
                error_log( 'RepoMan Error: unable to retrieve default branch for plugin ' . $plugin['slug'] . '. json error: ' . json_last_error_msg() );
                $default_branch = 'master'; // fallback to 'master'
            }
        }

        // cache the default branch for 12 hours
        set_transient( $cache_key, $default_branch, 12 * HOUR_IN_SECONDS );
    }

    // construct the download link using the default branch
    $download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$default_branch}.zip";

    // fetch the actual content to verify link existence
    $get_response = wp_remote_get( $download_link, array(
        'headers' => array(
            'user-agent' => 'RepoMan',
        ),
        'timeout' => 30, // increased timeout for download link verification
    ) );

    // handle errors for invalid zip file
    if ( is_wp_error( $get_response ) || wp_remote_retrieve_response_code( $get_response ) !== 200 ) {
        $error_message = is_wp_error( $get_response ) ? $get_response->get_error_message() : wp_remote_retrieve_response_message( $get_response );
        error_log( "RepoMan Error: unable to access zip file at {$download_link} for plugin {$plugin['slug']}. response: " . print_r( $error_message, true ) );

        // attempt fallback to 'main' if default branch download failed
        if ( 'master' !== $default_branch ) {
            $fallback_branch = 'master';
            $fallback_download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$fallback_branch}.zip";

            $fallback_get_response = wp_remote_get( $fallback_download_link, array(
                'headers' => array(
                    'user-agent' => 'RepoMan',
                ),
                'timeout' => 30, // increased timeout for fallback download link verification
            ) );

            if ( ! is_wp_error( $fallback_get_response ) && wp_remote_retrieve_response_code( $fallback_get_response ) === 200 ) {
                $download_link = $fallback_download_link;
                $default_branch = $fallback_branch;

                // update the cached branch
                set_transient( $cache_key, $default_branch, 12 * HOUR_IN_SECONDS );
            } else {
                // final fallback to 'main'
                $fallback_branch = 'main';
                $fallback_download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$fallback_branch}.zip";

                $fallback_get_response_main = wp_remote_get( $fallback_download_link, array(
                    'headers' => array(
                        'user-agent' => 'RepoMan',
                    ),
                    'timeout' => 30, // increased timeout for final fallback download link verification
                ) );

                if ( ! is_wp_error( $fallback_get_response_main ) && wp_remote_retrieve_response_code( $fallback_get_response_main ) === 200 ) {
                    $download_link = $fallback_download_link;
                    $default_branch = $fallback_branch;

                    // update the cached branch
                    set_transient( $cache_key, $default_branch, 12 * HOUR_IN_SECONDS );
                } else {
                    error_log( "RepoMan Error: unable to access zip file at {$fallback_download_link} for plugin {$plugin['slug']}." );
                    return ''; // unable to find a valid zip file
                }
            }
        } else {
            // attempt to fallback to 'main' if 'master' was already the default branch
            $fallback_branch = 'main';
            $fallback_download_link = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$fallback_branch}.zip";

            $fallback_get_response_main = wp_remote_get( $fallback_download_link, array(
                'headers' => array(
                    'user-agent' => 'RepoMan',
                ),
                'timeout' => 30, // increased timeout for final fallback download link verification
            ) );

            if ( ! is_wp_error( $fallback_get_response_main ) && wp_remote_retrieve_response_code( $fallback_get_response_main ) === 200 ) {
                $download_link = $fallback_download_link;
                $default_branch = $fallback_branch;

                // update the cached branch
                set_transient( $cache_key, $default_branch, 12 * HOUR_IN_SECONDS );
            } else {
                error_log( "RepoMan Error: unable to access zip file at {$fallback_download_link} for plugin {$plugin['slug']}." );
                return ''; // unable to find a valid zip file
            }
        }
    }

    return esc_url_raw( $download_link );
}

// normalize plugin data
function repoman_normalize_plugin_data( $plugin ) {
    // set default values for plugin data
    $defaults = array(
        'name'            => __( 'Unknown Plugin', 'repoman' ),
        'slug'            => 'unknown-slug',
        'author'          => __( 'Unknown Author', 'repoman' ),
        'author_url'      => '',
        'version'         => '1.0.0',
        'rating'          => 0,
        'num_ratings'     => 0,
        'repo'            => '',
        'last_updated'    => __( 'Unknown', 'repoman' ),
        'active_installs' => 0,
        'description'     => __( 'No description available.', 'repoman' ),
        'icon_url'        => '',
    );

    // merge plugin data with defaults
    return wp_parse_args( $plugin, $defaults );
}

// calculate match score based on search query
function repoman_calculate_match_score( $plugin, $search_query ) {
    $score              = 0;
    $plugin_name        = strtolower( $plugin['name'] );
    $plugin_slug        = strtolower( $plugin['slug'] );
    $plugin_description = strtolower( $plugin['description'] );
    $search_query       = strtolower( $search_query );
    $search_terms       = array_filter( explode( ' ', $search_query ) );

    // exact match of plugin name and search query
    if ( $plugin_name === $search_query ) {
        $score += 100;
    }

    // partial match of search query in plugin name
    if ( false !== strpos( $plugin_name, $search_query ) ) {
        $score += 50;
    }

    // exact match of plugin slug and search query
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

// prepare plugin tiles for display
function repoman_prepare_plugin_for_display( $plugin ) {
    // normalize the plugin data
    $plugin = repoman_normalize_plugin_data( $plugin );
    // get the download link for the plugin
    $download_link = repoman_get_plugin_download_link( $plugin );

    // return an array with plugin information
    return array(
        'id'                            => $plugin['slug'],
        'type'                          => 'plugin',
        'name'                          => sanitize_text_field( $plugin['name'] ),
        'slug'                          => sanitize_title( $plugin['slug'] ),
        'version'                       => sanitize_text_field( $plugin['version'] ),
        'author'                        => sanitize_text_field( $plugin['author'] ),
        'author_profile'                => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'contributors'                  => array(),
        'requires'                      => '',
        'tested'                        => '',
        'requires_php'                  => '',
        'rating'                        => intval( $plugin['rating'] ) * 20, // convert rating to a percentage
        'num_ratings'                   => intval( $plugin['num_ratings'] ),
        'support_threads'               => 0,
        'support_threads_resolved'      => 0,
        'active_installs'               => intval( $plugin['active_installs'] ),
        'short_description'             => wp_kses_post( $plugin['description'] ),
        'sections'                      => array(
            'description' => wp_kses_post( $plugin['description'] ),
        ),
        'download_link'                 => $download_link,
        'downloaded'                    => true,
        'homepage'                      => ! empty( $plugin['author_url'] ) ? esc_url( $plugin['author_url'] ) : '',
        'tags'                          => array(),
        'donate_link'                   => '',
        'icons'                         => array(
            'default' => ! empty( $plugin['icon_url'] ) ? esc_url( $plugin['icon_url'] ) : '',
        ),
        'banners'                       => array(),
        'banners_rtl'                   => array(),
        'last_updated'                  => sanitize_text_field( $plugin['last_updated'] ),
        'added'                         => '',
        'external'                      => false,
        'package'                       => $download_link,
        'plugin'                        => $plugin['slug'] . '/' . $plugin['slug'] . '.php',
    );
}

// handle the renaming of the plugin folder after installation
function repoman_rename_plugin_folder( $response, $hook_extra, $result ) {
    // only proceed if installing a plugin
    if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {

        // retrieve the desired slug from transient
        $plugin_slug = get_transient( 'repoman_installing_plugin' );

        if ( ! $plugin_slug ) {
            return $response; // nothing to do
        }

        // extract the destination from the result array
        if ( is_array( $result ) && isset( $result['destination'] ) ) {
            $plugin_path = $result['destination'];
        } else {
            error_log( 'RepoMan Error: invalid result format for plugin installation' );
            return $response;
        }

        // define the new plugin folder path
        $new_plugin_path = trailingslashit( dirname( $plugin_path ) ) . $plugin_slug;

        // check if the source is already correctly named to prevent multiple renames
        if ( basename( $plugin_path ) !== $plugin_slug ) {

            // rename the source directory to the desired slug
            if ( rename( $plugin_path, $new_plugin_path ) ) {
                error_log( 'renamed plugin folder from ' . $plugin_path . ' to ' . $new_plugin_path );

                // update the response to reflect the new path
                $response = $new_plugin_path;
            } else {
                error_log( 'failed to rename plugin folder from ' . $plugin_path . ' to ' . $new_plugin_path );
                return new WP_Error( 'rename_failed', __( 'could not rename plugin directory', 'repoman' ) );
            }
        }
    }

    // delete the transient as it's no longer needed
    delete_transient( 'repoman_installing_plugin' );

    return $response;
}
add_filter( 'upgrader_post_install', 'repoman_rename_plugin_folder', 10, 3 );

// extend search results to include plugins from the json file and prioritize them when relevant
function repoman_extend_search_results( $res, $action, $args ) {
    // return early if not a query_plugins action or search query is empty
    if ( 'query_plugins' !== $action || empty( $args->search ) ) {
        return $res;
    }

    // sanitize the search query
    $search_query = sanitize_text_field( urldecode( $args->search ) );
    $plugins      = repoman_get_plugins_data_with_cache();

    // return original results if there was an error or no plugins found
    if ( is_wp_error( $plugins ) || empty( $plugins ) ) {
        return $res;
    }

    // normalize plugin data and prepare matching plugins array
    $plugins          = array_map( 'repoman_normalize_plugin_data', $plugins );
    $matching_plugins = array();

    // loop through plugins to calculate match score
    foreach ( $plugins as $plugin ) {
        $score = repoman_calculate_match_score( $plugin, $search_query );
        if ( $score > 0 ) {
            $plugin['match_score'] = $score;
            $matching_plugins[]    = $plugin;
        }
    }

    // return original results if no matching plugins found
    if ( empty( $matching_plugins ) ) {
        return $res;
    }

    // sort matching plugins by score in descending order
    usort( $matching_plugins, function( $a, $b ) {
        return $b['match_score'] - $a['match_score'];
    } );

    // prepare formatted plugins for display
    $formatted_plugins = array_map( 'repoman_prepare_plugin_for_display', $matching_plugins );

    // filter out original plugins that match the slugs of the formatted plugins
    $original_plugins = $res->plugins;
    $original_plugins = array_filter( $original_plugins, function( $plugin ) use ( $formatted_plugins ) {
        return ! in_array( $plugin['slug'], wp_list_pluck( $formatted_plugins, 'slug' ), true );
    } );

    // merge formatted plugins with the original ones
    $res->plugins        = array_merge( $formatted_plugins, $original_plugins );
    $res->info['results'] = count( $res->plugins );

    return $res;
}
add_filter( 'plugins_api_result', 'repoman_extend_search_results', 12, 3 );

// Ref: ChatGPT
