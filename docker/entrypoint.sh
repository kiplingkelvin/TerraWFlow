#!/bin/bash

if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-progress --no-interaction
fi

# Only create .env if it doesn't exist AND we are not in production (optional safety)
# For Railway, we want to rely on environment variables injected into the container.
# If .env exists, it might override system envs depending on how Laravel loads them.
# Best practice for containerized apps is to NOT have a .env file if using system envs,
# OR have it but ensure system envs take precedence.
# However, the previous code was forcing a copy from .env.example which is bad.
if [ ! -f ".env" ]; then
    echo "No .env file found. Assuming environment variables are passed via container orchestration."
fi

php artisan migrate --force
# php artisan key:generate -- This is dangerous in production! Key should be in env vars.
php artisan cache:clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear

php-fpm
