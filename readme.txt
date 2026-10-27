=== Honest Last Modified Date ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: last modified, modified date, updated date, seo, freshness
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Only changes a post's "Last modified" date when enough of it has really changed: a minimum number of words, a percentage, or both, set separately for posts and pages.

== Description ==

WordPress moves a post's "Last modified" date to today on every update, even when nothing a reader can see has changed: a new category, a fixed link, a tweaked block setting, a bulk edit. Themes show that date as "Updated on", SEO plugins put it in schema and sitemaps, and search engines read it as fresh content that isn't there.

Honest Last Modified Date keeps the date where it is until enough has changed. You choose what "enough" means:

* **Words changed**: for example 300 words added, removed or rewritten.
* **Share of the words**: for example 20% of the post. Scales with short and long content.
* **Whichever comes first**, or **both**.

Set a different rule for posts and for pages, or switch either off. Other public content types (products, case studies...) can be switched on too.

= Small edits add up =

Every update is measured against the version from the date readers see, not just the last save. Ten small edits count the same as one big one, so a post that's slowly rewritten still gets its new date.

= Only visible words count =

Words are compared without formatting, HTML, block settings, link addresses, capital letters or punctuation. Categories, tags, custom fields, SEO fields and featured images never move the date. Headings, lists, tables, captions, shortcode text and numbers (prices, years) do count.

= In the editor =

A "Last modified date" panel in the block editor (and a box in the classic editor) shows the date readers see, how many words have changed so far against the rule, and whether updating now would move the date. It updates as you type.

For a single update, editors can also choose:

* **Set today's date**: a short but important fix, like a new price or a corrected fact.
* **Keep this date**: reformatting or reorganising. Those changes then stop counting.

= Everything reads the same date =

The date is kept in WordPress itself, so your theme's "Updated on", Yoast, Rank Math and All in One SEO schema, sitemaps, feeds and the REST API all see the honest date. Nothing to configure in other plugins.

= Activity =

The Activity tab lists recent updates to published content, how many words changed against the rule, and whether the date moved, with running totals.

= Details =

* Works with the block editor, the classic editor, Quick Edit, bulk edit, the REST API, WP-CLI and plugins that update posts.
* First publishing, drafts and scheduled posts work as usual. Unpublishing and republishing sets the date as WordPress normally does.
* New revisions keep their real time, and autosaves from before an update are cleared as WordPress does after a normal update, so you never see a false "more recent autosave" notice.
* Developers can override the decision with the `honest_last_modified_date_update` filter.

Find it under Content Powered > Honest Last Modified Date in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `honest-last-modified-date` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Honest Last Modified Date to adjust the rules. Posts and pages start at "300 words or 20%, whichever comes first".

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it change dates that are already set? =

No. It only decides what happens on updates from now on. Existing dates stay as they are.

= What counts as a changed word? =

A word added, removed or rewritten in the title or content. Rewriting a 50-word paragraph counts as 50, not 100. Moving a paragraph elsewhere counts as changed text.

= What if I fix a typo that matters, like a price? =

Choose "Set today's date" in the editor's "Last modified date" panel before updating. That choice applies to that one update.

= Does it work with my SEO plugin? =

Yes. The date is kept in WordPress itself, so Yoast, Rank Math, All in One SEO, sitemaps and your theme all read the same honest date.

= What happens if I deactivate it? =

Updates set today's date again, as WordPress normally does. Turn it back on and it carries on from the dates as they are then.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/honest-last-modified-date/releases for the full release history.
