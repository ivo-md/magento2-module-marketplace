#!/bin/bash
#
# IVO Marketplace Magento 2 Module - Build Script
# Creates a distributable ZIP package with version management
#
# Usage:
#   ./build.sh              # Increment patch version and build (1.0.0 -> 1.0.1)
#   ./build.sh 1.2.3        # Build and update to version 1.2.3
#   ./build.sh patch        # Increment patch version (1.0.0 -> 1.0.1)
#   ./build.sh minor        # Increment minor version (1.0.0 -> 1.1.0)
#   ./build.sh major        # Increment major version (1.0.0 -> 2.0.0)
#   ./build.sh same         # Build with current version (no increment)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$SCRIPT_DIR"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DIST_DIR="$PROJECT_ROOT/dist/magento-module"
COMPOSER_FILE="$EXT_DIR/composer.json"
MODULE_XML="$EXT_DIR/etc/module.xml"
CONFIG_HELPER="$EXT_DIR/Helper/Config.php"
DATA_HELPER="$EXT_DIR/Helper/Data.php"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Get current version from composer.json
get_current_version() {
    grep -o '"version": *"[^"]*"' "$COMPOSER_FILE" | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+'
}

# Increment version
increment_version() {
    local version=$1
    local type=$2

    IFS='.' read -r major minor patch <<< "$version"

    case $type in
        major)
            major=$((major + 1))
            minor=0
            patch=0
            ;;
        minor)
            minor=$((minor + 1))
            patch=0
            ;;
        patch)
            patch=$((patch + 1))
            ;;
    esac

    echo "$major.$minor.$patch"
}

# Update version in composer.json
update_composer_version() {
    local new_version=$1
    sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$new_version\"/" "$COMPOSER_FILE"
}

# Update version in module.xml
update_module_xml_version() {
    local new_version=$1
    sed -i "s/setup_version=\"[^\"]*\"/setup_version=\"$new_version\"/" "$MODULE_XML"
}

# Update version in Helper/Config.php and Helper/Data.php (platform version param)
update_helper_versions() {
    local new_version=$1
    for file in "$CONFIG_HELPER" "$DATA_HELPER"; do
        if [ -f "$file" ]; then
            sed -i "s/'version' => '[^']*'/'version' => '$new_version'/" "$file"
        fi
    done
}

# Main build function
build_package() {
    local version=$1
    local zip_name="ivo-marketplace-magento-module-${version}.zip"
    local temp_dir=$(mktemp -d)
    local build_dir="$temp_dir/app/code/Ivo/Marketplace"

    print_info "Building package version $version..."

    # Create dist directory if it doesn't exist
    mkdir -p "$DIST_DIR"

    # Create the module structure (app/code/Ivo/Marketplace)
    mkdir -p "$build_dir"

    # Copy all module files
    print_info "Copying module files..."

    # Copy directories
    for dir in Block Controller Helper Model Observer Ui etc i18n view; do
        if [ -d "$EXT_DIR/$dir" ]; then
            cp -r "$EXT_DIR/$dir" "$build_dir/"
        fi
    done

    # Copy root files
    for file in registration.php composer.json README.md; do
        if [ -f "$EXT_DIR/$file" ]; then
            cp "$EXT_DIR/$file" "$build_dir/"
        fi
    done

    # Remove any development/build files
    find "$build_dir" -name "*.bak" -delete 2>/dev/null || true
    find "$build_dir" -name ".DS_Store" -delete 2>/dev/null || true
    find "$build_dir" -name "*.log" -delete 2>/dev/null || true

    # Create the ZIP file
    print_info "Creating ZIP archive..."
    cd "$temp_dir"
    zip -r "$DIST_DIR/$zip_name" app -x "*.git*"

    # Cleanup
    rm -rf "$temp_dir"

    # Create/update latest symlink
    cd "$DIST_DIR"
    rm -f "ivo-marketplace-magento-module-latest.zip"
    ln -s "$zip_name" "ivo-marketplace-magento-module-latest.zip"

    print_success "Package created: $DIST_DIR/$zip_name"
    print_info "Latest symlink updated: ivo-marketplace-magento-module-latest.zip"

    # Show package info
    echo ""
    echo "Package Details:"
    echo "  - Version: $version"
    echo "  - File: $zip_name"
    echo "  - Size: $(du -h "$DIST_DIR/$zip_name" | cut -f1)"
    echo "  - Path: $DIST_DIR/$zip_name"
}

# Create version history file
update_version_history() {
    local version=$1
    local history_file="$DIST_DIR/VERSIONS.md"
    local date_str=$(date '+%Y-%m-%d %H:%M:%S')

    if [ ! -f "$history_file" ]; then
        echo "# IVO Marketplace Magento Module - Version History" > "$history_file"
        echo "" >> "$history_file"
        echo "| Version | Date | File |" >> "$history_file"
        echo "|---------|------|------|" >> "$history_file"
    fi

    sed -i "/^|---------|------|------|$/a | $version | $date_str | ivo-marketplace-magento-module-${version}.zip |" "$history_file"

    print_info "Version history updated: $history_file"
}

# Show usage
show_usage() {
    echo "IVO Marketplace Magento 2 Module - Build Script"
    echo ""
    echo "Usage:"
    echo "  $0              Increment patch version and build (default)"
    echo "  $0 <version>    Build with specific version (e.g., 1.2.3)"
    echo "  $0 patch        Increment patch version and build"
    echo "  $0 minor        Increment minor version and build"
    echo "  $0 major        Increment major version and build"
    echo "  $0 same         Build with current version (no increment)"
    echo ""
}

# Main execution
main() {
    print_info "IVO Marketplace Module Builder"
    echo ""

    # Check required files exist
    if [ ! -f "$COMPOSER_FILE" ]; then
        print_error "composer.json not found at $COMPOSER_FILE"
        exit 1
    fi

    if [ ! -f "$MODULE_XML" ]; then
        print_error "module.xml not found at $MODULE_XML"
        exit 1
    fi

    # Get current version
    current_version=$(get_current_version)
    print_info "Current version: $current_version"

    # Determine new version
    if [ -z "$1" ]; then
        new_version=$(increment_version "$current_version" "patch")
    elif [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        new_version=$1
    elif [ "$1" = "patch" ] || [ "$1" = "minor" ] || [ "$1" = "major" ]; then
        new_version=$(increment_version "$current_version" "$1")
    elif [ "$1" = "same" ]; then
        new_version=$current_version
    elif [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
        show_usage
        exit 0
    else
        print_error "Invalid argument: $1"
        show_usage
        exit 1
    fi

    # Update version if changed
    if [ "$new_version" != "$current_version" ]; then
        print_info "Updating version: $current_version -> $new_version"
        update_composer_version "$new_version"
        update_module_xml_version "$new_version"
        update_helper_versions "$new_version"
    fi

    # Build the package
    build_package "$new_version"

    # Update version history
    update_version_history "$new_version"

    echo ""
    print_success "Build completed successfully!"
}

main "$@"
