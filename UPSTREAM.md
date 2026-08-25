# Upstream Synchronization

HorizonFlow is an independently maintained fork of Laravel Horizon.

- Upstream repository: https://github.com/laravel/horizon
- Upstream branch: `5.x`
- Imported/synchronized baseline: `60e9d1369458762c55be6167bbf31930406ac3c9`
- Latest synchronized upstream commit: `562bacfde5812921c4aa19702ad1c8406f08858b`
- Synchronization method: reviewed patch range with applicable upstream commits cherry-picked
- Last synchronization date: 2026-08-25

## Checking for updates

```bash
git fetch upstream 5.x
git log --oneline 562bacfde5812921c4aa19702ad1c8406f08858b..upstream/5.x
git diff --stat 562bacfde5812921c4aa19702ad1c8406f08858b..upstream/5.x
```

Upstream changes must be reviewed and combined with HorizonFlow-specific modifications rather than applied blindly.
