# Repo Man

Install public repos to WordPress

## Changelog

### 1.4.4
- added `boogah/biscotti`
- added `zouloux/bowl`

### 1.4.3
- added `MisoAI/miso-wordpress-plugin`
- added `pods-framework/pods`
- added `thecodezone/dt-home`

### 1.4.2
- changed from 2-space to 4-space json indentation
- added `pressbooks/pressbooks`
- added `mihdan/recrawler`
- added `WPCloudDeploy/wp-cloud-deploy`
- added `wp-sms/wp-sms`

### 1.4.1
- added `littlebizzy/multisite-billing-manager`
- tweaked dummy data in the json file for consistency

### 1.4.0
- installing plugins directly from GitHub now supported based on the `repo` field in the `plugin-repos.json` file
- GitHub repos will be automatically scanned for default, `master` and `main` fallback branches
- `url` field in json data changed to `repo` field with owner/repo syntax
- refined error handling if json file has parsing issues
- plugin folders will be force renamed during installation to match `repo` field (if folder not exists)
- various other code refactoring and cleanup

### 1.3.0
- simplified approach focused on plugin search results only (removed Public Repos tab)
- greatly improved search query matching rules with new scoring function
- tweaked logic for plugin data normalization and sanitizing
- added textdomain `repo-man` for translation support

### 1.2.4
- added error handling in case of empty `plugin-repos.json` file
- added/changed to `wp_kses_post()` from `esc_html()` for admin notices

### 1.2.3
- added `urldecode()` inside the `repo_man_extend_search_results` function

### 1.2.2 
- Public Repos tab position is now dynamic depending on Search Results tab being active or not
- various minor security enhancements
- minor translation enhancements
- transitional release to prepare for 1.3.0 changes

### 1.2.1
- changed 3 actions/filters to use priority `12`

### 1.2.0
- added LittleBizzy icon from GitHub to appropriate plugins in `plugin-repos.json`
- integrated json list into the native plugin search results (json list plugins should appear first)

### 1.1.1
- added `littlebizzy/disable-feeds`
- sorted `plugin-repos.json` in alphabetical order

### 1.1.0
- enhanced json file location security using `realpath()`
- added error handing for json file and admin notices for clear user feedback
- more efficient rendering of top/bottom pagination
- display 36 plugins instead of 10 per page
- added fallback values for missing keys in the plugin data (e.g., slug, name, icon_url, author) to ensure that all plugins display properly even if some data is missing
- improved structure and display of plugin cards, including star ratings, action buttons, and compatibility information
- removed forced redirect to  "Repos" tab as it was unnecessary and caused redirect loop on Multisite

### 1.0.0
- adds new tab under Add Plugins page for "Public Repos" (default tab)
- displays a few hand-picked plugins from GitHub
- hardcoded list of plugins using local `plugin-repos.json`
- public repo suggestions are more than welcome!
- supports PHP 7.0 to PHP 8.3
- supports Git Updater
- supports Multisite
