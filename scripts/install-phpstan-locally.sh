#!/usr/bin/env bash
#
# Make `composer analyse` work in a sandbox where `composer install` cannot
# authenticate to GitHub.
#
# Larastan itself installs fine and is already in vendor/; the only missing
# piece is the phpstan/phpstan binary, whose dist is a GitHub zipball that
# an unauthenticated composer cannot fetch. The released phar can be
# downloaded directly and is about 28 MB.
#
# This exists because AGENTS.md said for months that static analysis was
# CI-only and could not run locally — a claim that came from a *source*
# install reaching 5.7 GB, and that cost three CI runs in one afternoon
# before anybody tried the phar.
#
# Nothing here is committed: vendor/ is gitignored. Run it once per sandbox.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

VERSION="$(php -r '
    $lock = json_decode(file_get_contents($argv[1]), true);
    foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
        if ($package["name"] === "phpstan/phpstan") {
            echo ltrim($package["version"], "v");
            exit;
        }
    }
    fwrite(STDERR, "phpstan/phpstan is not in composer.lock\n");
    exit(1);
' "$ROOT/composer.lock")"

TARGET="$ROOT/vendor/phpstan/phpstan"

if [ -f "$TARGET/phpstan.phar" ]; then
    echo "phpstan ${VERSION} is already installed at ${TARGET}."
    exit 0
fi

echo "Fetching phpstan ${VERSION} (the version composer.lock pins)…"

mkdir -p "$TARGET"
curl -sSLf -o "$TARGET/phpstan.phar" \
    "https://github.com/phpstan/phpstan/releases/download/${VERSION}/phpstan.phar"

# The shim composer would normally generate, so `vendor/bin/phpstan` and
# therefore `composer analyse` work unchanged.
cat > "$TARGET/phpstan" <<'SHIM'
#!/usr/bin/env php
<?php
require __DIR__.'/phpstan.phar';
SHIM

chmod +x "$TARGET/phpstan"
mkdir -p "$ROOT/vendor/bin"
ln -sf ../phpstan/phpstan/phpstan "$ROOT/vendor/bin/phpstan"

echo "Done. Run: composer analyse"
