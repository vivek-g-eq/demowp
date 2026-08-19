=== AI Blog Writer ===
Contributors: yourname
Tags: ai, blog, content generation, openai, writer
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate SEO-friendly blog posts using AI directly from your WordPress dashboard.

== Description ==

AI Blog Writer connects to an OpenAI-compatible Chat Completions API to generate blog
post titles and content based on a topic, focus keywords, and tone you specify.
Generated posts are saved as WordPress drafts (or Pending / Published, per your
choice) so you can review and edit before they go live.

Features:

* Simple "Generate Post" screen: topic, keywords, tone, length, and save status.
* Settings screen to store your API key and preferred model.
* Secure by default: nonces on every request, capability checks, and full output
  escaping.
* Clean OOP structure, one class per responsibility.

== Installation ==

1. Upload the `ai-blog-writer` folder to `/wp-content/plugins/`, or install the
   zip file through the WordPress admin Plugins > Add New > Upload Plugin screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **AI Blog Writer > Settings** and enter your API key and model.
4. Go to **AI Blog Writer > Generate Post** to create your first AI-generated draft.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Any provider exposing an OpenAI-compatible `/v1/chat/completions` endpoint.

= Where is my API key stored? =

In a single serialized option (`aibw_settings`) in the WordPress options table.
It is never displayed in plain text after saving.

== Changelog ==

= 1.0.0 =
* Initial release.
