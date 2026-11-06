#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

# Select and review a SimpleXboard release and back up the database before
# invoking this script. It never fetches, resets or replaces the checkout.
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm --prefix panel ci
npm --prefix panel run build
php artisan xboard:update --no-interaction
