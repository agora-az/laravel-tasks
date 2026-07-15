#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Remove stale manifests that can reference dev-only providers (e.g. tinker)
# and break artisan in production environments deployed with --no-dev.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

php artisan "$@"
