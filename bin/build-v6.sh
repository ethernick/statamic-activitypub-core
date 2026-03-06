#!/bin/bash
# Build script for Vue 3 (Statamic 6) compatibility
# This script temporarily swaps Vue 2 for Vue 3, builds, then restores Vue 2

set -e

echo "📦 Building Vue 3 (Statamic 6) bundle..."

# Backup current package.json
cp package.json package.json.backup

# Temporarily replace Vue 2 with Vue 3
echo "🔄 Swapping Vue 2 → Vue 3..."
npm install --legacy-peer-deps vue@^3.5.13

# Build Vue 3 version
echo "🏗️  Building v6..."
npx cross-env BUILD_ADDON=true VUE_VERSION=3 vite build

# Copy CSS to dist (IIFE format inlines CSS into JS, but service provider expects a standalone file)
mkdir -p dist/v6/css
cp resources/css/cp.css dist/v6/css/cp.css

# Restore Vue 2
echo "🔄 Restoring Vue 2..."
mv package.json.backup package.json
npm install --legacy-peer-deps

echo "✅ Vue 3 build complete: dist/v6/"
