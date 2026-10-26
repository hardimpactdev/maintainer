# Maintainer

`hardimpactdev/maintainer` updates dependencies for one repository. Composer, npm, and Bun stay in that repository. The package only runs the commands and prints the result. It does not choose versions, commit, or open a pull request.

A Laravel app is a normal consumer. The tool itself has no framework dependency.

## Install

```bash
composer require --dev hardimpactdev/maintainer
```

PHP 8.4 or newer.

## Wrapper

Orbit runs `scripts/dependencies`. Keep that file as a short wrapper so the repository can install this package on a fresh checkout:

```sh
#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -x vendor/bin/maintainer ]; then
  composer install --no-interaction --no-scripts >&2
fi
exec vendor/bin/maintainer dependencies "$@"
```

The same file is in `resources/dependencies`. Copy it to `scripts/dependencies` and mark it executable. The wrapper forwards every argument, so the existing Swamp calls keep working.

## Commands

Run the commands from the repository root.

```text
vendor/bin/maintainer dependencies routine [path]
vendor/bin/maintainer dependencies detect [path]
vendor/bin/maintainer dependencies summary [--base <git ref>]
vendor/bin/maintainer dependencies package [path] <composer|js> <package> <x.y.z> [--advisory ID ...]
vendor/bin/maintainer dependencies package [path] <composer|js> <package> <x.y.z> <major|security> [advisory-id ...]
vendor/bin/maintainer dependencies check
```

`routine` updates within the ranges already declared. For each selected path it runs `composer update --no-interaction` when that path has Composer, then the JavaScript update. It stops at the first failed command and returns that exit code.

`detect` prints one JSON object on stdout. A frozen install may create `node_modules`, and it does not change the lockfile. Bootstrap output goes to stderr. `package` updates one direct dependency to the exact version you pass.

The second `package` form is what Swamp sends today:

```text
package composer livewire/livewire 4.9.9 security PKSA-xhv3-nr94-h8db
```

`--advisory` is the same thing. Repeat the flag for more than one advisory. Security mode requires at least one advisory id. Major mode accepts none.

`package` uses `.` when you omit the path. Pass a path, such as `server/web` or `mobile`, to select another configured directory. `routine` and `detect` without a path cover every configured path.

`check` runs the commands in `maintainer.json` `checks`. Routine, detect, package, and summary never run those checks. Orbit's handoff check does that work.

## Pull request summary

`summary` compares the Composer and JavaScript lockfiles of every configured path with a git commit. It prints a pull request title, a blank line, and the body. It does not commit or open the pull request.

```bash
vendor/bin/maintainer dependencies summary --base abc123
```

Omit `--base` to use the merge base of `HEAD` and `origin/HEAD`. The working tree counts, including changes that are not committed yet.

One changed package:

```text
Update livewire/livewire to 4.5.0 (security fix)

This updates livewire/livewire from 4.2.0 to 4.5.0.

Security fixes

livewire/livewire: [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db)

| Package | From | To | note |
| --- | --- | --- | --- |
| livewire/livewire | 4.2.0 | 4.5.0 | security fix [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db) |
```

Several packages use the title `Maintenance update for October 2026`. The month is UTC. The first sentence is always `This is a routine maintenance update. The packages below were updated to their newest safe versions.`

A major upgrade, including a newer minor of a `0.x` release, adds an `Action needed` section. Each line names that package, for example `laravel/framework 13.38.0 → 14.0.0 is a major update. Check its upgrade notes before you deploy.` The section is left out when there is no major update.

A cleared advisory adds a `Security fixes` section. Each line names the package and links the advisory id. An id with no `https` URL stays plain text. The section is left out when the update clears nothing.

One package keeps `Update <name> to <version>`, and adds ` (security fix)` when that update clears an advisory. The sentence under the title says what happened: `This updates livewire/livewire from 4.2.0 to 4.5.0.` A removal is `Remove <name>` and `This removes <name> <version>.` An addition is `Add <name> <version>` and `This adds <name> <version>.` There is no `Tested` line. When the package is not in the repository root, the table and those two sections name it as `expo (mobile)`.

The table lists each direct dependency whose locked version changed, including one that was removed. A security fix is an advisory that the update clears. An advisory that still applies to the new version is not a fix. Stopping a direct declaration is not a security fix when that package stays installed and the advisory is still reported. A Composer lock with no packages left still lists the removals. A JavaScript advisory that `detect` attaches to a direct dependency is listed on that direct dependency, including when only a nested copy in the lockfile changed. An npm or Bun alias is listed under the name in `package.json`, not the package it installs. The advisory id is a link when the audit has an `https` URL. Audits are the same locked Composer and JavaScript audits as `detect`, read for the base commit and the working tree. A package name is never an advisory id.

If nothing in that table changed, the command exits `1` and prints `No dependency changes to summarize.` on stderr. Stdout stays empty.

## Config

A repository with one root application can omit `maintainer.json`. That means `paths` is `["."]`. Composer is included when `composer.json` exists in the path. JavaScript is included when `package.json` exists there. There is no name and no managers list.

