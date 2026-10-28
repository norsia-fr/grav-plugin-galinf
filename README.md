# Galinf — Infinite gallery for Grav 2

**Galinf** is a plugin for [Grav CMS](https://github.com/getgrav/grav) that
gathers the images of every selected page on a site into **a single gallery
you can scroll endlessly**, even with tens of thousands of images. It installs
**without touching the theme**: the gallery goes into a page's content with a
shortcode, or into a template with a Twig function.

![The gallery: a justified mosaic of every image, with a sort menu](https://raw.githubusercontent.com/norsia-fr/grav-plugin-galinf/main/screenshots/gallery.jpg)

## What the plugin does

- **A smooth "infinite" gallery.** Justified mosaic, loading as you scroll,
  and only 50 to 150 elements in the page at any time, whatever the total
  number of images: the browser never chokes.
- **A gallery per article.** The same display, limited to the images of the
  current page or of a given page.
- **A full-featured lightbox** (PhotoSwipe): slideshow, rotation, fullscreen,
  download, thumbnail strip, info panel fed by the images' metadata, link to
  the source article.
- **Comments on each image** (optional), with moderation in the admin and
  anti-bot protection: built in, or Cap / Cloudflare Turnstile / Google
  reCAPTCHA through the Form plugin.
- **Several sort orders**: by date, by the manual page order set in the admin,
  random, commented images.
- **Animated GIFs** animate in the gallery.
- **Hide an image with a simple tag**, without deleting it.
- **An index that keeps itself up to date** when an article is saved or an
  image is added to it from the admin, and a **Maintenance** tab for the
  rest: reindexing with progress, purge, shrinking of oversized images.
- **Persistent thumbnails**, which survive Grav updates and cache clears.
- **English and French** included; any text can be changed or translated into
  another language from the admin.

![The lightbox with its info panel and comments](https://raw.githubusercontent.com/norsia-fr/grav-plugin-galinf/main/screenshots/lightbox.jpg)

## Requirements

- **Grav 2.0** or later.
- **PHP 8.3** or later, with the **sqlite3** and **gd** extensions.
- The **shortcode-core** plugin (installed automatically as a dependency).
- The **api** and **admin2** (Admin Next) plugins for everything that happens
  in the admin: options, automatic indexing on save, maintenance, moderation.
  The public gallery works without them.
- The **flex-objects** plugin (shipped with Grav 2) if you enable comments.
- The **form** plugin only if you choose Cap, Cloudflare Turnstile or Google
  reCAPTCHA as the comments' anti-bot check.

## Installation

The plugin is not in the Grav catalog yet. Its releases are on GitHub:
[github.com/norsia-fr/grav-plugin-galinf/releases](https://github.com/norsia-fr/grav-plugin-galinf/releases).
Download the **Source code (zip)** of the latest release, then install it in
one of the following two ways.

**From the admin**: open the **Tools** menu, **Direct Install** tab
(`admin/tools#direct-install`). Under **Install from file**, drag and drop
the `.zip` or select it.

**By hand**: unzip the archive, rename the folder it contains to `galinf`
and put it in `user/plugins/`, then clear the cache (`bin/grav clearcache`,
or the admin button).

*Install from URL* does not work with a GitHub address on a default Grav
site: the **Official GPM Only** setting (Configuration → System) only
allows addresses of the Grav catalog. You can turn it off for the time of
the install, then turn it back on; installing from the file avoids that.

To update, install the new release the same way: settings, index,
thumbnails and comments are kept.

## After installing: three steps

The plugin does nothing until you tell it which pages to use: **no page is
indexed by default**, so that no unwanted image ends up in a public gallery.

1. **Choose the pages.** In the admin, Plugins → Galinf → **General** tab,
   fill in at least one of the two lists, then save:
   - **Source pages/folders**: routes whose pages are indexed, e.g. `/blog`
     (`/` for the whole site);
   - **Indexed templates**: page templates whose images are indexed, e.g.
     `item` for blog articles.

   With both, only the pages matching both are taken.
2. **Build the index.** Still in the plugin options, **Maintenance** tab →
   **Run all**. From the command line: `bin/plugin galinf reindex`.
   Afterwards, the index updates itself when an article is saved from the
   admin.
3. **Display a gallery.** Put `[galinf]` in a page's content (see below for
   the options), or `{{ galinf() }}` in a theme template.

## Displaying a gallery

Two ways to insert a gallery:

- a **shortcode** in a page's content;
- a **Twig function** in a theme template.

Both follow the same logic.

### The principle: with or without `src`

- **Without `src`** → **global** gallery: every indexed image (according
  to the plugin's `templates`/`roots` options).
- **With `src`** → targets the media of **one page**:
  - `src="article"` → the **current page** (ideal in an article template);
  - `src="/blog/my-page"` → a **given page**, designated by its **route**
    (the page's URL path, e.g. `/blog/my-page` — not the folder name).

Optional parameters in global mode:

- `sort` = `asc` | `desc` | `random` | `commented` — initial order: ascending,
  descending, random, or commented images first (if comments are enabled).
  The old `recent`/`oldest` values are still accepted;
- `field` = `date` | `folder` — criterion: publication date, or **manual page
  order in the admin** (default: the plugin setting).

### Shortcode (in a page's content)

```
[galinf]                          (global gallery)
[galinf sort="asc"]               (global, initial order)
[galinf src="article"]            (current page)
[galinf src="/blog/my-page"]      (a given page)
[galinf-article]                  (shortcut for src="article")
```

### Twig function (in a theme template)

```twig
{{ galinf() }}                     {# global gallery #}
{{ galinf({sort: 'asc'}) }}        {# global, initial order #}
{{ galinf('/blog/my-page') }}      {# a given page (shortcut) #}
{{ galinf_article() }}             {# current page #}
```

> A string passed to `galinf('…')` is read as `src`; pass an
> object `{ … }` to set several options (e.g. `sort`).

Example article template (`item.html.twig` or a `blogitem.html.twig`):

```twig
{% block content %}
    <article>{{ page.content|raw }}</article>
    {{ galinf_article() }}
{% endblock %}
```

## Configuration

Options are set in the admin (Plugins → Galinf), spread over five tabs. Each
option explains itself in place.

| Tab | What you set there |
|---|---|
| **General** | Which pages are indexed: templates, source pages or folders (e.g. `/blog`), image formats. |
| **Gallery** | Batch size, sort criterion and order, visitor sort menu, row height, spacing, thumbnail width, hiding tags, performance settings. |
| **Lightbox** | Loop, info panel, thumbnail strip, and whether to show each toolbar button. |
| **Comments** | Enabling, pre-moderation, who can comment, required nickname, maximum length and counter, anti-bot check, comments per day. |
| **Maintenance** | Index status, reindexing, thumbnail purge, oversized images, data deletion before uninstalling. |

Without the admin, copy `user/plugins/galinf/galinf.yaml` to
`user/config/plugins/galinf.yaml` and edit that copy.

After changing the scope (templates, source folders, formats) or the
thumbnail width, run **Run all** in the Maintenance tab.

![The Comments tab of the plugin settings](https://raw.githubusercontent.com/norsia-fr/grav-plugin-galinf/main/screenshots/admin-settings-comments.png)

![The Maintenance tab: index status and upkeep actions](https://raw.githubusercontent.com/norsia-fr/grav-plugin-galinf/main/screenshots/admin-maintenance.png)

## Languages

The plugin ships in **English** (the default) and **French**.

- **Admin**: options and the Maintenance tab follow the language each user
  chose in their Admin Next preferences.
- **Visitors**: the gallery, lightbox and comments use the site language if
  Grav declares one (`languages.supported`); otherwise the visitor's browser
  language, falling back to English.
- **Changing a text, or adding a language**: every string is a
  `PLUGIN_GALINF.*` key, editable in Admin Next's translation editor. Changes
  are stored in `user/languages/` and survive plugin updates. A language added
  there is offered to visitors automatically.

## CLI commands

Everything the Maintenance tab does is also available from the command line:

```
bin/plugin galinf reindex                     # updates the index, creates missing thumbnails
bin/plugin galinf reindex --no-thumbs         # index only: no thumbnail created (fast)
bin/plugin galinf reindex --force-thumbs      # recreates every thumbnail, even existing ones
bin/plugin galinf reindex --clear             # starts again from an empty index
bin/plugin galinf purge-thumbs                # deletes orphan thumbnails
bin/plugin galinf shrink-oversized [--yes]    # shrinks oversized images in place (originals replaced)
bin/plugin galinf uninstall-data [--comments] [--yes]   # before uninstalling: deletes index and thumbnails
```

## Uninstalling

Removing the plugin does not delete what it created on the server. This is
deliberate: uninstalling to reinstall another version must not lose anything.
To get back to a clean site, delete its data **just before** uninstalling it:

- in the admin, **Maintenance** tab → **Uninstall** → **Delete the data**;
- or from the command line: `bin/plugin galinf uninstall-data`.

| Data | Location | Deleted |
|---|---|---|
| Gallery index | `user/data/gallery-index/` | yes |
| Thumbnails | `galinf-thumbs/` at the site root | yes |
| Visitor comments | `user/data/galinf/comments/` | **only on request** (checkbox, or `--comments`) |
| Settings | `user/config/plugins/galinf.yaml` | no |

The index and thumbnails are recreated by a reindex. Comments, on the other
hand, cannot be recovered once deleted.

As with every Grav plugin, settings stay in place after uninstalling, so that
a reinstall finds them again. To erase them, delete
`user/config/plugins/galinf.yaml` by hand.

While the plugin stays active, a simple visit to the gallery recreates an
empty index database: so delete the data and uninstall right after. If you
remove the plugin folder by hand (FTP), also delete the locations above by
hand.

## Credits

- Vibecoded with [Claude](https://claude.com/claude-code).
- Lightbox: [PhotoSwipe](https://photoswipe.com/) 5.4.4 by Dmitry Semenov
  (MIT license), bundled with the plugin; its license is in
  `assets/photoswipe/LICENSE`.

## License

MIT, see `LICENSE`.

---

# Going further

The following sections detail how the plugin works. You do not need them to
install it or to display a first gallery.

## Indexing: what is automatic, what is not

The plugin never reads pages on the fly: the gallery relies on an **index**
(an SQLite database) of the images, updated when content changes.

- **Automatic** from the admin: saving, copying, moving, reordering or
  deleting an article (or a parent page: its articles follow), adding or
  removing an image, editing an image's metadata.
- **To run by hand**: after a change made outside the admin (FTP upload,
  direct file editing, bulk import), no event notifies the plugin. Run
  **Run all** or `bin/plugin galinf reindex`.

A reindex that runs to completion also removes articles that no longer exist
from the index.

**Scheduled publishing dates** (`publish_date`, `unpublish_date`) are honored
to the second: a scheduled article is indexed in advance but only appears in
the gallery on its start date, and disappears from it on its end date,
without reindexing.

## Restricted-access pages

The gallery is **public**: the feed, the thumbnails (copies served directly
by the web server) and the info panel do not check who is looking. So that
restricted content does not end up there by mistake, a page under an access
rule (`access:` in its header, or in a parent page's header) **is never
indexed**. If an indexed page becomes restricted, it leaves the index and its
thumbnails are deleted on save; a rule set on a parent page likewise removes
all its articles.

Consequence: the images of a restricted page do not appear in the main
gallery, even for a logged-in visitor who is allowed to see that page. They
remain visible on the page itself.

Only pages stored under `user/pages/` and reachable by their address are
indexed: a page with `routable: false` (e.g. a modular sub-page) or a page
registered by another plugin from its own folder never is.

> **Grav itself serves the media of a restricted page** to anyone who knows
> their address, unless the login plugin's `protect_protected_page_media`
> option is enabled (`user/config/plugins/login.yaml`). Galinf never reveals
> these addresses, but if your site has restricted pages, enabling this
> option protects their original images too.

## Sorting by manual page order

The "manual order" criterion follows the page tree as you arrange it by drag
and drop in the admin; a reordering there is reflected in the gallery
automatically. Folder names are sorted in **natural order** (numeric prefixes
included): the same order as an `order: by: folder` collection, so a blog
page and the gallery stay aligned.

## Lightbox: enforced or free states

The **"Sidebar shown"** and **"Thumbnail strip shown"** options set the state
on opening. If the matching toolbar button is disabled, that state is
**enforced** for every visitor; otherwise it is just a default, and each
visitor's choice (remembered in their browser) takes precedence. PhotoSwipe's
zoom button is removed: clicking the image already zooms.

## Info panel (`.meta.yaml` files)

Grav natively associates an **`image.jpg.meta.yaml`** file placed next to
`image.jpg` (free keys: `title`, `description`…). The lightbox shows this
information in a **side panel** ("i" button in the top bar; bottom sheet on
mobile):

- **Scalar keys** are shown in file order — beware: their content therefore
  becomes public. Two options filter the content: **"Show .meta.yaml info"**
  (editorial keys — title, description, prompt…; on by default) and **"Show
  EXIF data"** (technical keys — camera, exposure, ISO… — recognized whatever
  their origin; off by default, this data being often verbose, even binary).
- Each key is a `galinf-meta-<key>` block (label + value as plain text), which
  the theme can style (e.g. `.galinf-meta-title`).
- Read **on demand** (one request per article opened in the lightbox,
  `gallery-meta.json` endpoint), through Grav's native media API: **nothing is
  stored in the index**, a change to a `.meta.yaml` shows up immediately,
  without reindexing.
- The panel's open/closed state is remembered on the visitor's side. The
  **"Info panel (sidebar)"** option disables both the panel and the endpoint.

## Hiding an image with a tag

An image can be removed from a gallery **without being deleted or moved**, by
adding a **tag** to its metadata:

| Tag (default) | Effect |
|---|---|
| `nogal` | the image disappears from **its own article's** gallery, but stays in the infinite gallery |
| `nogalinf` | the image disappears from the **infinite gallery** (every sort order, "Commented" included), but stays in its article's gallery |

Both tags together hide the image everywhere; the image stays indexed and
becomes visible again as soon as the tag is removed.

**Where to add the tag**: in the **Tags** field of the admin's metadata
editor (page media → metadata edit button). It writes a list into the `tags`
key of the `.meta.yaml` — the equivalent, by hand:

```yaml
tags:
  - nogalinf
```

**When it takes effect**: hiding is resolved **at indexing time** (the feed
only reads the index, never the files on the fly). The article is therefore
**reindexed automatically** when the metadata is saved from the admin. After
a `.meta.yaml` change made outside the admin (direct file editing, import),
run `bin/plugin galinf reindex` again.

Both tag names are **configurable** in the plugin options (Gallery tab,
"Hide an image with a tag" section); emptying a field disables that tag.
Matching ignores case and extra spaces.

> **Changing a keyword does not apply retroactively.** Since hiding is
> computed at indexing time, images already hidden by the old keyword stay
> hidden, and the new one only applies to articles reindexed afterwards.
> After a change: **run a reindex** (Maintenance tab of the options, or
> `bin/plugin galinf reindex`). No need to clear the cache — the new value is
> read immediately. Adding or removing a tag on an image, on the other hand,
> takes effect right away (automatic reindex of the article).

These tags are **internal markers**: they are stripped from the lightbox info
panel, and so never shown to visitors. An image whose `tags` only contained
markers simply no longer shows that line.

> This filter only concerns the plugin's galleries: a hidden image remains
> reachable by its URL and can still be used by the theme ("hero" image,
> etc.), which reads `page.media` directly.

## Comments

Each image can be commented on from the lightbox info panel. The flat-file
spirit is kept: each comment is a **Flex object** (a small YAML file under
`user/data/galinf/comments/`, never reachable over HTTP), through a
`galinf-comments` directory the plugin declares itself — the **flex-objects**
plugin (shipped with Grav 2) is required. The SQLite index only keeps a
**counter** (badge on the grid thumbnails, "Commented" sort), kept in sync
live through Flex events and recomputed on every reindex.

![Comment moderation in the admin](https://raw.githubusercontent.com/norsia-fr/grav-plugin-galinf/main/screenshots/admin-comments.png)

- **Moderation in the admin**: a **"Gallery comments"** entry appears in the
  Admin Next sidebar — paginated list sorted by date, **Approved** column
  (state in the list, approval in the edit form), **Page** column linking to
  the article, editing (with a preview of the commented image), deletion,
  search. Dedicated permissions (`admin.galinf-comments`, CRUDPL) to delegate
  moderation without granting full powers.
- **Who can comment** (option): everyone (free nickname), logged-in members,
  or administrators. "Logged in" means logged in **on the site** (login
  plugin): being logged in to the admin does not count, Grav keeps the two
  apart. Administrators, and accounts allowed to edit gallery comments, are
  moderators: their comments skip pre-moderation and the daily cap.
  Required nickname: your choice. A comment left without a nickname is
  signed "Anon".
- **Length** (option): 300 characters by default, from 50 to 1000. The
  "characters left" counter appears once less than 20 % of the limit is left,
  or all the time if you enable "Counter always visible". In the admin,
  existing comments can still be edited up to 2000 characters.
- **Anti-bot** (visitors who are not logged in): a honeypot and a per-IP
  rate limit (hashed, 5 comments per 10 minutes) always apply, plus the
  check chosen in the **Anti-bot check** option:
  - **Galinf question** (default): a simple sum with a signed, single-use
    token and a time trap. Nothing else to install.
  - **Cap**: a proof of work the visitor's browser computes in the
    background, with no click. Self-hosted: everything stays on your server.
  - **Cloudflare Turnstile**: Cloudflare's challenge, usually without a
    click. It **sends visitor data to Cloudflare**: mention it in your
    privacy policy, and ask for consent where your law requires it.
  - **Google reCAPTCHA**: the version set in the Form plugin — v2 checkbox,
    v2 invisible, or v3 (a score, no challenge; its threshold is set in the
    Form plugin too). It **sends visitor data to Google**: same privacy
    caution as Turnstile. Google's test keys cannot be used: the Form plugin
    checks the site's host name, which they never return.
  - **None**: no check, only the honeypot and the rate limit. Bots do not
    look at whether their message shows up: with pre-moderation, they land
    in the moderation list; without it, on the site.

  Cap, Turnstile and reCAPTCHA come from the **Form plugin**, which must be
  enabled, and use its settings (Cap difficulty, theme, reCAPTCHA version).
  Turnstile and reCAPTCHA also need a site key and a secret key from the
  Cloudflare or Google dashboard, entered in the Form plugin's settings. If
  the chosen check cannot work (Form disabled, keys missing), visitors see
  "Comments are temporarily unavailable" and the **Maintenance** tab says
  why.

  These barriers stop generic bots; against targeted spam, enable
  pre-moderation.
- **Comments per day** (option): 20 by default for the whole site, or
  unlimited. Beyond it, new comments are refused until the next day (server
  date). It stops a flood of bots coming from many addresses, which the
  per-address limit does not. Once it is reached, the lightbox says so in
  place of the comment form.
- **Site behind a proxy or a CDN** (Cloudflare, reverse proxy): the server
  only sees the intermediary's address, and every visitor would share the
  same limit. In Grav's configuration (`user/config/system.yaml`), enable the
  header your intermediary sets, and only that one:
  `http_x_forwarded.cf_connecting_ip: true` behind Cloudflare,
  `http_x_forwarded.ip: true` behind a proxy that fills in
  `X-Forwarded-For`. The plugin then uses the visitor's real address.
- **Pre-moderation** (option): comments wait for approval in the admin list.
  Disabled: published immediately, deleted afterwards if needed.
- Comments **follow their pages**: moving/renaming an article in the admin →
  their routes are rewritten; deleting an article → its comments are purged.

## Persistent thumbnails

Thumbnails are stored in **`galinf-thumbs/`** at the site root, **outside
Grav's cache** (a statically served folder, created automatically).
Consequences:

- A **Grav update** or a **"Clear all"** of the cache **does not destroy
  them** → the gallery stays displayed, **no reindex needed**.
- They are generated **only once** (stable key `md5(path|width)`) then
  reused, even during a reindex.
- Changing the **thumbnail width** (`thumb_width`) → run **Run all** in the
  Maintenance tab (new thumbnails, then purge of the old ones, now orphaned).
- Deleting images leaves orphan thumbnails → **Purge orphan thumbnails** in
  the Maintenance tab, or `bin/plugin galinf purge-thumbs`, to free up space.
- The `galinf-thumbs/` folder must be **reachable over HTTP** (it is by
  default with Grav's `.htaccess`, like `images/`).
- An `.htaccess` is placed there automatically with a **long browser cache**
  (`immutable`, 1 year): thumbnail names are hashes, their content never
  changes. Requires Apache with `AllowOverride` (Grav's default).
- If a **thumbnail file disappears** from disk (folder emptied by hand,
  partial restore), the gallery shows the original in its place instead of a
  broken image; **Create missing thumbnails** recreates the files.

## Very large images (25-megapixel limit)

Since Grav 2.1.7, the core refuses to resize an image whose
`width × height` exceeds `system.images.max_pixels` (25,000,000 by default).
No thumbnail is created then, and the gallery serves the original instead,
which makes loading heavier.

Reindexing **reports** these images, both in the summary of
`bin/plugin galinf reindex` and in the Maintenance tab of the options
(count, list, dimensions).

From the box that lists them, each oversized image can be **shrunk in place**
("Shrink" button, or checkboxes then "Shrink selection"): it is brought just
under the limit, then its thumbnail is created. From the command line:
`bin/plugin galinf shrink-oversized` (`--yes` to skip confirmation). Allow a
few seconds and 100 to 200 MB of memory per image.

> **The original is replaced permanently**, with no backup copy, and its
> **EXIF data is lost** (the orientation is applied to the image first). The
> file name does not change: captions and tags in the `.meta.yaml`, comments
> and links remain valid. JPEG, PNG and WebP only. If the web server is not
> allowed to write the file (photo uploaded by FTP under another account),
> the image is left untouched and the error is shown.

To avoid the problem at the source, Admin Next can **shrink images in the
browser at upload time**. The setting is read from the configuration of the
old admin plugin, which does not need to be installed: create
`user/config/plugins/admin.yaml` with

```yaml
pagemedia:
  resize_width: 5000     # maximum width, in pixels
  resize_height: 5000    # maximum height, in pixels
  resize_quality: 0.9    # JPEG quality, from 0 to 1
```

The image is brought within this frame while keeping its proportions, never
enlarged. 5000 × 5000 guarantees staying under 25 megapixels. Good to know:

- only JPEGs and PNGs uploaded through the admin are shrunk (not files
  uploaded by FTP);
- the setting applies to the whole site, not just the gallery;
- an image of more than 50 megapixels is not shrunk and arrives as is;
- since the image is re-encoded by the browser, its EXIF data may be lost.
