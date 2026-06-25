In-plugin branding assets — KennelFlow Core
==============================================

Plugin slug: kennelflow-core

These files ship inside the plugin zip and are enqueued from PHP (admin menus,
about screens, settings headers). Keep them separate from assets/dist/ (JS/CSS).

Recommended files
-----------------
  logo.svg                    Full horizontal wordmark (preferred)
  logo.png                    Raster fallback for logo.svg
  icon.svg                    Square mark only (no text)
  icon-32.png                 32 x 32 — admin menu icon
  icon-128.png                128 x 128 — settings / about header
  icon-256.png                256 x 256 — retina in-plugin use

Optional slug-prefixed copies (when sharing assets across repos):
  kennelflow-core-logo.svg
  kennelflow-core-icon-128.png

Usage in PHP (example)
----------------------
  LTKF_PLUGIN_URL . 'assets/images/branding/icon-32.png'

WordPress.org listing images (banner, directory screenshots) belong in
`.wordpress-org/` at the plugin root — not in this folder.
