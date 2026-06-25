WordPress.org Plugin Directory assets — KennelFlow Core
=========================================================

Plugin slug: kennelflow-core

This folder mirrors the SVN `assets/` directory at the WordPress.org repository root
(sibling to `trunk/` and `tags/`). These files are NOT shipped inside the plugin zip.

Drop finished PNG/JPG files here, then commit them to SVN:

  plugins.svn.wordpress.org/kennelflow-core/assets/

Required filenames (exact spelling, lowercase, hyphens)
-----------------------------------------------------

Icons (square logo mark):
  icon-128x128.png          128 x 128 px
  icon-256x256.png          256 x 256 px  (retina)

Banners (plugin page header):
  banner-772x250.png        772 x 250 px
  banner-1544x500.png       1544 x 500 px  (retina, recommended)

Screenshots (plugin page gallery):
  screenshot-1.png
  screenshot-2.png
  screenshot-3.png
  ... (continue numbering with no gaps)

Each screenshot-N.png must have a matching caption in readme.txt under == Screenshots ==:

  1. First caption here
  2. Second caption here

Suggested captures for KennelFlow Core
--------------------------------------
  1. Hub dashboard / main admin menu
  2. Pet (kf_pet) and location management
  3. Owner portal or booking wizard
  4. Compliance / medical records view
  5. Calendar or settings screen

Notes
-----
* Do not put these files in assets/dist/ or build output folders.
* Release zips exclude this `.wordpress-org/` folder automatically.
