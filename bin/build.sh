#!/bin/bash
set -e

echo "🔨 Building ERNI PMS for Render..."

# Ensure directories exist
echo "Creating required directories..."
mkdir -p git
mkdir -p git-workspaces
mkdir -p uploads
mkdir -p logs

# Set permissions
chmod -R 755 git git-workspaces uploads logs

# Install PHP dependencies (if composer.json exists)
if [ -f "composer.json" ]; then
    echo "Installing PHP dependencies..."
    composer install --no-dev --optimize-autoloader
fi

# Create logs directory
touch logs/application.log 2>/dev/null || true
touch logs/errors.log 2>/dev/null || true

echo "✅ Build completed successfully"
