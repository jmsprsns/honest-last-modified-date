# Honest Last Modified Date

Only changes a post's "Last modified" date when enough of it has really changed: a minimum number of words, a percentage, or both, set separately for posts and pages.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

WordPress sets `post_modified` to "now" on every update, whatever changed. This plugin puts the old date back before the row is written unless the update changed enough visible words, so themes, SEO plugins, sitemaps, feeds and the REST API all show an honest "Last modified" date.

Settings are under Content Powered > Honest Last Modified Date: a rule per content type (words changed, share of the words, whichever comes first, or both; posts and pages start at 300 words or 20%), a "Last modified date" panel in the editor, and an Activity tab.

## How it decides

- **Baseline.** Each post keeps a copy of the visible text its current date stands for (post meta `_hlmd_baseline`: `gmt`, `title`, `text`). Every update is measured against that, not the previous save, so small edits add up. The copy only counts while its `gmt` matches the post's `post_modified_gmt`; otherwise the saved post is the baseline (every earlier save moved the date).
- **Visible words.** Block comments, tags and attributes, shortcode tags, scripts and styles are dropped; words are lowercased without punctuation. Chinese and Japanese characters count one word each.
- **Words changed** = max(words added, words removed) from a word-level Myers diff (a rewritten word counts once). The diff stops as soon as the threshold is reached, and has a step budget (about 0.1 s on PHP 8); past it, it falls back to a proven lower bound, then a word-pair estimate.
- **Scope.** Only updates of a post that was already published and stays published, for switched-on content types. First publishing, drafts, scheduled posts and unpublishing are left to WordPress.
- **Side effects handled.** New revisions of a post whose date was kept get the real time (WordPress copies `post_modified` into them), and autosaves from before the update are deleted, the way core deletes them after a normal update, so the editor never shows a false "more recent autosave" notice.
- **Editor choice.** The block editor sends `hlmd_choice` (`update` or `keep`) with its save request; the classic editor posts it with a per-post nonce. A choice applies to that one save. "Keep" also resets the baseline, so those changes stop counting.

### Filters

```php
// Decide yourself whether a save moves the date.
add_filter( 'honest_last_modified_date_update', function ( $update, $old_post, $result, $choice ) {
	return $update; // $result: changed, exact, needed, percent, met, old_words, new_words...
}, 10, 4 );

// Content types listed on the settings page.
add_filter( 'honest_last_modified_date_post_types', function ( $types ) {
	return $types; // name => WP_Post_Type
} );
```

## Automatic updates

Updates come from https://github.com/jmsprsns/honest-last-modified-date through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `honest-last-modified-date.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `honest-last-modified-date.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'HONEST_LAST_MODIFIED_DATE_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'HONEST_LAST_MODIFIED_DATE_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
honest-last-modified-date.php                         Plugin header, constants, boot
uninstall.php                         Deletes settings, activity and each post's stored baseline
readme.txt                            WordPress readme; feeds the "View details" popup
includes/
  class-honest-last-modified-date-plugin.php          Main singleton, settings and rules
  class-honest-last-modified-date-saves.php           The save guard: keeps or moves the date
  class-honest-last-modified-date-diff.php            Visible text and the word diff
  class-honest-last-modified-date-editor.php          Editor panel, classic box, REST /check route
  class-honest-last-modified-date-log.php             Activity list and totals
  class-honest-last-modified-date-admin.php           Content Powered > Honest Last Modified Date page and Plugins-screen link
  class-honest-last-modified-date-menu.php            Shared top-level "Content Powered" menu
  class-honest-last-modified-date-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-honest-last-modified-date-updater.php         GitHub update checks (PUC wiring)
  class-honest-last-modified-date-icons.php           Inline Lucide icon helper
assets/
  admin.css, admin.js                 Loaded only on the settings page
  editor.js, classic.js, editor.css   Editor panel (block editor) and box (classic editor)
  icon.svg                            Icon on the update screens
  content-powered.svg                 Sumo logo for the Content Powered menu
icons/                                Lucide icon set (plus LICENSE)
plugin-update-checker/                Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-honest-last-modified-date-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-honest-last-modified-date-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icon set in `icons/`. Print one inline with:

```php
echo Honest_Last_Modified_Date_Icons::get( 'package', 20 );           // name, size in px
echo Honest_Last_Modified_Date_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