```json
{
    "paths": [".", "mobile"],
    "checks": ["composer check"]
}
```

An application in a subdirectory is one path, for example `{ "paths": ["server/web"] }`. `maintainer.schema.json` is the schema. The command rejects unknown keys, a repeated path, an absolute path, `..`, and a symlink that leaves the repository. A `sides` key is rejected and the error tells you to use `paths`.

Everything else is detected:

- The JavaScript manager comes from `packageManager` or `devEngines.packageManager` (`npm` or `bun`). A declared manager must have its own lockfile in that path (`package-lock.json` for npm, `bun.lock` for Bun) and must not have the other manager's lockfile. When both fields are absent, `package-lock.json` means npm and `bun.lock` means Bun. None, or both lockfiles, is an error. The command does not add `packageManager`.
- Vite+ is used when `vite-plus` is a direct dependency. The command is that path's `node_modules/.bin/vp`. When `node_modules` is absent, the command first runs a frozen install that does not change the lockfile: `npm ci --ignore-scripts`, or `bun install --frozen-lockfile --ignore-scripts`. Its output goes to stderr. A failed install stops the command. If `node_modules` is present but `vp` is missing or not executable, the command fails and does not install again. A global `vp` is never used.
- Without Vite+, the declared manager is called directly. An Expo app on plain npm is the usual case.
- Release age comes from the policy file that is already there. A missing policy is left missing.

## Release age

The package never silences an advisory and never runs `audit fix`.

- Composer: when `vet.json` already has `minimum-release-age`, a security update adds that one package to `minimum-release-age-exclude`.
- Bun: when `bunfig.toml` already has `[install]` and `minimumReleaseAge`, a security update adds that one package to `install.minimumReleaseAgeExcludes`. The value is seconds. Seven days is `604800`.
- npm: the security install passes `--min-release-age=0` for that one package only (`vp add -- --min-release-age=0` when Vite+ delegates to npm). `.npmrc` is not changed, and `packageManager` is not changed. npm 11.12.0 ignores `min-release-age-exclude` and still returns `ETARGET` for a release inside the seven-day cutoff. npm 11.17.0 honours the exclude, but the command does not use it and does not upgrade npm.

Each Composer or Bun exception is also recorded in `maintainer-exceptions.json` with the date it was added. A later `detect` adds `stale_exceptions` after the age window has passed and the package is still excluded. Remove the exclusion then. Detect does not delete it for you. The npm flag is not written to disk, so there is nothing to remove later.

An Expo SDK major is one candidate, `package` `expo`, with `"align": "expo-install-fix"`. Package mode runs `npx expo install expo@x.y.z` and then `npx expo install --fix`. These packages are coupled and are not separate major bumps: `expo`, `react`, `react-dom`, `react-native`, `expo-*`, `@expo/*`, `react-native-*`, `@react-native/*`, and `@react-native-community/*`. A security advisory on one of them is still that package's own update.

## JSON contract

`detect` prints one line:

```json
{
    "schema": 1,
    "candidates": [
        {
            "path": ".",
            "manager": "js",
            "package": "shadcn",
            "reasons": ["security"],
            "advisories": [
                {
                    "id": "1243066",
                    "url": "https://github.com/advisories/GHSA-mrhx-c7xc-m3w5",
                    "title": "Zod can exhaust memory while validating oversized constrained collections",
                    "affected": "<4.6.6",
                    "severity": "high"
                }
            ]
        }
    ]
}
```

`reasons` contains `major`, `security`, or both. `current` and `latest` are present for a major bump. `align` is present only for an Expo SDK major. `stale_exceptions` is present only when a recorded exception is due for removal.

Composer majors come from `composer outdated --locked --major-only`. Composer and npm audits read the lockfile, so a fresh checkout does not need `vendor`. For a `0.x` release, a newer minor counts as a major.

npm `outdated --json` omits `current` when `node_modules` is absent. Detect reads that version from `package-lock.json` at `packages["node_modules/<name>"].version`, or from `bun.lock` when the manager is Bun. It does not install packages merely to discover the current version. A direct package that has no current version in either place is an error. Rows that are not direct dependencies are still skipped.

npm advisories keep the real `via` id, URL, and severity on the controlling direct dependency. A package name is never used as an id.

Bun `vp outdated --format json` prints a table. The trailing `*` is a release-age marker, not part of the version. Rows that are not direct dependencies are skipped, including the Vite alias target `@voidzero-dev/vite-plus-core`. Bun audit ids come from `vp pm audit --json`. An indirect advisory is attached by walking `bun.lock`, including peers, and the walk stops at another direct dependency. A direct package keeps its own advisories.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | The command finished. |
| 1 | The selected package still has a security advisory, or `summary` found nothing to report. |
| child | `routine` or `package` returns the failing command's code. |
| 64 | The arguments or the target are invalid. |
| 70 | A tool result, lockfile, or config file is unusable. |
| 127 | A required binary is missing. |

## Tests

```bash
vendor/bin/pest --parallel --processes=4
```

The tests use the real parsing paths. They stub only the external binaries.
