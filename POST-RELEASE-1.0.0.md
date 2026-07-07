# Post-release steps for 1.0.0 (maintainer)

Tags `1.0.0` are pushed on Core, Filament, and Pro. Complete these steps with a `dbflow-labs` org account that can create releases (e.g. `baron-dbflow`).

## GitHub Releases

```bash
gh auth login   # as org maintainer with release permission

cd dbflow-core
gh release create 1.0.0 --title "DBFlow Core 1.0.0" --notes-file RELEASE-NOTES-1.0.0.md

cd ../dbflow-filament
gh release create 1.0.0 --title "DBFlow Filament 1.0.0" --notes-file RELEASE-NOTES-1.0.0.md

cd ../../loongdomSoft/dbflow-filament-pro
gh release create 1.0.0 --title "DBFlow Filament Pro 1.0.0" --notes-file docs/release-notes/1.0.0.md
```

## Packagist verification

Packagist p2 metadata already lists `1.0.0` for Core and Filament. If local Composer still resolves only RC:

```powershell
Remove-Item "$env:LOCALAPPDATA\Composer\repo" -Recurse -Force
composer clear-cache
composer show dbflowlabs/core:1.0.0
```

Smoke test (stable, no `@RC`):

```bash
composer require dbflowlabs/core:^1.0
```

## Documentation site

Update [dbflow.dev/docs](https://dbflow.dev/docs) maturity copy from alpha to **1.0.0 stable** and add version selector entry.
