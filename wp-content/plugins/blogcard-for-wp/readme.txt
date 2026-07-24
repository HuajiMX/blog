=== SU Blocks Blogcard ===
Contributors: ejointjp
Tags: link, blog, blogcard, card, url
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that makes it easy to create blog cards. Simply enter a URL and automatically fetch metadata to display beautiful cards.

== Description ==

WordPress's standard URL embedding feature only works with WordPress sites.

This plugin was created to display any link destination as a stylish card with thumbnails.

Just paste the URL into the block editor. Blog cards are displayed in an instant.

New features from version 2.0.0 include the ability to create blog cards from site search.
This allows you to search for articles within your own site and convert them into blog cards.

> **Important Notice:**
> Starting from version 2.1.0, the block is rendered dynamically on the server side to improve stability.
> **Note:** Manual edits made via "Edit as HTML" will be reset to the standard design.
> If you see "Attempt Block Recovery" after updating, please click the button to recover the block.

== Key Features ==

* **Easy Operation**: Simply enter a URL and press Enter to instantly generate a blog card
* **Universal Compatibility**: Works with non-WordPress sites (note, Zenn, Qiita, GitHub, etc.)
* **Automatic Metadata Fetching**: Automatically retrieves title, description, and thumbnail images
* **Customizable**: Retrieved information can be edited later
* **SEO Friendly**: Set rel attributes like nofollow, nofollow, sponsored, ugc
* **Responsive Design**: Beautiful display on mobile devices
* **Caching**: Caches retrieved metadata for fast display
* **Site Search**: Search and convert internal posts into blog cards
* **Dark Mode Support**: Supports dark mode with .dark class
* **Advanced Management**: Configure default link targets, cache duration, and manual favicon support

== Installation ==

1. Upload plugin files to `/wp-content/plugins/blogcard-for-wp/` directory
2. Activate the plugin from the 'Plugins' menu in WordPress admin
3. Add the 'Blog Card' block in post/page editor
4. Enter a URL and press Enter to automatically generate a blog card

== Usage ==

=== Basic Usage ===

1. Click the '+' button in the post/page editor
2. Search for and select 'Blog Card'
3. Enter the destination URL in the URL input field
4. Press Enter to automatically fetch metadata and generate a blog card

=== Advanced Settings ===

When the block is selected, the following settings appear in the right sidebar:

* **URL**: Destination URL
* **TARGET Attribute**: How to open the link (_blank, _self, etc.)
* **Rel Attributes**: noopener, nofollow, noreferrer, sponsored, ugc
* **Thumbnail**: Show/hide thumbnail image
* **Title**: Manual title editing
* **Description**: Manual description editing
* **Thumbnail Image**: Manual thumbnail image setting

== Frequently Asked Questions ==

= Can any site URL be converted to a blog card? =

Yes, basically any site URL can be converted to a blog card. However, some sites may not allow metadata retrieval.

= Can retrieved metadata be edited? =

Yes, title, description, and thumbnail images can be edited later. Link attributes (target, rel, etc.) can also be changed freely.

= How is caching managed? =

Retrieved metadata is automatically cached and displayed quickly for the same URL. Cache can be manually cleared from the admin screen.

= Does it display correctly on mobile? =

Yes, it supports responsive design and displays beautifully on mobile devices.

= Does it affect SEO? =

By setting appropriate rel attributes (nofollow, etc.), you can create SEO-friendly links.

== Screenshots ==

1. Instantly create beautiful blog cards.

== Changelog ==

= 2.3.7 =
* Simplified the block's dark mode color handling to work reliably regardless of the active theme.

= 2.3.6 =
* Fixed the plugin icon to display correctly in the plugin directory.
* Added a banner image for the plugin directory.

= 2.3.5 =
* Updated the plugin icon.
* Updated "Tested up to" to WordPress 7.0.

= 2.3.4 =
* Improved: Added `aria-label` to the blogcard link for better screen reader accessibility.
* Improved: Refactored CSS to align with design token conventions.

= 2.3.3 =
* Fixed: Resolved an issue where titles and descriptions containing HTML entities (like `&` or `<`) were double-escaped and displayed incorrectly (e.g., `&amp;amp;`).

= 2.3.2 =
* CSS Fixed.

= 2.3.1 =
* Improved: Added spinner and loading message during site search.
* Fixed: Favicon not showing correctly during site search.
* Fixed: Error occurring when entering the site's Home URL.
* Improvement: Enhanced visibility of the loading indicator in the editor.

= 2.2.1 =
* Bug fixes and minor adjustments.

= 2.2.0 =
* New Feature: Added settings page for default link target attributes and cache configuration.
* New Feature: Added ability to manually set Favicon URL.
* Improvement: Updated block editor UI layout.
* Improvement: Added option to delete plugin data on uninstallation.
* Update: Refactored internal code structure.

= 2.1.2 =
* Update CSS.

= 2.1.1 =
* Bug fixes.
* Update CSS.

= 2.1.0 =
* Major Update: Switched to Server Side Rendering (Dynamic Block) to improve stability and prevent block validation errors.
* Performance: Improved rendering performance by utilizing server-side processing.
* Note: Manual HTML edits via "Edit as HTML" will be overridden by the dynamic renderer.

= 2.0.8 =
* Update version.

= 2.0.7 =
* Update CSS variables.

= 2.0.4 =
* Update CSS.

= 2.0.0 =
* Significantly improved block editor UI
* Added site search functionality
* Enhanced error handling
* Optimized performance
* Improved responsive design
* Enhanced accessibility

= 1.0.7 =
* Bug fixes
* Improved metadata retrieval stability

= 1.0.0 =
* Initial release
* Basic blog card functionality
* Automatic metadata fetching
* Customization features

== Upgrade Notice ==

= 2.1.0 =
Major Update: Switched to Server Side Rendering for better stability. Please check your block appearance.

= 2.0.0 =
Major feature improvements and UI refresh have been implemented. Existing blog cards will continue to work, but we recommend upgrading to take advantage of new features.

= 1.0.7 =
This is a bug fix version. We recommend upgrading.

== Support ==

For questions about the plugin or bug reports, please use the following methods:

* GitHub Issues: [Plugin repository URL]
* Email: [Support email address]

== Developer Information ==

This plugin is developed using the following technologies:

* WordPress Block Editor (Gutenberg)
* React
* WordPress REST API
* PHP 7.4 or higher

If you would like to customize or add features, please send a pull request to the GitHub repository.

== License ==

This plugin is released under the GPLv2 or later license.

== Acknowledgments ==

In developing this plugin, we referenced the following open source projects:

* WordPress
* React
* Other open source libraries

== Development ==

This plugin is actively maintained and developed. Contributions are welcome!

For development setup and contribution guidelines, please refer to the GitHub repository.