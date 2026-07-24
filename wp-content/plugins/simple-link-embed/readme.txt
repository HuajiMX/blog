=== Simple Link Embed ===
Contributors: monokurodesign
Tags: link card, blog card, ogp, embed, block
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Create link cards from URLs or internal posts in the block editor. Fetches page metadata automatically for link previews.

== Description ==

Simple Link Embed lets editors create link cards from external URLs or internal posts in the block editor. Paste a URL or search for a post, and the plugin fetches metadata such as the title, description, image, and site name automatically.

When an editor inserts or refreshes a card, the plugin requests the selected URL from the server side to fetch metadata. Preview images and site favicons are still loaded from the linked site when available.

**Features:**

* **WordPress Block (Gutenberg) Support** - Fully compatible with modern block editor
* **External and Internal Links** - Paste an external URL or search for an internal post from the editor
* **Automatic Metadata Fetching** - Retrieves OGP and fallback metadata from the selected page
* **Card Display Controls** - Control image position, description, site name, and link target behavior
* **Site Favicon with Fallback** - Uses the linked site's favicon when available and falls back to a bundled icon
* **YouTube and X Support** - Includes service-specific metadata handling and fallbacks
* **Optional GA4 Click Tracking** - Sends click events only when explicitly enabled

== External Services ==

This plugin uses the following external services:

1. **Linked page metadata fetch**
   * Service URL: The exact URL entered by the editor
   * Purpose: Retrieve page metadata such as title, description, image, canonical URL, site name, and favicon to build the link card.
   * Data sent: The selected URL is requested from the site server when an editor inserts or refreshes a card. The remote server may receive the site server IP address and user agent.
   * Terms of Service / Privacy Policy: Depend on the linked destination website selected by the editor.

2. **Linked page preview image and favicon**
   * Service URL: The `og:image` URL and favicon URL provided by the linked destination website
   * Purpose: Display the linked page's preview image and site icon in the card.
   * Data sent: Site visitors' browsers request these image URLs when viewing a page that contains the card.
   * Terms of Service / Privacy Policy: Depend on the linked destination website selected by the editor.

3. **YouTube oEmbed API**
   * Service URL: `https://www.youtube.com/oembed`
   * Purpose: Retrieve metadata for YouTube URLs (title, author, thumbnail).
   * Data sent: The selected YouTube URL.
   * Terms of Service: https://www.youtube.com/t/terms
   * Privacy Policy: https://policies.google.com/privacy

4. **YouTube thumbnail image service**
   * Service URL: `https://i.ytimg.com/vi/{video_id}/hqdefault.jpg`
   * Purpose: Fallback thumbnail retrieval for YouTube video cards.
   * Data sent: Video ID extracted from the selected YouTube URL.
   * Terms of Service: https://www.youtube.com/t/terms
   * Privacy Policy: https://policies.google.com/privacy

5. **Google Analytics 4 (optional)**
   * Service URL: `https://www.google-analytics.com/`
   * Purpose: Send link card click events when tracking is enabled in plugin settings.
   * Data sent: `link_url`, `card_title`, `link_domain`, `page_title`, `page_url`, and the configured GA4 measurement ID.
   * When sent: Only when the site owner enables tracking in this plugin and a GA4 `gtag.js` setup already exists on the site.
   * Terms of Service: https://marketingplatform.google.com/about/analytics/terms/
   * Privacy Policy: https://policies.google.com/privacy

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/simple-link-embed`, or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Add the 'Simple Link Embed' block in the editor
4. Enter a URL

== Frequently Asked Questions ==

= What are the requirements? =

WordPress 5.8+ and PHP 7.4+ are required.

= Does this plugin contact external websites? =

Yes.

* When an editor inserts or refreshes a card, the plugin requests the selected URL to read metadata and cache it.
* When a card is displayed, the linked site's preview image and favicon may be loaded from that linked site.
* YouTube URLs may also use the documented YouTube services listed above.
* Google Analytics 4 is used only if the site owner explicitly enables tracking in the plugin settings and already uses `gtag.js`.

= Which OGP tags are supported? =

The following OGP tags are supported:
* og:title
* og:description
* og:image
* og:url
* og:site_name

= Can I customize the styling? =

Yes, you can customize using CSS variables:
* --slemb-card-bg
* --slemb-card-border
* --slemb-card-shadow
* --slemb-title-color
* --slemb-desc-color
* --slemb-border-radius

== Screenshots ==

1. Block editor interface showing the Simple Link Embed block with URL input and settings panel
2. Frontend display example of a link card with image, title, description, and site information
3. Block settings sidebar with image position, display options, and link behavior controls

== Changelog ==

= 1.0.4 =
* Compatibility: Confirmed compatibility with WordPress 7.0.

= 1.0.3 =
* Changed: Switched plugin source strings to English so WordPress.org translations can be managed normally.

= 1.0.2 =
* Added: Prefer the linked site's favicon and fall back to the bundled icon when unavailable.

= 1.0.1 =
* Added: Show a shared site icon next to the site name in link cards.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.4 =
Confirms compatibility with WordPress 7.0.

= 1.0.3 =
Improves WordPress.org translation support by moving source strings to English.

= 1.0.2 =
Adds site favicon support with fallback to the bundled icon.

= 1.0.1 =
Adds a shared site icon next to the site name in link cards.

= 1.0.0 =
Initial release. No upgrade required.
