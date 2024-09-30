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

// Display content for the Repos tab using native plugin list rendering
add_action( 'install_plugins_repos', 'repo_man_display_repos_plugins', 12 );
function repo_man_display_repos_plugins() {
    // Fetch cached plugin data
    $plugins = repo_man_get_plugins_data_with_cache();
    $plugins_per_page = 36;

    // Handle error when fetching plugins data
    if ( is_wp_error( $plugins ) ) {
        repo_man_display_admin_notice( esc_html( $plugins->get_error_message() ) );
        return;
    }

    // Get current page number, defaulting to 1 if not provided, and sanitize input
    $paged = max( 1, absint( sanitize_text_field( $_GET['paged'] ?? 1 ) ) );
    $total_plugins = count( $plugins );
    $total_pages = ceil( $total_plugins / $plugins_per_page );
    $offset = ( $paged - 1 ) * $plugins_per_page;

    // Paginate the plugins array
    $paged_plugins = array_slice( $plugins, $offset, $plugins_per_page );

    // Display message if no plugins found
    if ( empty( $paged_plugins ) ) {
        echo '<p>' . esc_html__( 'No plugins available to display.', 'repo-man' ) . '</p>';
        return;
    }

    // Prepare each plugin for display (assuming this handles escaping)
    $paged_plugins = array_map( 'repo_man_prepare_plugin_for_display', $paged_plugins );

    // Instantiate the WP_Plugin_Install_List_Table class
    $plugin_list_table = new WP_Plugin_Install_List_Table();
    $plugin_list_table->items = $paged_plugins;

    // Set pagination arguments for the list table
    $plugin_list_table->set_pagination_args( [
        'total_items' => $total_plugins,
        'per_page'    => $plugins_per_page,
        'total_pages' => $total_pages,
    ]);

    // Display the plugin list table
    $plugin_list_table->display();
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
