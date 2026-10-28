# v2.0.0
## 10/28/2026

1. [](#new)
    * **First public release**, on GitHub: [norsia-fr/grav-plugin-galinf](https://github.com/norsia-fr/grav-plugin-galinf). Install it from the admin's Direct Install with the address of a release's zip (see the README).
    * **Comments per day** (Comments tab): the whole site accepts 20 comments a day by default, or as many as you set (1 to 10000), or without limit with the "Unlimited per day" option. Beyond it, new comments are refused until the next day. It stops a flood of bots coming from many addresses, which the per-address limit does not. Moderators are not counted.
2. [](#improved)
    * `compatibility: grav: ['2.0']` in the blueprint, so that the Grav package manager only offers the plugin to Grav 2 sites; author, homepage, bug tracker and documentation links; new description and keywords.
    * README: screenshots, installation from the GitHub releases (by URL or by file), update instructions.
    * The "Delete the data" action of the Maintenance tab now requires a super administrator when it also deletes the comments (irreversible).
    * The image preview of a comment's edit form follows the comments' own permission (`admin.galinf-comments`, granted per user or group), instead of any API access.
    * With Cloudflare Turnstile or Google reCAPTCHA, each attempt counts against the per-address limit before the check is sent to Cloudflare or Google: a flood of fake answers can no longer keep the server busy with remote checks.
    * The admin's destructive actions are no longer run without confirmation if the admin's dialog boxes are missing.
3. [](#bugfix)
    * **Grav 2 administrators are recognized as moderators again.** Only the permissions of Grav 1.7's admin (`admin.super`, `admin.login`) were checked, which Grav 2 accounts do not carry: with "Who can comment: Administrators", nobody could comment, and with pre-moderation, administrators' own comments waited for approval. Grav 2 administrators (`api.super`) and accounts allowed to edit gallery comments are now moderators. As before, this applies to users logged in on the site: being logged in to the admin does not count, Grav keeps the two apart (the option now says "Administrators (logged in on the site)").

# v1.19.1
## 10/28/2026

1. [](#improved)
    * PhotoSwipe's MIT license text now ships with the plugin (`assets/photoswipe/LICENSE`), as its license requires for redistributed copies; README credits and License section point to the license files.

# v1.19.0
## 10/27/2026

1. [](#new)
    * **"None" anti-bot check** (Comments tab, "Anti-bot check"): no question or widget at all, only the honeypot and the per-IP rate limit remain. Bots do not check whether their message shows up: with pre-moderation they fill the moderation list, without it they reach the site. The choice is left to the admin.
2. [](#improved)
    * Comments tab reordered: Comments, Pre-moderation, Who can comment, Nickname required, Maximum comment length, Counter always visible, Anti-bot check (same order in `galinf.yaml` and the README).
    * "Who can comment": the "Everyone" choice no longer mentions an anti-bot check, which now depends on the "Anti-bot check" option.

# v1.18.1
## 10/27/2026

1. [](#improved)
    * **Comment length is now an option** (Comments tab, "Maximum comment length"): 300 characters by default, from 50 to 1000. It used to be fixed at 2000, which is a lot for a comment on an image. The admin editor still accepts up to 2000 characters, so existing comments stay editable.
    * **Discreet counter**: "characters left" only appears once less than 20 % of the limit is left. The new "Counter always visible" option shows it as soon as the form opens.

# v1.18.0
## 10/27/2026

1. [](#new)
    * **Choice of the comments' anti-bot check** (Comments tab, "Anti-bot check"): the Galinf sum question (default, unchanged), **Cap** (proof of work computed by the browser in the background, no click, self-hosted), **Cloudflare Turnstile** or **Google reCAPTCHA** (v2 checkbox, v2 invisible or v3, as set in the Form plugin). Cap, Turnstile and reCAPTCHA come from the Form plugin and use its settings (keys and reCAPTCHA version are entered there); the Form plugin is only needed when one of them is chosen. Their scripts load only when a visitor opens the comment form. The honeypot and the per-IP rate limit still apply whatever the check.
    * If the chosen check cannot work (Form plugin missing or disabled, Turnstile or reCAPTCHA keys not set), visitors see "Comments are temporarily unavailable" instead of the form, and the Maintenance tab shows a notice saying why.
2. [](#improved)
    * README (Comments): the four checks, and Turnstile and reCAPTCHA sending visitor data to Cloudflare or Google (privacy policy, consent).

# v1.17.1
## 10/27/2026

1. [](#improved)
    * **Security hardening of the indexed scope.** Only pages stored under `user/pages/` are indexed, compared on real paths: a page registered by another plugin from its own folder, or reached through a symbolic link pointing outside `user/pages/`, never is. Pages with `routable: false` (e.g. modular sub-pages) are no longer indexed either: they cannot be reached by their address and have no place in a public gallery.
    * README ("Restricted-access pages"): Grav serves the media of a restricted page to anyone who knows their address unless the login plugin's `protect_protected_page_media` option is enabled.

# v1.17.0
## 10/27/2026

1. [](#improved)
    * **No page is indexed by default.** A fresh install no longer indexes every page using the `item` template: nothing ends up in a gallery until you choose the pages to use (General tab, "Indexed templates" and/or "Source pages/folders"). With both lists empty, nothing is indexed; `/` in "Source pages/folders" takes the whole site. A site that relied on the old `item` default without ever saving its options has nothing selected after updating: choose the pages in the General tab.
    * The Maintenance tab shows a notice as long as no page is selected.
    * README: new "After installing: three steps" section (choose the pages, build the index, display a gallery); help texts of the two options updated.

# v1.16.0
## 10/27/2026

1. [](#improved)
    * **Shorter names.** The shortcode is now `[galinf]` (was `[galinf-gallery]`), the Twig function `galinf()` (was `galinf_gallery()`), and the page parameter `src` (was `source`): `[galinf src="/blog/my-page"]`, `{{ galinf({src: '/blog/my-page'}) }}`. `[galinf-article]` and `galinf_article()` do not change. The old names are no longer recognized.
    * README: first sentence and the "Displaying a gallery" introduction reworded.

# v1.15.3
## 10/24/2026

1. [](#bugfix)
    * **"Purge orphan thumbnails" now runs in batches, with progress and a Stop button.** It used to be a single request: with tens of thousands of orphans (e.g. after a thumbnail width change), it lasted minutes with no feedback, and the site could stop responding until the end. It now moves forward one thumbnail folder after another, in ~2-second requests, showing "Folder 120 of 256", the thumbnails deleted, the space freed and the remaining time; what has been deleted stays deleted if it is stopped. The purge phase of "Run all" benefits too.
    * **The purge no longer deletes `galinf-thumbs/.htaccess`** (long browser cache for thumbnails), and puts it back if it is missing. It used to delete it, and since "Run all" purges last, thumbnails lost their browser cache until the next article save. The purge now only ever deletes files named like thumbnails.
2. [](#improved)
    * `bin/plugin galinf purge-thumbs` shows a progress bar per folder.

# v1.15.2
## 10/21/2026

1. [](#improved)
    * The plugin now ships its `LICENSE` file (MIT, as declared in the blueprint and the README).

# v1.15.1
## 10/21/2026

1. [](#improved)
    * **Everything in English**: README, CHANGELOG (all past entries translated), CLI messages and options of the `bin/plugin galinf` commands, server log messages, and code comments. No change in behavior.
    * README: new "Languages" section (admin and visitor language, changing a text or adding a language from the translation editor).

# v1.15.0
## 10/15/2026

1. [](#new)
    * **Plugin translated: English (default language) and French.** Every displayed text goes through `PLUGIN_GALINF.*` translation keys (`languages/en.yaml`, `languages/fr.yaml`): admin options, Maintenance tab, "Gallery comments" directory, gallery, lightbox and visitor comments. Any text can be changed, or translated into another language, from Admin Next's translation editor, without touching the plugin.
    * **Visitor language**: the site's language if Grav declares one; otherwise the browser's (French or English, English by default). The choice is made in the browser, so the gallery stays compatible with the page cache. A language added by the site in the translation editor is offered automatically.
    * The admin follows the language chosen in the user's preferences: numbers, sizes, durations and dates are formatted for that language.
2. [](#improved)
    * Lightbox: PhotoSwipe's own buttons (close, previous / next image, image not found) are translated; they stayed in English.
    * Comment dates in the visitor's language format ("11 juillet 2026 à 17:12", "July 11, 2026 at 5:12 PM").
    * Default nickname of a comment left without a name: "Anon" (instead of "Anonyme"), the same in every language.
    * Plugin name: "Galinf — Infinite gallery"; description in English.
    * Refusals when posting a comment or shrinking an image carry a code, translated by the interface; the server's sentences and those of the `shrink-oversized` command are in English.
    * Help texts fixed: the admin entry is called "Gallery comments", and Maintenance is a tab ("at the bottom of this page" was outdated).

# v1.14.9
## 10/15/2026

1. [](#improved)
    * "Rebuild from scratch" and `bin/plugin galinf reindex --clear` keep the "Last run" history of the Maintenance tab (the command line erased it).
    * Code cleanup, with no visible change: removal of the `POST /galinf/reindex` admin endpoint, unused since v1.12.0 (the Maintenance tab goes through batched reindexing), of a few dead lines and of outdated comments; the progress of a finished action is no longer kept in the database.

# v1.14.8
## 10/15/2026

1. [](#bugfix)
    * **Shortcode `[galinf-gallery source="article"]` / `[galinf-article]`: the gallery always shows its own article's images.** When the article was first displayed in a list (blog summaries, home page), the gallery targeted the list page, and the mistake stayed in cache: the article opened next showed an empty gallery. The Twig functions were not affected.
    * Changing the gallery order during a load could show the first batch of images twice (or mix two draws in random order).
    * Info panel: if the server briefly refused to answer (overload, anti-DoS), the panel stayed empty for every image of the article until the page was reloaded. The request is now retried on the next image.
    * Lightbox: in a browser that blocks local storage (some private modes, Safari with cookies blocked), the whole added interface (buttons, panel, thumbnails) could fail to appear.
2. [](#improved)
    * Comments: an anti-bot question left on screen for more than 15 minutes is reported as expired ("answer the new one") instead of "wrong".

# v1.14.7
## 10/15/2026

1. [](#bugfix)
    * **Articles now follow their parent page.** Deleting, moving or renaming a page that contains articles (for instance the blog page) only updated that page: its articles stayed in the index under their old address, hence broken images in the gallery until the next reindex, and articles missing from their new address. They are now removed, or reindexed at their new place, along with the parent page; their old thumbnails are deleted. Comments already followed.
    * **Access rule set on a parent page**: its articles leave the gallery as soon as it is saved, thumbnails included, instead of staying there until the next reindex.
    * Moving a page without changing its address (a simple change of position) no longer deletes and recreates its thumbnails.
2. [](#improved)
    * Removing a group of pages from the index: exact address comparison, case included (removing `/Blog` no longer takes the articles of `/blog` with it), and thumbnails deleted in every case, drag-and-drop reordering included.

# v1.14.6
## 10/15/2026

1. [](#improved)
    * **Two distinct signals in the Maintenance tab.** Red was reserved for actions that delete something, so "Purge orphan thumbnails", a quick operation, looked as alarming as "Regenerate all thumbnails". Now red and the "Heavy operation" badge flag what keeps the server busy for a long time (Regenerate all thumbnails), orange and the "Permanent deletion" badge what erases (Purge orphan thumbnails, Rebuild from scratch). Deletions still ask for confirmation.
    * The Uninstall block states explicitly that visitor comments are deleted permanently if the matching box is ticked.
    * README: explains that settings survive uninstalling, as with any Grav plugin, and how to erase them.
    * Update instructions of v1.14.3 and v1.14.5 fixed: "Update the index" is enough, "Run all" is not needed.

# v1.14.5
## 10/15/2026

1. [](#bugfix)
    * **Scheduled publishing honored to the second.** An article whose unpublish date arrived stayed in the gallery until the next reindex; an article scheduled for later did not appear on the day. Start and end dates are now stored in the index, and the gallery only serves articles published at the moment of the request, with no reindex or scheduled task. As in Grav, an explicit `published:` in the header takes precedence over the dates. **After updating, run "Update the index" once** (Maintenance tab) to store the dates of existing articles: no thumbnail needs recreating.

# v1.14.4
## 10/15/2026

1. [](#bugfix)
    * **[security] The comment captcha could be bypassed.** Its signing key was read from `security.salt`, a setting Grav 2 no longer provides (its secret now lives in `user/config/security-private.php`). The plugin then fell back on a key derived from the site's installation path, hence guessable: a valid token could be forged without solving the question. The key is now derived from Grav's secret. Questions displayed at the time of the update must be asked again (reloading the page is enough).
2. [](#improved)
    * **Per-IP comment limit: works behind a proxy or a CDN.** The plugin read the address seen by the server itself, which is the intermediary's when the site is behind Cloudflare or a reverse proxy: every visitor then shared the same limit of 5 comments per 10 minutes. It now uses the address provided by Grav, which follows the site's `system.http_x_forwarded` setting. Without a proxy, nothing changes; behind a proxy, enable in that setting the header your intermediary sets (see the README).

# v1.14.3
## 10/13/2026

Release resulting from a security review of the plugin before publication.

1. [](#new)
    * **Restricted-access pages are no longer indexed.** Since the gallery is public, a page under an access rule (`access:` in its header or in a parent page's header) is now excluded from the index; if an indexed page becomes restricted, its thumbnails are deleted. There is deliberately no option to lift this exclusion. **After updating, run "Update the index" once** (Maintenance tab) to remove restricted pages already indexed, thumbnails included.
2. [](#improved)
    * **Comment captcha: a token can only be used once.** A solved captcha stayed reusable for 15 minutes, and a wrong answer cost nothing: a script could try every possible sum with the same token. The token is now consumed as soon as it is presented, right or wrong answer; after a refusal, the form shows a new question.
    * **Posting a comment: cheap checks come first.** Looking up the page and reading its folder only happen once the captcha is validated: a burst of invalid requests no longer loads the server.
    * **Posting a comment: the request origin is checked**, on top of the header already required. A submission declared as coming from another site is refused.
    * **The info panel only returns the metadata of the gallery's images**: the other files in the article folder (documents, videos) and images hidden by tag in both galleries are excluded.
    * An article removed from the index (unpublished, now restricted, out of scope) has its thumbnails deleted at the same time.

# v1.14.2
## 10/13/2026

1. [](#improved)
    * **Bottom-of-gallery indicator redesigned.** Under the main gallery, the "End of the gallery" text is replaced by a counter and a thin progress bar: "12,480 / 25,000 images". The first number is the visitor's real position (the last image shown on screen), no longer the number of preloaded images. While waiting for a batch, the line adds "loading…"; at the end, the counter is complete and the bar full.
    * **Nothing is shown under article galleries any more**, neither while loading nor at the end.

# v1.14.1
## 10/13/2026

1. [](#improved)
    * **README rewritten for newcomers to the plugin.** The first part follows the usual Grav plugin outline: presentation, what the plugin does, requirements, installation (through the admin's Direct Install tab or by hand), displaying a gallery with a shortcode or a Twig function, configuration by tab, commands, uninstalling. The details of how it works are grouped at the end, under "Going further".
    * README fixes: references to the current tabs, name of the "Gallery comments" entry, `commented` sort documented, fallback to the original when a thumbnail is missing, api, admin2 and flex-objects requirements spelled out.

# v1.14.0
## 10/13/2026

1. [](#bugfix)
    * **A thumbnail gone from disk no longer shows a broken image.** If a thumbnail file is missing while the index still references it (`galinf-thumbs/` folder emptied by hand, partial restore), the gallery and the lightbox strip show the original instead. "Create missing thumbnails" then recreates the files.

# v1.13.0
## 10/13/2026

1. [](#new)
    * **Clean uninstall.** The Maintenance block gains a "Before uninstalling the plugin" section: a button deletes the index and all thumbnails, which uninstalling leaves on the server. A checkbox also deletes the visitor comments; without it, they are kept. Same from the command line: `bin/plugin galinf uninstall-data [--comments] [--yes]`.
    * Nothing is deleted automatically when the plugin is removed, so that a reinstall loses nothing. The README describes the steps.
2. [](#improved)
    * **Options page reorganized into tabs**: General, Gallery, Lightbox, Comments, Maintenance. No setting changes name or value.
    * **Maintenance blocks restyled**: they follow the admin theme (light or dark, accent color), with a figures header (images indexed, last indexing, images without thumbnail), actions grouped into "Routine upkeep" and "Rebuild", and an icon per action.
    * Durations estimated on a Raspberry Pi 5 are given per 1,000 images, so they are easy to scale.

# v1.12.1
## 10/12/2026

1. [](#bugfix)
    * **The image preview of a comment, in the admin, no longer shows an outdated thumbnail.** It computed the thumbnail address on its own, without the version token introduced in v1.9.6: after an image was replaced under the same name, the browser could keep the old one for up to a year. The preview now reads the address from the index, like the gallery.
2. [](#improved)
    * Moving a page by reorganizing in the admin no longer reindexes it twice (the api plugin now emits two events for this operation).
    * Code: the index database and the indexer are each built in a single place (`IndexDatabase::fromConfig()`, `Indexer::fromConfig()`), instead of four and two. No change in behavior.

# v1.12.0
## 10/12/2026

1. [](#new)
    * **The Maintenance block offers six actions instead of a single button**, each with its explanation: "Run all" (index, missing thumbnails, purge of orphans), "Update the index" (fast, no thumbnail created), "Create missing thumbnails", "Regenerate all thumbnails", "Rebuild from scratch" and "Purge orphan thumbnails". The last three ask for confirmation.
    * **Progress and stop.** Reindexes started from the admin move forward in short batches, with an "article 42 of 180" banner, a progress bar and a "Stop" button that cleanly interrupts after the current article. No more single request lasting tens of minutes with no feedback.
    * **Lasting report.** At the end of an operation (reindex, purge, image shrinking), a green, orange or red banner gives the details and the duration; it stays displayed until "Close". During the operation, the remaining time is estimated.
    * **Last run of each action** shown under its button: date, duration and volumes processed. As long as an action has never run to completion, an estimate on a Raspberry Pi 5 is shown, announced as such.
    * Purging orphan thumbnails is available without the command line.
    * `bin/plugin galinf reindex` accepts `--no-thumbs` (index only) and `--force-thumbs` (recreates every thumbnail), and reports the number of thumbnails created.
2. [](#improved)
    * **A reindex that runs to completion removes articles that no longer exist from the index** (deleted outside the admin, unpublished, moved out of the indexed folders). Previously only a rebuild from scratch did. As a safety measure, nothing is removed if no indexable article is found.
    * The announced durations distinguish updating the index from creating thumbnails, instead of a single "tens of minutes".

# v1.11.0
## 10/12/2026

1. [](#new)
    * **Reindexing reports images left without a thumbnail.** Since Grav 2.1.7, the core refuses to resize an image of more than 25 megapixels (`system.images.max_pixels`): no thumbnail was created and the gallery served the original instead, silently. The summary of `bin/plugin galinf reindex` and the Maintenance block of the options now show the number of images concerned, their list and their dimensions.
    * **In-place shrinking of oversized images.** In the box of images without a thumbnail, a "Shrink" button per image and a multiple selection ("Select all", "Shrink selection") bring the image just under the system limit, then create its thumbnail. After explicit confirmation: the original is replaced permanently and its EXIF data is lost (the orientation is applied first); the file name, the `.meta.yaml` and the comments are kept. JPEG, PNG and WebP. On failure the original stays intact and the reason is shown on its line.
    * New `bin/plugin galinf shrink-oversized [--yes]` command, which does the same in bulk.
2. [](#improved)
    * The README explains how to shrink images in the browser at upload time (Admin Next's `pagemedia` setting), to stay under the limit.

# v1.10.1
## 08/17/2026

1. [](#bugfix)
    * **Hiding tags no longer appear in the lightbox info panel.** They are internal markers (they drive the gallery): they are now stripped from the `tags` key before being sent to the visitor, in list form as well as in an "a, b" form typed by hand. An image whose `tags` only contained markers no longer shows that line at all.
2. [](#improved)
    * **Warning in the options**: changing a hiding keyword does not apply to images already indexed (hiding is computed at indexing time). The box says a reindex must be run, and that clearing the cache is useless — the new value is read immediately. The opposite case (adding/removing a tag on an image) still takes effect right away.

# v1.10.0
## 08/17/2026

1. [](#new)
    * **Hide an image from a gallery with a tag in its metadata.** The `nogal` tag removes the image from **its article's** gallery (it stays in the infinite gallery); `nogalinf` removes it from the **infinite gallery**, in every sort order including "Commented" (it stays in its article). Both together hide it everywhere. The image stays indexed: removing the tag makes it reappear. Tags are added in the **Tags** field of the admin's metadata editor (`tags` key of the `.meta.yaml`), and **their names are configurable** in the plugin options — an empty field disables that tag. Case- and space-insensitive matching.
    * **Automatic reindex when metadata is saved** (`onApiMediaMetadataUpdated` / `onApiMediaMetadataDeleted`): since hiding is resolved at indexing time, a tag added or removed from the admin takes effect immediately, with no manual reindex.
2. [](#improved)
    * The feed counters (progress bar, article total, "Commented" sort total) exclude hidden images, while the indexing statistics (CLI, admin button) still report the real number of rows in the index.

# v1.9.6
## 08/17/2026

1. [](#bugfix)
    * **An image replaced under the same name kept its old thumbnail.** The persistent thumbnail's name only depends on the path and the width: after a replacement, the thumbnail already existed and was reused as is, forever. The gallery therefore showed the old image, in a tile computed with the NEW dimensions (wrong and distorted image). The thumbnail is now regenerated as soon as the source image is newer than it — up-to-date thumbnails are still reused, no mass regeneration. Already outdated thumbnails repair themselves on the next reindex. Same family of bug as the one fixed in Grav core 2.0.8 for its own derivative cache.
    * **Version token in thumbnail URLs** (`?v=<mtime>`): `galinf-thumbs/` is served with an "immutable" one-year browser cache, so a visitor who had already seen the old thumbnail would have kept it for up to a year after the replacement, without revalidating. `purge-thumbs` strips this token before comparing with the disk (otherwise it would have taken every thumbnail for an orphan).

# v1.9.5
## 08/14/2026

1. [](#improved)
    * **.meta.yaml lists prefixed with a dash** in the sidebar ("- blue cat"), instead of a bare line. Pure CSS, no structural change.

# v1.9.4
## 08/14/2026

1. [](#new)
    * **Lists in .meta.yaml files shown in the sidebar** (e.g. `prompts: ['blue cat', 'light red sweater', …]`): until now silently filtered out (only scalar values were kept), they are now rendered one line per item. Nested lists (list of lists/maps) are ignored, not meant for display.

# v1.9.3
## 07/15/2026

1. [](#bugfix)
    * **Sort control readable on a dark theme**: the "Order" label was a hard-coded dark gray (`#444`), unreadable on a dark background. The label and the `<select>` now inherit the theme's text color (contrast guaranteed whatever the background), the select becomes transparent with a neutral border, and a luminance detector (JS, actual background behind the gallery) switches the native dropdown to dark rendering (`color-scheme: dark`) when the page is dark.

# v1.9.2
## 07/15/2026

1. [](#bugfix)
    * **The gallery is immune to the host theme's typography**: in a theme wrapping content in `.prose` (Tailwind Typography), the `img { margin: 2em 0 }` rule shifted images vertically in their tiles. Double protection: `not-prose` class on the gallery container (neutralizes ALL prose rules inside) + explicit `margin: 0` on `.galinf-tile img` (defense even outside Tailwind).

# v1.9.1
## 07/13/2026

1. [](#bugfix)
    * **Shortcode: the `field` parameter is finally passed on** — `[galinf-gallery field="folder"]` was documented in the README but silently ignored (only the Twig function took it into account).
2. [](#improved)
    * **Cleanup following the code audit (docs/AUDIT_1.9.0.md)**: dead code removed — `migrate-comments` command (no site left on schema ≤ 1.8.16), `Indexer::removePage()` method never called, `.galinf-sentinel` CSS rule (leftover from before virtualization), shadowed `MARGIN` variable in the JS. Outdated comments fixed (mentions of "comments stored in the .meta.yaml files" from before the Flex migration, keyboard shortcuts never implemented, the orphan docblock of `buildThumb`). `galinf.yaml` now documents every recent option (sidebar, buttons) with its default; the feed reuses `matchesRoute()`; `strict_types` on the shortcode. No other change in behavior.

# v1.9.0
## 07/13/2026

1. [](#new)
    * **Milestone release — end of the lightbox work (v1.8.1 → v1.8.27).** Summary of the cycle: PhotoSwipe lightbox fully re-tooled (top bar with slideshow at adjustable delay, rotation, download, fullscreen; `.meta.yaml` info sidebar + comments; virtualized thumbnail strip); **comments on Flex Objects** with full moderation in Admin Next ("Gallery comments" list, links to page and image, preview in the edit form, `migrate-comments` migration); fine-grained options (buttons à la carte, enforced or free sidebar/strip states, meta/EXIF filters for the sidebar); compact top bar on mobile (320 px); danger signage in the options. Tested on a real site with 15,000 images.

# v1.8.27
## 07/13/2026

1. [](#improved)
    * **Red "thumbnail width" box redone NATIVELY** (user request: no custom field): core `display` field, HTML content styled inline — Tailwind's red utilities being purged from the admin2 bundle, and HTML rendering of help texts not being guaranteed. The `galinf-warning` custom field introduced in v1.8.26 is removed. The "Force reindex" block (historical custom field, needed for the button and the stats) keeps its danger style.

# v1.8.26
## 07/13/2026

1. [](#improved)
    * **Danger signage in the options**: RED box under "Thumbnail width" (new reusable `galinf-warning` custom field) and "Force reindex" block switched to danger style (red border/button, "tens of minutes for ~25,000 images" warning, stronger confirmation dialog).
    * **Admin entry renamed "Gallery comments"** (Admin Next sidebar) — avoids clashing with other comment plugins.
    * **Plugin info**: author filled in (norsia); homepage/bugs links removed until the public GitHub repository exists.

# v1.8.25
## 07/13/2026

1. [](#bugfix)
    * **Compact top bar on narrow screens**: buttons overlapped at 320 px (7 buttons + counter at standard PhotoSwipe sizes). Progressive compaction in pure CSS: 42 px buttons under 480 px, 36 px under 360 px, counter in a small font without wrapping; last resort under 340 px, the slideshow delay badge disappears (the delay is set on a wider screen).

# v1.8.24
## 07/13/2026

1. [](#improved)
    * **Sidebar/strip semantics clarified (user request)**: "Sidebar shown" and "Strip shown" are STATES chosen by the admin; if the matching visitor button ("i", show/hide the strip) is disabled, the state is **enforced** — the visitor's remembered choice is ignored (and the sidebar's close cross disappears too). Button enabled: the state is only a default, the visitor's choice takes precedence. In v1.8.23 the visitor's localStorage won even without a button.

# v1.8.23
## 07/13/2026

1. [](#new)
    * **Lightbox buttons à la carte**: new "Lightbox buttons" options section — slideshow, rotation, download, fullscreen, strip, info panel, each can be enabled/disabled (all shown by default).
    * **Default states**: "Sidebar open by default" (default: closed) and "Thumbnail strip shown by default" (default: shown) options. The visitor's choice, remembered in their browser, then takes precedence.
2. [](#improved)
    * **Zoom button removed** (clicking the image already zooms) — frees up room in the top bar, especially on mobile.
    * Sidebar: no more "No information" message — the section simply stays empty when there is nothing to show.

# v1.8.22
## 07/12/2026

1. [](#new)
    * **Admin list: clickable "Image" column** linking to the image file (`image_url` virtual property, sorted through the Flex index like "Page").
    * **Image preview in a comment's edit form**: `galinf-comment-preview` custom field (persistent thumbnail if available, otherwise the original; clickable to the original) fed by the new authenticated `GET /galinf/comment-preview/{key}` endpoint.
2. [](#bugfix)
    * **Edit form title**: the template displayed raw Twig (`{{ object.name ?: 'Anonyme' }}`) — Admin Next only interpolates `{{ object.x }}` and `{{ object.x ?? 'fallback' }}`, not Twig expressions. Template fixed.

# v1.8.21
## 07/12/2026

1. [](#new)
    * **Two display options for the info panel**: "Show .meta.yaml info" (editorial keys — title, prompt…; on by default) and "Show EXIF data" (technical keys — camera, exposure, ISO, MakerNote… recognized case-insensitively, whether they come from `auto_metadata_exif` or another tool; off by default). Both off: the sidebar no longer reads the media at all (header + comments only).
2. [](#bugfix)
    * **Admin list: column labels finally show** ("Page", "Nickname", "Comment"…) — Admin Next reads the label from `field.label`, not from the blueprint's top-level `label:`. The Date column is also declared `type: date` (formatted display).

# v1.8.20
## 07/12/2026

1. [](#improved)
    * Admin comment list: column renamed **"Page"**, and its sort goes through the Flex index (`url` key = route) — no more loading of objects when clicking the header (the SPA's headers are clickable for every column, which cannot be disabled).

# v1.8.19
## 07/12/2026

1. [](#improved)
    * **Admin comment list: clickable "Article" column** — `url` virtual property (`CommentObject` class) rendered as a link by the SPA, leading to the page concerned.
    * Documentation fixed: the list's "Approved" column is a state indicator (approval happens in the comment's edit form), not a clickable switch.

# v1.8.18
## 07/12/2026

1. [](#bugfix)
    * **Editing a comment in the admin emptied the list**: under Apache mod_php, PHP's current directory changes in shutdown functions (documented PHP gotcha); yet Grav core's Flex storage scan uses a relative path → the counter resync in `onShutdown` saw "zero objects" and overwrote the Flex index file with an empty index (the comment files themselves were intact). Double fix: `chdir(GRAV_ROOT)` at the start of `onShutdown`, and a safeguard in `CommentIndex` — if the storage folder is not visible, the saved index is kept instead of being destroyed. Reproduced and verified by simulating the shutdown cwd.

# v1.8.17
## 07/12/2026

1. [](#new)
    * **Comments on Flex Objects**: each comment becomes a Flex object (`galinf-comments` directory declared by the plugin, one YAML file per comment under `user/data/galinf/comments/`, sharded, unreachable over HTTP). New **"Comments"** entry in the Admin Next sidebar: paginated list sorted by date (sort on the Flex index alone, without loading the objects), clickable **Approved** column in the list, edit/delete/search/export, dedicated permissions (`admin.galinf-comments`).
    * **`bin/plugin galinf migrate-comments [--dry-run]` command**: migrates comments from the ≤ 1.8.16 schema (`comments:` key of the `.meta.yaml` files) to Flex, cleans up the meta.yaml files (deleted if they contained nothing else), resyncs the counters. Idempotent, dates and approvals kept.
    * Comments **follow their pages**: moving/renaming an article → routes rewritten; deletion → purge.
2. [](#improved)
    * SQLite counters (badge, "Commented" sort) resynced through Flex events (`onFlexObjectAfterSave/AfterDelete`): a single path for the public POST AND admin moderation, immediate effect in the gallery.
    * `.meta.yaml` files become purely editorial again: the public POST NEVER writes into the page tree any more.
3. [](#bugfix)
    * **Moderation removed from the lightbox** (Approve/Delete buttons, "pending" mention): it happens in the admin, where one is really logged in — the Admin Next login did not authenticate on the front end.

# v1.8.16
## 07/12/2026

1. [](#bugfix)
    * **Meta endpoint robust against binary EXIF**: a `.meta.yaml` containing non-UTF-8 bytes (EXIF fields such as `MakerNote`, stored as `!!binary` or raw) made the endpoint return an **empty response** (silent `json_encode` failure) or a 500 error — the sidebar stayed without info or comment button for the whole article. Now: encoding with `JSON_INVALID_UTF8_SUBSTITUTE` (invalid bytes become �), and an unreadable meta file is skipped with a warning in `logs/grav.log` instead of killing the endpoint.

# v1.8.15
## 07/11/2026

1. [](#improved)
    * **Bootstrap rollback (v1.8.14)**: the plugin is **100% standalone** again — no Bootstrap class or detection, custom CSS only (light, works on any theme). The "Add a comment" form still **slides open with an animation**, in pure CSS (`grid-template-rows` 0fr→1fr, no library).

