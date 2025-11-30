#!/bin/bash
set -e

echo "Starting Laravel application setup..."

# Install/update composer dependencies if needed
if [ ! -f "vendor/autoload.php" ]; then
    echo "Installing composer dependencies..."
    composer install --no-progress --no-interaction --optimize-autoloader
fi

# For Railway/containerized deployments, environment variables are injected
# No need to copy .env.example
if [ ! -f ".env" ]; then
    echo "No .env file found. Using environment variables from container."
fi

# Wait for database to be ready (with timeout)
echo "Waiting for database to be ready..."
MAX_TRIES=30
COUNT=0
until php artisan db:show 2>/dev/null || [ $COUNT -eq $MAX_TRIES ]; do
    echo "Database not ready, waiting... ($COUNT/$MAX_TRIES)"
    sleep 2
    COUNT=$((COUNT + 1))
done

if [ $COUNT -eq $MAX_TRIES ]; then
    echo "Warning: Database may not be ready, proceeding anyway..."
fi

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Clear and optimize
echo "Optimizing application..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Optional: Cache for better performance (only if not using .env file)
# php artisan config:cache
# php artisan route:cache
# php artisan view:cache

echo "Starting Laravel development server on port 8000..."
php artisan serve --host=0.0.0.0 --port=8000