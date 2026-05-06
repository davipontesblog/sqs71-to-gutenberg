# Squarespace 7.1 → Gutenberg Converter

A WordPress plugin that re-scrapes a live Squarespace 7.1 site and rewrites
matching WordPress posts as proper Gutenberg block markup
(`wp:image`, `wp:gallery`, `wp:paragraph`, `wp:heading`, `wp:embed`,
`wp:list`, `wp:quote`, `wp:separator`).

Built for migrations where the standard Squarespace XML export drops gallery
structure (galleries are exported "version 7.0 only") or leaves Squarespace
HTML soup behind that the block editor can't edit cleanly.

## Why this exists

Squarespace's official XML export does not export gallery content from 7.1
sites and produces nested `<div class="sqs-block ...">` HTML rather than
discrete image references. As a result, when imported with the WordPress
Importer (or WordPress.com's Squarespace import) the posts end up as one
big "Classic"/HTML blob in the editor and images are referenced by external
URL only.

This plugin works around all of the above by **fetching the live rendered
HTML** of each post from the source site, parsing the Squarespace block
markup, and emitting equivalent Gutenberg block markup with images uploaded
to the local Media Library.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- The source Squarespace site is still **publicly reachable** (this plugin
  does not work against an expired/cancelled subscription)
- Each WordPress post you want to convert has the same slug as its
  Squarespace counterpart (this is the default for sites imported via the
  built-in WordPress importer)

## Install

1. Drop the `sqs71-to-gutenberg` folder into `wp-content/plugins/`.
2. Activate **Squarespace 7.1 → Gutenberg Converter** from Plugins.
3. Visit **Tools → Squarespace → Gutenberg**.

## Configure

- **Source domain** — the live Squarespace 7.1 site, e.g. `https://www.example.com`.
- **Post URL pattern** — placeholders `{year}`, `{month}`, `{day}`, `{slug}`.
  Example for Walkabout-style sites:
  `/walkabout-chronicles/{year}/{month}/{day}/{slug}`.
- **Batch size** — how many posts per "Run batch" click.
- **Dry run** — preview block-type counts and a content head without writing.
- **Force reconvert** — re-run on posts already marked converted.
- **Date offset (days)** — set if your imported post dates are off by N days
  vs. the live site. The fetcher also automatically tries ±1 day to absorb
  timezone drift.
- **Image quality** — Squarespace `?format=` value (defaults to `2500w`).
- **Request timeout** — per-URL HTTP timeout in seconds.

## Run

Either use **Tools → Squarespace → Gutenberg** in wp-admin, or run via WP-CLI:

```bash
wp sqs71 convert --slugs=routines,the-shifting --dry-run
wp sqs71 convert --all --batch-size=20
wp sqs71 convert --all --force
```

The plugin marks each converted post with `_sqs71_converted_at` post meta so
re-runs skip them by default.

## What it converts

| Squarespace block class      | Gutenberg block emitted                      |
|------------------------------|----------------------------------------------|
| `sqs-block image-block`      | `core/image` (with caption, alt, attachment) |
| `sqs-block gallery-block`    | `core/gallery` containing `core/image` items |
| `sqs-block html-block`       | `core/paragraph` / `core/heading` / `core/list` / `core/quote` |
| `sqs-block embed-block`      | `core/embed`                                 |
| `sqs-block video-block`      | `core/embed`                                 |
| `sqs-block quote-block`      | `core/quote`                                 |
| `sqs-block horizontal-rule-block` / `line-block` | `core/separator`         |
| `sqs-block spacer-block`     | dropped (block editor handles spacing)       |
| any other `sqs-block`        | `core/html` fallback so content isn't lost   |

## Caveats

- Squarespace's HTML markup isn't formally versioned. A class-name change
  on their side could break the parser. **Always do a 3-post dry run first.**
- The plugin writes to `post_content`. **Back up the database before a full
  run.**
- Supported post type is `post`. Pages and custom types can be added by
  extending the rewriter.

## License

GPL v2 or later — see `LICENSE`.
