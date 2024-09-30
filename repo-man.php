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
add_filter( 'install_plugins_tabs', 'repo_man_adjust_repos_tab_position', 12 );
function repo_man_adjust_repos_tab_position( $tabs ) {
    // Define the "Public Repos" tab
    $public_repos_tab = array( 'repos' => _x( 'Public Repos', 'Tab title', 'repo-man' ) );

    // Check if a search query is active, sanitize the input
    if ( ! empty( $_GET['s'] ) && ! is_null( sanitize_text_field( $_GET['s'] ) ) ) {
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

// Extend the search results to include plugins from the JSON file and place them first
add_filter( 'plugins_api_result', 'repo_man_extend_search_results', 12, 3 );
function repo_man_extend_search_results( $res, $action, $args ) {
    // Return early if not searching for plugins
    if ( 'query_plugins' !== $action || empty( $args->search ) ) {
        return $res;
    }

    // Sanitize the search query, preserving spaces
    $search_query = sanitize_textarea_field( $args->search );

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

    // Step 1: First, check for an exact match of the full search term in the name or description
    $matching_plugins = array_filter( $plugins, function( $plugin ) use ( $search_query ) {
        return stripos( $plugin['name'], $search_query ) !== false || stripos( $plugin['description'], $search_query ) !== false;
    });

    // Step 2: If no exact match, fallback to individual word matching
    if ( empty( $matching_plugins ) ) {
        $matching_plugins = array_filter( $plugins, function( $plugin ) use ( $search_terms ) {
            foreach ( $search_terms as $term ) {
                if ( stripos( $plugin['name'], $term ) !== false || stripos( $plugin['description'], $term ) !== false ) {
                    return true;
                }
            }
            return false;
        });
    }

    // If no matching plugins are found, return the original results
    if ( empty( $matching_plugins ) ) {
        return $res;
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
        'author_url'        => '#',
        'version'           => '1.0.0',
        'rating'            => 0,
        'num_ratings'       => 0,
        'url'               => '#',
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
        'author_profile'    => esc_url( $plugin['author_url'] ),
        'version'           => esc_html( $plugin['version'] ),
        'rating'            => intval( $plugin['rating'] ) * 20, // Convert rating to a percentage
        'num_ratings'       => intval( $plugin['num_ratings'] ),
        'homepage'          => esc_url( $plugin['url'] ),
        'download_link'     => esc_url( $plugin['url'] ),
        'last_updated'      => esc_html( $plugin['last_updated'] ),
        'active_installs'   => intval( $plugin['active_installs'] ),
        'short_description' => esc_html( $plugin['description'] ),
        'icons'             => [
            'default' => esc_url( $plugin['icon_url'] ),
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
        if ( ! $file || strpos( $file, plugin_dir_path( __FILE__ ) ) !== 0 || strpos( $file, ABSPATH ) !== 0 || ! is_readable( $file ) ) {
            return new WP_Error( 'file_missing', __( 'Error: The plugin-repos.json file is missing or unreadable.', 'repo-man' ) );
        }

        $content = file_get_contents( $file );
        if ( false === $content ) {
            return new WP_Error( 'file_unreadable', __( 'Error: The plugin-repos.json file could not be read.', 'repo-man' ) );
        }

        $plugins = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'file_malformed', sprintf( __( 'Error: The plugin-repos.json file is malformed (%s).', 'repo-man' ), json_last_error_msg() ) );
        }

        return $plugins;
    }
}

// Enqueue necessary JS files for plugin search
add_action( 'admin_enqueue_scripts', 'repo_man_enqueue_plugin_search_scripts' );
function repo_man_enqueue_plugin_search_scripts( $hook_suffix ) {
    if ( $hook_suffix === 'plugin-install.php' ) {
        wp_enqueue_script( 'plugin-install' );
        wp_enqueue_script( 'updates' ); // This helps with AJAX behavior
    }
}

// Inject custom search logic into the plugin search form
add_action( 'admin_footer-plugin-install.php', 'repo_man_inject_search_logic' );
function repo_man_inject_search_logic() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $(document).on('input', '#search-plugins', function() {
                var search_term = $(this).val();
                if (window.location.href.indexOf('tab=repos') !== -1) {
                    var url = window.location.href.split('?')[0] + '?tab=repos&s=' + encodeURIComponent(search_term);
                    window.history.replaceState({}, '', url);

                    // Trigger AJAX request to update plugin tiles
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'plugin_install_repos_search',
                            s: search_term,
                            repo_man_nonce: repoMan.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data.html) {
                                var newContent = $('<div>').html(response.data.html).find('#the-list').html();
                                $('#the-list').html(newContent);
                                tb_init('a.thickbox'); // Initialize Thickbox for modals
                                $('.install-now').off().on('click', function(e) {
                                    e.preventDefault();
                                    wp.updates.installPlugin({
                                        slug: $(this).data('slug')
                                    });
                                });
                            } else {
                                console.error('No HTML content returned in AJAX response');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX request failed:', status, error);
                        }
                    });
                }
            });
        });
    </script>
    <?php
}

// Handle the custom AJAX request for the Public Repos search
add_action( 'wp_ajax_plugin_install_repos_search', 'repo_man_handle_repos_search' );
add_action( 'wp_ajax_nopriv_plugin_install_repos_search', 'repo_man_handle_repos_search' );

function repo_man_handle_repos_search() {
    // Sanitize the search term
    $search_term = isset( $_POST['s'] ) ? sanitize_textarea_field( $_POST['s'] ) : '';

    // Verify nonce for security
    if ( ! isset( $_POST['repo_man_nonce'] ) || ! wp_verify_nonce( $_POST['repo_man_nonce'], 'repo_man_nonce_action' ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid nonce verification', 'repo-man' ) ) );
        wp_die();
    }

    // Fetch Repo Man plugins from JSON
    $repo_plugins = repo_man_get_plugins_data_with_cache();

    // Handle error for Repo Man plugin retrieval
    if ( is_wp_error( $repo_plugins ) ) {
        wp_send_json_error( [ 'message' => $repo_plugins->get_error_message() ] );
        wp_die();
    }

    // Filter Repo Man plugins by search term
    $matching_repo_plugins = array_filter( $repo_plugins, function( $plugin ) use ( $search_term ) {
        return stripos( $plugin['name'], $search_term ) !== false ||
               stripos( $plugin['description'], $search_term ) !== false;
    });

    // Fetch WordPress.org plugin search results
    $api = plugins_api( 'query_plugins', array(
        'search'   => $search_term,
        'page'     => 1,
        'per_page' => 36,
    ));

    if ( is_wp_error( $api ) ) {
        wp_send_json_error( [ 'message' => $api->get_error_message() ] );
        wp_die();
    }

    // Merge Repo Man and WordPress.org plugins
    $combined_plugins = array_merge(
        array_map( 'repo_man_prepare_plugin_for_display', $matching_repo_plugins ),
        $api->plugins
    );

    // Output the HTML for the plugins using the WP Plugin Install List Table
    if ( ! empty( $combined_plugins ) ) {
        $plugin_list_table = new WP_Plugin_Install_List_Table();
        $plugin_list_table->items = $combined_plugins;
        ob_start();
        $plugin_list_table->display();
        $output = ob_get_clean();
        wp_send_json_success( [ 'html' => $output ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'No plugins found.', 'repo-man' ) ] );
    }

    wp_die(); // Always end the AJAX request
}

// Ref: ChatGPT
