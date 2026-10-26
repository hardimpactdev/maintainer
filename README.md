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
vendor/bin/maintainer dependencies routine [side]
vendor/bin/maintainer dependencies detect [side]
vendor/bin/maintainer dependencies package [side] <composer|js> <package> <x.y.z> [--advisory ID ...]
vendor/bin/maintainer dependencies package [side] <composer|js> <package> <x.y.z> <major|security> [advisory-id ...]
vendor/bin/maintainer dependencies check
```

`routine` updates within the ranges already declared. For each selected side it runs `composer update --no-interaction` when that side has Composer, then the JavaScript update. It stops at the first failed command and returns that exit code.

`detect` prints one JSON object on stdout. A frozen install may create `node_modules`, and it does not change the lockfile. Bootstrap output goes to stderr. `package` updates one direct dependency to the exact version you pass.

The second `package` form is what Swamp sends today:

```text
package composer livewire/livewire 4.9.9 security PKSA-xhv3-nr94-h8db
```

`--advisory` is the same thing. Repeat the flag for more than one advisory. Security mode requires at least one advisory id. Major mode accepts none.

Omit `side` when only one side has that manager. If several sides have it, pass the side name. `routine` and `detect` without a side cover every side.

`check` runs the commands in `maintainer.json` `checks`. Routine, detect, and package never run those checks. Orbit's handoff check does that work.

## Config

A repository with one root application can omit `maintainer.json`. The implicit side is named `root`. Composer is included when `composer.json` exists. JavaScript is included when `package.json` exists.

```json
{
    "sides": [
        { "name": "web", "dir": ".", "managers": ["composer", "js"] },
        { "name": "mobile", "dir": "mobile", "managers": ["js"] }
    ],
    "checks": ["composer check"]
}
```

`maintainer.schema.json` is the schema. The command rejects unknown keys, a side named `composer` or `js`, and a directory that escapes the repository.

Everything else is detected:

- The JavaScript manager comes from `packageManager` or `devEngines.packageManager` (`npm` or `bun`). A declared manager must have its own lockfile in that side (`package-lock.json` for npm, `bun.lock` for Bun) and must not have the other manager's lockfile. When both fields are absent, `package-lock.json` means npm and `bun.lock` means Bun. None, or both lockfiles, is an error. The command does not add `packageManager`.
- Vite+ is used when `vite-plus` is a direct dependency. The command is the side's `node_modules/.bin/vp`. When `node_modules` is absent, the command first runs a frozen install that does not change the lockfile: `npm ci --ignore-scripts`, or `bun install --frozen-lockfile --ignore-scripts`. Its output goes to stderr. A failed install stops the command. If `node_modules` is present but `vp` is missing or not executable, the command fails and does not install again. A global `vp` is never used.
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
            "side": "root",
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
| 1 | The selected package still has a security advisory. |
| child | `routine` or `package` returns the failing command's code. |
| 64 | The arguments or the target are invalid. |
| 70 | A tool result, lockfile, or config file is unusable. |
| 127 | A required binary is missing. |

## Tests

```bash
vendor/bin/pest --parallel --processes=4
```

The tests use the real parsing paths. They stub only the external binaries.
