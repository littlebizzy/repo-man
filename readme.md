# Repo Man

Install public repos to WordPress

## Changelog

### 1.1.1
- added Disable Feeds plugin
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
