#!/usr/bin/env bash

# Safe Analysis Script for Monolog GDPR Filter
# This script runs static analysis tools safely with dry-runs and confirmations

set -e # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
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

# Function to ask for confirmation
confirm() {
    read -r -p "$(echo -e "${YELLOW}$1 [y/N]:${NC} ")" response
    case "$response" in
    [yY][eE][sS] | [yY])
        return 0
        ;;
    *)
        return 1
        ;;
    esac
}

# Function to check tool configuration status
check_tool_status() {
    print_status "Checking static analysis tool configurations..."

    # Check Psalm
    if vendor/bin/psalm --version >/dev/null 2>&1; then
        print_success "Psalm: Available and configured"
    else
        print_error "Psalm: Not available or misconfigured"
    fi

    # Check PHPStan
    if vendor/bin/phpstan --version >/dev/null 2>&1; then
        print_success "PHPStan: Available and configured"
    else
        print_error "PHPStan: Not available or misconfigured"
    fi

    # Check Rector
    if vendor/bin/rector --version >/dev/null 2>&1; then
        print_success "Rector: Available and configured"
    else
        print_error "Rector: Not available or misconfigured"
    fi

    # Check PHPUnit
    if vendor/bin/phpunit --version >/dev/null 2>&1; then
        print_success "PHPUnit: Available"
    else
        print_error "PHPUnit: Not available"
    fi

    # Check PHPCS
    if vendor/bin/phpcs --version >/dev/null 2>&1; then
        print_success "PHPCS: Available"
    else
        print_warning "PHPCS: Not available"
    fi
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to backup current state
create_backup() {
    print_status "Creating backup of current state..."
    local backup_dir="backups/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backup_dir"

    # Copy source files
    cp -r src/ "$backup_dir/"
    cp -r tests/ "$backup_dir/" 2>/dev/null || true
    cp -r config/ "$backup_dir/" 2>/dev/null || true
    cp -r examples/ "$backup_dir/" 2>/dev/null || true

    echo "$backup_dir" >.last_backup
    print_success "Backup created in $backup_dir"
}

# Function to restore from backup
restore_backup() {
    if [[ -f .last_backup ]]; then
        local backup_dir=$(cat .last_backup)
        if [[ -d "$backup_dir" ]]; then
            print_status "Restoring from backup: $backup_dir"
            cp -r "$backup_dir"/* ./
            print_success "Restored from backup"
        else
            print_error "Backup directory not found: $backup_dir"
        fi
    else
        print_error "No backup found"
    fi
}

# Function to run Rector dry-run
run_rector_dry() {
    print_status "Running Rector dry-run..."
    if [[ -f vendor/bin/rector ]]; then
        vendor/bin/rector process --dry-run
        return $?
    else
        print_error "Rector not found. Please install: composer require --dev rector/rector"
        return 1
    fi
}

# Function to run Rector with changes
run_rector_apply() {
    print_status "Running Rector with changes..."
    vendor/bin/rector process
}

# Function to run Psalm
run_psalm() {
    print_status "Running Psalm analysis..."
    if [[ -f vendor/bin/psalm ]]; then
        vendor/bin/psalm --no-cache --show-info=false
        return $?
    else
        print_error "Psalm not found. Please install: composer require --dev vimeo/psalm"
        return 1
    fi
}

# Function to run PHPStan
run_phpstan() {
    print_status "Running PHPStan analysis..."
    if [[ -f vendor/bin/phpstan ]]; then
        vendor/bin/phpstan analyse --memory-limit=256M
        return $?
    else
        print_error "PHPStan not found. Please install: composer require --dev phpstan/phpstan"
        return 1
    fi
}

# Function to run all tests
run_tests() {
    print_status "Running test suite..."
    if [[ -f vendor/bin/phpunit ]]; then
        vendor/bin/phpunit --testdox
        return $?
    else
        print_error "PHPUnit not found"
        return 1
    fi
}

# Function to run code style check
run_code_style() {
    print_status "Running code style checks..."
    if [[ -f vendor/bin/phpcs ]]; then
        vendor/bin/phpcs --standard=phpcs.xml --report=summary
        return $?
    else
        print_warning "PHPCS not found, skipping code style check"
        return 0
    fi
}

# Main menu
show_menu() {
    echo ""
    echo "=== Safe Static Analysis Tool ==="
    echo "1. Check tool status"
    echo "2. Run Rector dry-run (show changes only)"
    echo "3. Run Psalm analysis"
    echo "4. Run PHPStan analysis"
    echo "5. Run all analysis tools (dry-run)"
    echo "6. Apply Rector changes (with backup)"
    echo "7. Run full analysis + apply changes"
    echo "8. Restore from last backup"
    echo "9. Run tests only"
    echo "10. Run code style check"
    echo "0. Exit"
    echo ""
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
    --dry-run | --dry)
        DRY_RUN=1
        shift
        ;;
    --apply)
        APPLY_CHANGES=1
        shift
        ;;
    --all)
        RUN_ALL=1
        shift
        ;;
    --restore)
        restore_backup
        exit 0
        ;;
    --help | -h)
        echo "Usage: $0 [options]"
        echo "Options:"
        echo "  --dry-run    Run analysis without making changes"
        echo "  --apply      Apply changes (will create backup first)"
        echo "  --all        Run all analysis tools"
        echo "  --restore    Restore from last backup"
        echo "  --help       Show this help"
        exit 0
        ;;
    *)
        print_error "Unknown option: $1"
        exit 1
        ;;
    esac
done

# Check if we're in the right directory
if [[ ! -f composer.json ]]; then
    print_error "Please run this script from the project root directory"
    exit 1
fi

# Install dependencies if needed
if [[ ! -d vendor ]]; then
    print_status "Installing dependencies..."
    composer install --dev
fi

# Handle command line mode
if [[ -n "$RUN_ALL" ]]; then
    print_status "Running all analysis tools..."

    print_status "=== Rector Dry-Run ==="
    run_rector_dry || print_warning "Rector dry-run had issues"

    print_status "=== Psalm Analysis ==="
    run_psalm || print_warning "Psalm found issues"

    print_status "=== PHPStan Analysis ==="
    run_phpstan || print_warning "PHPStan found issues"

    print_status "=== Code Style Check ==="
    run_code_style || print_warning "Code style issues found"

    if [[ -n "$APPLY_CHANGES" ]]; then
        if confirm "Apply Rector changes?"; then
            create_backup
            run_rector_apply
            print_status "Running tests after changes..."
            if ! run_tests; then
                print_error "Tests failed after applying changes!"
                if confirm "Restore from backup?"; then
                    restore_backup
                fi
            fi
        fi
    fi
    exit 0
fi

# Interactive mode
while true; do
    show_menu
    read -r -p "Choose an option [0-10]: " choice

    case $choice in
    1)
        check_tool_status
        ;;
    2)
        run_rector_dry
        ;;
    3)
        run_psalm
        ;;
    4)
        run_phpstan
        ;;
    5)
        print_status "=== Running All Analysis Tools (Dry-Run) ==="
        run_rector_dry || print_warning "Rector dry-run had issues"
        echo ""
        run_psalm || print_warning "Psalm found issues"
        echo ""
        run_phpstan || print_warning "PHPStan found issues"
        echo ""
        run_code_style || print_warning "Code style issues found"
        ;;
    6)
        if run_rector_dry; then
            if confirm "Apply these Rector changes?"; then
                create_backup
                run_rector_apply
                print_status "Changes applied. Running tests..."
                if ! run_tests; then
                    print_error "Tests failed after applying changes!"
                    if confirm "Restore from backup?"; then
                        restore_backup
                    fi
                else
                    print_success "All tests pass after changes!"
                fi
            fi
        else
            print_warning "Rector dry-run showed issues. Review before applying."
        fi
        ;;
    7)
        print_status "=== Full Analysis & Apply Changes ==="

        # First, run all analysis
        print_status "Step 1: Running analysis tools..."
        run_psalm || print_warning "Psalm found issues"
        run_phpstan || print_warning "PHPStan found issues"
        run_code_style || print_warning "Code style issues found"

        # Show Rector changes
        print_status "Step 2: Showing Rector changes..."
        if run_rector_dry; then
            if confirm "Apply Rector changes and run tests?"; then
                create_backup
                run_rector_apply

                print_status "Step 3: Running tests after changes..."
                if ! run_tests; then
                    print_error "Tests failed after applying changes!"
                    if confirm "Restore from backup?"; then
                        restore_backup
                    fi
                else
                    print_success "All tests pass! Changes applied successfully."
                fi
            fi
        fi
        ;;
    8)
        restore_backup
        ;;
    9)
        run_tests
        ;;
    10)
        run_code_style
        ;;
    0)
        print_status "Goodbye!"
        exit 0
        ;;
    *)
        print_error "Invalid option. Please choose 0-10."
        ;;
    esac

    echo ""
    read -r -p "Press Enter to continue..."
done
