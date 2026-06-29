# Push KennelFlow Core

## GitHub

```bash
cd /Users/randy/wordpress-plugins/KennelPress/kennelflow-core
git push -u origin main
git push origin v0.3.23
gh release create v0.3.23 --title "KennelFlow Core 0.3.23" --notes-file .release/0.3.23.md
```

## WordPress.org

```bash
cd /Users/randy/wordpress-plugins/KennelPress/kennelflow-core
npm run build
./deploy-to-wordpress-org.sh --commit
```

Last published on WordPress.org: **0.2.3**. This release publishes **0.3.23**.
