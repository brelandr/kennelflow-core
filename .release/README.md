# KennelFlow Core — release artifacts

Versioned release notes for GitHub and WordPress.org. Build distributable assets before tagging:

```bash
cd /path/to/kennelflow-core
npm ci
npm run build
./create-plugin-zip.sh
```

Deploy to WordPress.org SVN:

```bash
./deploy-to-wordpress-org.sh --commit
```

See `PUSH.md` for GitHub tag and release steps.
