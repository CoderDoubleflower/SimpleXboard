#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

# Install exactly the backend and panel dependencies locked in this checkout.
# No remote code checkout, dependency upgrade, plugin or theme installation.
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm --prefix panel ci
npm --prefix panel run build
php artisan xboard:install
