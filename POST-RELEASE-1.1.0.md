# Post-release steps for 1.1.0 (maintainer)

Complete these steps with a `dbflow-labs` org account that can create releases (e.g. `baron-dbflow`).

## Git tags

Ensure `1.1.0` (or `v1.1.0`, matching existing tag style) is pushed on Core, Filament, and Pro after the squash commit lands on `main`.

## GitHub Releases

```bash
gh auth login   # as org maintainer with release permission

cd dbflow-core
gh release create 1.1.0 --title "DBFlow Core 1.1.0" --notes-file RELEASE-NOTES-1.1.0.md

cd ../dbflow-filament
gh release create 1.1.0 --title "DBFlow Filament 1.1.0" --notes-file RELEASE-NOTES-1.1.0.md

cd ../dbflow-filament-pro
gh release create 1.1.0 --title "DBFlow Filament Pro 1.1.0" --notes-file docs/release-notes/1.1.0.md
```

Adjust Filament/Pro `--notes-file` paths if those packages use a different layout.

## Packagist verification

```powershell
Remove-Item "$env:LOCALAPPDATA\Composer\repo" -Recurse -Force
composer clear-cache
composer show dbflowlabs/core:1.1.0
```

Smoke test:

```bash
composer require dbflowlabs/core:^1.1
```

## Documentation site

Update [dbflow.dev/docs](https://dbflow.dev/docs) for **1.1.0** (version selector, upgrade notes, scheduler/queue requirements for SLA and reliable actions).
