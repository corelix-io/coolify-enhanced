#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Corelix Platform - Setup Script
# =============================================================================
# Interactive menu-driven setup for Coolify and the Enhanced addon.
# Can install Coolify from scratch on a vanilla server, install/uninstall the
# addon, and check installation status.
#
# Usage (interactive menu):
#   sudo bash install.sh
#
# Usage (CLI arguments):
#   sudo bash install.sh --install-coolify          # Install Coolify only
#   sudo bash install.sh --install-addon             # Install enhanced addon
#   sudo bash install.sh --install-addon --local     # Install addon (local build)
#   sudo bash install.sh --uninstall                 # Uninstall enhanced addon
#   sudo bash install.sh --status                    # Check installation status
#   sudo bash install.sh --install-coolify --install-addon --unattended
#
# Options:
#   --install-coolify   Install Coolify from official repository
#   --install-addon     Install the enhanced addon
#   --uninstall         Uninstall the enhanced addon
#   --status            Check installation status
#   --local             Build addon image locally instead of pulling from GHCR
#   --unattended        Non-interactive mode (accept all defaults)
#   --help, -h          Show this help message
# =============================================================================

# Preserve original CLI args for forwarding to uninstall.sh (function $@ is empty).
SCRIPT_ARGS=("$@")

# --- Constants ---

COOLIFY_BASE="${COOLIFY_BASE:-/data/coolify}"
COOLIFY_SOURCE="${COOLIFY_SOURCE:-${COOLIFY_BASE}/source}"
COMPOSE_FILE="${COOLIFY_SOURCE}/docker-compose.yml"
PROD_COMPOSE="${COOLIFY_SOURCE}/docker-compose.prod.yml"
CUSTOM_COMPOSE="${COOLIFY_SOURCE}/docker-compose.custom.yml"
UPGRADE_SCRIPT="${COOLIFY_SOURCE}/upgrade.sh"
ENV_FILE="${COOLIFY_SOURCE}/.env"
UPGRADE_STATUS_FILE="${COOLIFY_SOURCE}/.upgrade-status"
ADDON_IMAGE_VERSION="1.0.0"
GHCR_IMAGE="ghcr.io/corelix-io/coolify-enhanced:${ADDON_IMAGE_VERSION}"
LOCAL_IMAGE_NAME="corelix-platform:local"
BACKUP_SUFFIX=".backup.$(date +%Y%m%d_%H%M%S)"
COOLIFY_INSTALL_URL="https://cdn.coollabs.io/coolify/install.sh"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- Colors ---

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# --- Parse arguments ---

METHOD="ghcr"
UNATTENDED=false
ARG_INSTALL_COOLIFY=false
ARG_INSTALL_ADDON=false
ARG_UNINSTALL=false
ARG_STATUS=false

for arg in "$@"; do
    case "$arg" in
        --install-coolify)  ARG_INSTALL_COOLIFY=true ;;
        --install-addon)    ARG_INSTALL_ADDON=true ;;
        --uninstall)        ARG_UNINSTALL=true ;;
        --status)           ARG_STATUS=true ;;
        --local)            METHOD="local" ;;
        --unattended)       UNATTENDED=true ;;
        --help|-h)
            echo "Usage: sudo bash install.sh [OPTIONS]"
            echo ""
            echo "When run without arguments, an interactive menu is displayed."
            echo ""
            echo "Options:"
            echo "  --install-coolify   Install Coolify from official repository"
            echo "  --install-addon     Install the enhanced addon"
            echo "  --uninstall         Uninstall the enhanced addon"
            echo "  --status            Check installation status"
            echo "  --local             Build addon image locally instead of pulling from GHCR"
            echo "  --unattended        Non-interactive mode (accept all defaults)"
            echo "  --help, -h          Show this help message"
            echo ""
            echo "Examples:"
            echo "  sudo bash install.sh                                   # Interactive menu"
            echo "  sudo bash install.sh --install-coolify                 # Install Coolify only"
            echo "  sudo bash install.sh --install-addon                   # Install addon (GHCR)"
            echo "  sudo bash install.sh --install-addon --local           # Install addon (local build)"
            echo "  sudo bash install.sh --install-coolify --install-addon # Full setup"
            echo "  sudo bash install.sh --status                          # Check status"
            echo "  sudo bash install.sh --uninstall                       # Uninstall addon"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg"
            echo "Run 'bash install.sh --help' for usage."
            exit 1
            ;;
    esac
done

HAS_CLI_ACTION=false
if [ "$ARG_INSTALL_COOLIFY" = true ] || [ "$ARG_INSTALL_ADDON" = true ] || \
   [ "$ARG_UNINSTALL" = true ] || [ "$ARG_STATUS" = true ]; then
    HAS_CLI_ACTION=true
fi

# --- Helper functions ---

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; }
step()    { echo -e "\n${CYAN}==>${NC} ${BOLD}$*${NC}"; }

confirm() {
    if [ "$UNATTENDED" = true ]; then
        return 0
    fi
    local prompt="${1:-Continue?} [y/N] "
    read -r -p "$prompt" response
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

press_enter() {
    if [ "$UNATTENDED" = true ]; then
        return 0
    fi
    echo ""
    read -r -p "Press Enter to return to menu..." _
}

separator() {
    echo -e "${DIM}----------------------------------------------${NC}"
}

read_coolify_version_pin() {
    grep -m1 '^ARG COOLIFY_VERSION=' "${SCRIPT_DIR}/docker/Dockerfile" 2>/dev/null | cut -d= -f2 || echo "4.1.2"
}

ensure_trailing_newline() {
    local file="$1"
    if [ ! -s "$file" ]; then
        return 0
    fi
    local last_byte
    last_byte=$(tail -c1 "$file" | od -An -tx1 | tr -d ' \n')
    if [ "$last_byte" != "0a" ]; then
        echo >> "$file"
    fi
}

set_corelix_platform_env() {
    local value="${1:-true}"
    if [ ! -f "$ENV_FILE" ]; then
        return 1
    fi
    if grep -q '^CORELIX_PLATFORM=' "$ENV_FILE"; then
        sed -i "s/^CORELIX_PLATFORM=.*/CORELIX_PLATFORM=${value}/" "$ENV_FILE"
    else
        ensure_trailing_newline "$ENV_FILE"
        echo "CORELIX_PLATFORM=${value}" >> "$ENV_FILE"
    fi
}

compose_stack_up() {
    local include_custom="${1:-auto}"
    local compose_args=(--env-file "$ENV_FILE" -f "$COMPOSE_FILE" -f "$PROD_COMPOSE")

    if [ "$include_custom" = "yes" ] || { [ "$include_custom" = "auto" ] && [ -f "$CUSTOM_COMPOSE" ]; }; then
        compose_args+=(-f "$CUSTOM_COMPOSE")
    fi

    if [ -f "${COOLIFY_SOURCE}/docker-compose.postgres-upgrade.yml" ]; then
        compose_args+=(-f "${COOLIFY_SOURCE}/docker-compose.postgres-upgrade.yml")
    fi

    (
        cd "$COOLIFY_SOURCE"
        docker compose "${compose_args[@]}" up -d --remove-orphans
    )
}

restart_coolify_stack() {
    local mode="${1:-upgrade}"

    if [ "$mode" = "compose" ]; then
        if ! compose_stack_up auto; then
            error "Failed to restart Coolify stack via docker compose."
            return 1
        fi
        return 0
    fi

    if [ -f "$UPGRADE_SCRIPT" ]; then
        warn "upgrade.sh upgrades Coolify to the latest release and pulls all images."
        if ! bash "$UPGRADE_SCRIPT"; then
            warn "upgrade.sh exited with non-zero status. Falling back to docker compose..."
            if ! compose_stack_up auto; then
                error "Failed to restart Coolify stack."
                return 1
            fi
        fi
    else
        if ! compose_stack_up auto; then
            error "Failed to restart Coolify stack."
            return 1
        fi
    fi
}

verify_package_discovered() {
    docker exec coolify grep -q 'corelix-io/platform' bootstrap/cache/packages.php 2>/dev/null
}

# --- Prerequisite checks ---

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        error "This script must be run as root (or with sudo)."
        exit 1
    fi
}

check_curl() {
    if ! command -v curl &>/dev/null; then
        return 1
    fi
    return 0
}

check_docker() {
    if ! command -v docker &>/dev/null; then
        return 1
    fi
    return 0
}

check_docker_compose() {
    if ! docker compose version &>/dev/null 2>&1; then
        return 1
    fi
    return 0
}

check_coolify_installed() {
    if [ -f "$COMPOSE_FILE" ]; then
        return 0
    fi
    return 1
}

check_coolify_running() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'coolify'
}

check_addon_installed() {
    if [ -f "$CUSTOM_COMPOSE" ] && grep -q "corelix-platform\|CORELIX_PLATFORM" "$CUSTOM_COMPOSE" 2>/dev/null; then
        return 0
    fi
    return 1
}

# --- Wait for Coolify to be ready ---

wait_for_coolify() {
    local expected_image="${1:-}"
    step "Waiting for Coolify to be ready..."
    local max_wait=120
    local waited=0

    if [ -f "$UPGRADE_STATUS_FILE" ]; then
        while [ $waited -lt $max_wait ] && [ -f "$UPGRADE_STATUS_FILE" ]; do
            sleep 2
            waited=$((waited + 2))
            echo -n "."
        done
        echo ""
        waited=0
    fi

    while [ $waited -lt $max_wait ]; do
        if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'coolify'; then
            local health
            health=$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "")
            local is_up=false
            if [ "$health" = "healthy" ]; then
                is_up=true
            elif docker ps --format '{{.Names}}\t{{.Status}}' --filter name=^coolify$ 2>/dev/null | grep -q "Up"; then
                is_up=true
            fi

            if [ "$is_up" = true ]; then
                if [ -n "$expected_image" ]; then
                    local current_image
                    current_image=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "")
                    if [ "$current_image" = "$expected_image" ]; then
                        break
                    fi
                else
                    break
                fi
            fi
        fi
        sleep 2
        waited=$((waited + 2))
        echo -n "."
    done
    echo ""

    if [ $waited -ge $max_wait ]; then
        warn "Timed out waiting for Coolify. It may still be starting up."
        warn "Check with: docker logs coolify --tail 50"
        return 1
    fi

    success "Coolify is running"
    return 0
}

# =============================================================================
# ACTION: Install Coolify
# =============================================================================

action_install_coolify() {
    echo ""
    echo -e "${BOLD}Install Coolify from Official Repository${NC}"
    separator

    # Check if already installed
    if check_coolify_installed; then
        warn "Coolify appears to already be installed at ${COOLIFY_SOURCE}"
        if check_coolify_running; then
            info "Coolify container is currently running."
        fi
        echo ""
        if ! confirm "Continue with Coolify installation anyway? (this will re-run the official installer)"; then
            info "Skipped Coolify installation."
            return 0
        fi
    fi

    # Check for curl
    if ! check_curl; then
        error "curl is required to download the Coolify installer."
        info "Install curl first: apt-get install -y curl (Debian/Ubuntu) or yum install -y curl (RHEL/CentOS)"
        return 1
    fi

    echo ""
    info "This will download and run the official Coolify install script from:"
    info "  ${COOLIFY_INSTALL_URL}"
    echo ""
    info "The official installer will:"
    info "  - Install required system packages"
    info "  - Install/configure Docker if needed"
    info "  - Set up Coolify at /data/coolify"
    info "  - Start the Coolify stack"
    echo ""

    if ! confirm "Proceed with Coolify installation?"; then
        info "Skipped Coolify installation."
        return 0
    fi

    step "Downloading official Coolify install script..."

    local tmp_installer
    tmp_installer="$(mktemp /tmp/coolify-install.XXXXXX.sh)"
    if ! curl -fsSL "$COOLIFY_INSTALL_URL" -o "$tmp_installer"; then
        error "Failed to download Coolify install script."
        error "Check your internet connection and try again."
        rm -f "$tmp_installer"
        return 1
    fi
    chmod +x "$tmp_installer"
    success "Downloaded installer"

    step "Running official Coolify installer..."
    echo ""
    echo -e "${DIM}--- Coolify installer output begins ---${NC}"
    echo ""

    # Run the official installer; don't let a failure kill our script
    if bash "$tmp_installer"; then
        echo ""
        echo -e "${DIM}--- Coolify installer output ends ---${NC}"
        success "Coolify installation completed successfully!"
    else
        echo ""
        echo -e "${DIM}--- Coolify installer output ends ---${NC}"
        warn "Coolify installer exited with a non-zero status."
        warn "Check the output above for errors."
    fi

    rm -f "$tmp_installer"

    # Verify the result
    echo ""
    if check_coolify_installed; then
        success "Coolify files found at ${COOLIFY_SOURCE}"
    else
        warn "Coolify files not found at ${COOLIFY_SOURCE} after installation."
        warn "The installer may have used a different path, or the installation may have failed."
    fi

    if check_docker && check_coolify_running; then
        success "Coolify container is running"
    else
        info "Coolify container may still be starting up."
    fi

    return 0
}

# =============================================================================
# ACTION: Install Addon
# =============================================================================

action_install_addon() {
    local method="$METHOD"

    echo ""
    echo -e "${BOLD}Install Enhanced Addon${NC}"
    separator

    # --- Prerequisites ---
    step "Checking prerequisites..."

    if ! check_docker; then
        error "Docker is not installed. Install Coolify first (option 1) or install Docker manually."
        return 1
    fi
    success "Docker is available"

    if ! check_docker_compose; then
        error "Docker Compose (v2) is not available."
        return 1
    fi
    success "Docker Compose is available"

    if ! check_coolify_installed; then
        error "Coolify installation not found at ${COOLIFY_SOURCE}"
        echo ""
        echo "  Expected docker-compose.yml at: ${COMPOSE_FILE}"
        echo ""
        echo "  Install Coolify first using option 1 in the menu, or run:"
        echo "    curl -fsSL ${COOLIFY_INSTALL_URL} | bash"
        echo ""
        echo "  If Coolify is installed in a different location, set COOLIFY_SOURCE:"
        echo "    COOLIFY_SOURCE=/path/to/coolify/source bash install.sh --install-addon"
        return 1
    fi
    success "Coolify installation found at ${COOLIFY_SOURCE}"

    if [ ! -f "$UPGRADE_SCRIPT" ]; then
        warn "upgrade.sh not found at ${UPGRADE_SCRIPT}"
        warn "Will use 'docker compose up -d' to restart instead."
    fi

    if ! check_coolify_running; then
        warn "Coolify container does not appear to be running."
        if ! confirm "Continue anyway?"; then
            info "Aborted addon installation."
            return 0
        fi
    else
        success "Coolify container is running"
    fi

    # --- Check for existing custom compose ---
    if [ -f "$CUSTOM_COMPOSE" ]; then
        warn "A docker-compose.custom.yml already exists at ${CUSTOM_COMPOSE}"
        echo ""
        echo "Existing content:"
        cat "$CUSTOM_COMPOSE"
        echo ""
        if ! confirm "Overwrite the existing file?"; then
            info "Aborted. Please back up or remove the existing file first."
            return 0
        fi
        cp "$CUSTOM_COMPOSE" "${CUSTOM_COMPOSE}${BACKUP_SUFFIX}"
        info "Backed up existing file to docker-compose.custom.yml${BACKUP_SUFFIX}"
    fi

    # --- Choose install method ---
    if [ "$UNATTENDED" = false ] && [ "$method" = "ghcr" ]; then
        echo ""
        echo "Installation methods:"
        echo "  1) Pre-built image from GHCR (recommended, faster)"
        echo "  2) Build image locally (requires cloning the repo)"
        echo ""
        read -r -p "Choose method [1]: " choice
        case "$choice" in
            2) method="local" ;;
            *) method="ghcr" ;;
        esac
    fi

    # --- Pull or build image ---
    local image_to_use=""

    if [ "$method" = "ghcr" ]; then
        step "Pulling pre-built image from GHCR..."
        if ! docker pull "$GHCR_IMAGE"; then
            error "Failed to pull image. Check your internet connection and registry access."
            return 1
        fi
        success "Image pulled: ${GHCR_IMAGE}"
        image_to_use="$GHCR_IMAGE"

    elif [ "$method" = "local" ]; then
        step "Building image locally..."

        if [ ! -f "${SCRIPT_DIR}/docker/Dockerfile" ]; then
            error "Dockerfile not found at ${SCRIPT_DIR}/docker/Dockerfile"
            error "Make sure you're running this script from the package repository root."
            return 1
        fi

        local coolify_version
        coolify_version="$(read_coolify_version_pin)"
        if [ "$UNATTENDED" = false ]; then
            read -r -p "Coolify version to build against [${coolify_version}]: " version_input
            if [ -n "${version_input:-}" ]; then
                if [ "$version_input" = "latest" ]; then
                    warn "Building against 'latest' can mismatch overlays — use the pinned version unless testing upstream."
                fi
                coolify_version="$version_input"
            fi
        fi

        info "Building with COOLIFY_VERSION=${coolify_version} (pinned default: $(read_coolify_version_pin))..."
        if ! docker build \
            --build-arg "COOLIFY_VERSION=${coolify_version}" \
            -t "$LOCAL_IMAGE_NAME" \
            -f "${SCRIPT_DIR}/docker/Dockerfile" \
            "$SCRIPT_DIR"; then
            error "Docker build failed."
            return 1
        fi
        success "Image built: ${LOCAL_IMAGE_NAME}"
        image_to_use="$LOCAL_IMAGE_NAME"
    fi

    # --- Deploy docker-compose.custom.yml ---
    step "Deploying docker-compose.custom.yml..."

    cat > "$CUSTOM_COMPOSE" << EOF
# Corelix Platform - Auto-generated by install.sh
# Installed: $(date -u +"%Y-%m-%dT%H:%M:%SZ")

services:
  coolify:
    image: ${image_to_use}
    environment:
      - CORELIX_PLATFORM=true
EOF

    success "Created ${CUSTOM_COMPOSE}"

    # --- Set environment variable ---
    step "Configuring environment..."

    if [ -f "$ENV_FILE" ]; then
        if set_corelix_platform_env true; then
            info "Set CORELIX_PLATFORM=true in .env"
        else
            warn "Could not update .env"
        fi
    else
        warn ".env file not found at ${ENV_FILE}. Feature flag will rely on docker-compose.custom.yml."
    fi
    success "Environment configured"

    # --- Restart Coolify ---
    step "Restarting Coolify stack..."

    if [ "$method" = "local" ]; then
        warn "Skipping upgrade.sh for local builds (it cannot pull local-only image tags)."
        warn "UI-triggered Coolify upgrades will fail until you switch to a registry image or remove docker-compose.custom.yml."
        if ! restart_coolify_stack compose; then
            error "Failed to restart Coolify with the local image."
            return 1
        fi
    elif ! restart_coolify_stack upgrade; then
        return 1
    fi
    success "Coolify stack restarted"

    # --- Wait and verify ---
    wait_for_coolify "$image_to_use" || true

    step "Verifying installation..."

    local running_image
    running_image=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
    info "Running image: ${running_image}"

    if [ "$running_image" = "$image_to_use" ]; then
        success "Correct image is running"
    else
        warn "Running image doesn't match expected. Expected: ${image_to_use}"
        warn "The upgrade.sh script may have pulled a different image."
        warn "Check your docker-compose.custom.yml is being loaded."
    fi

    if verify_package_discovered; then
        success "Package is discovered by Laravel"
    else
        warn "Could not verify package discovery. This is normal if Coolify is still starting."
    fi

    if docker exec coolify php artisan migrate:status 2>/dev/null | grep -q "project_user"; then
        success "Database migrations have run"
    else
        info "Migrations may not have run yet. They will run automatically on next container start."
        info "Or run manually: docker exec coolify php artisan migrate --path=vendor/corelix-io/platform/database/migrations --force"
    fi

    # --- Done ---
    echo ""
    echo -e "${GREEN}============================================${NC}"
    echo -e "${GREEN}  Addon Installation Complete!${NC}"
    echo -e "${GREEN}============================================${NC}"
    echo ""
    echo "What was done:"
    echo "  - Custom image: ${image_to_use}"
    echo "  - Created: ${CUSTOM_COMPOSE}"
    echo "  - Set: CORELIX_PLATFORM=true"
    echo "  - Restarted Coolify stack"
    echo ""
    echo "Next steps:"
    echo "  1. Log into Coolify as an admin/owner"
    echo "  2. Navigate to Team > Admin to see the Access Matrix"
    echo "  3. Configure per-user project/environment permissions"
    echo ""

    return 0
}

# =============================================================================
# ACTION: Uninstall Addon
# =============================================================================

action_uninstall_addon() {
    echo ""
    echo -e "${BOLD}Uninstall Enhanced Addon${NC}"
    separator

    local uninstall_script="${SCRIPT_DIR}/uninstall.sh"
    if [ -f "$uninstall_script" ]; then
        info "Running uninstall.sh..."
        echo ""
        bash "$uninstall_script" "${SCRIPT_ARGS[@]}"
    else
        error "uninstall.sh not found at ${uninstall_script}"
        echo ""
        echo "You can uninstall manually:"
        echo "  1. Remove ${CUSTOM_COMPOSE}"
        echo "  2. Remove CORELIX_PLATFORM from ${ENV_FILE}"
        echo "  3. Restart Coolify: cd ${COOLIFY_SOURCE} && docker compose up -d"
        return 1
    fi

    return 0
}

# =============================================================================
# ACTION: Check Status
# =============================================================================

action_check_status() {
    echo ""
    echo -e "${BOLD}Installation Status${NC}"
    separator

    # System
    step "System"

    if check_curl; then
        success "curl is installed"
    else
        warn "curl is not installed"
    fi

    if check_docker; then
        success "Docker is installed"
        local docker_ver
        docker_ver=$(docker --version 2>/dev/null || echo "unknown")
        info "  ${docker_ver}"
    else
        warn "Docker is NOT installed"
    fi

    if check_docker && check_docker_compose; then
        success "Docker Compose (v2) is available"
        local compose_ver
        compose_ver=$(docker compose version 2>/dev/null || echo "unknown")
        info "  ${compose_ver}"
    else
        warn "Docker Compose (v2) is NOT available"
    fi

    # Coolify
    step "Coolify"

    if check_coolify_installed; then
        success "Coolify files found at ${COOLIFY_SOURCE}"
    else
        warn "Coolify is NOT installed at ${COOLIFY_SOURCE}"
        echo ""
        info "Use option 1 in the menu to install Coolify."
        return 0
    fi

    if [ -f "$ENV_FILE" ]; then
        success ".env file exists"
    else
        warn ".env file not found"
    fi

    if [ -f "$UPGRADE_SCRIPT" ]; then
        success "upgrade.sh found"
    else
        warn "upgrade.sh not found"
    fi

    if check_docker && check_coolify_running; then
        success "Coolify container is running"
        local running_image
        running_image=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
        info "  Image: ${running_image}"

        local coolify_status
        coolify_status=$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "no healthcheck")
        info "  Health: ${coolify_status}"
    else
        warn "Coolify container is NOT running"
    fi

    # Addon
    step "Enhanced Addon"

    if [ -f "$CUSTOM_COMPOSE" ]; then
        if check_addon_installed; then
            success "docker-compose.custom.yml exists with addon config"
            local addon_image
            addon_image=$(grep "image:" "$CUSTOM_COMPOSE" 2>/dev/null | head -1 | awk '{print $2}' || echo "unknown")
            info "  Configured image: ${addon_image}"
        else
            info "docker-compose.custom.yml exists but does not contain addon config"
        fi
    else
        info "docker-compose.custom.yml does not exist (addon not installed)"
    fi

    if [ -f "$ENV_FILE" ]; then
        local env_val
        env_val=$(grep '^CORELIX_PLATFORM=' "$ENV_FILE" 2>/dev/null || echo "")
        if [ -n "$env_val" ]; then
            success "Environment: ${env_val}"
        else
            info "CORELIX_PLATFORM not set in .env"
        fi
    fi

    if check_docker && check_coolify_running; then
        if verify_package_discovered; then
            success "Package is discovered by Laravel"
        else
            info "Package not detected (addon may not be installed or container still starting)"
        fi

        if docker exec coolify php artisan migrate:status 2>/dev/null | grep -q "project_user"; then
            success "Database migrations have been applied"
        else
            info "Addon database migrations not detected"
        fi
    fi

    echo ""
    return 0
}

# =============================================================================
# Interactive Menu
# =============================================================================

show_banner() {
    echo ""
    echo -e "${CYAN}${BOLD}===============================================${NC}"
    echo -e "${CYAN}${BOLD}  Corelix Platform - Setup${NC}"
    echo -e "${CYAN}${BOLD}===============================================${NC}"
    echo ""
}

show_menu() {
    echo -e "  ${BOLD}1)${NC}  Install Coolify            ${DIM}(fresh server setup)${NC}"
    echo -e "  ${BOLD}2)${NC}  Install Enhanced Addon      ${DIM}(requires Coolify)${NC}"
    echo -e "  ${BOLD}3)${NC}  Uninstall Enhanced Addon"
    echo -e "  ${BOLD}4)${NC}  Check Installation Status"
    echo -e "  ${BOLD}5)${NC}  Full Setup                   ${DIM}(install Coolify + addon)${NC}"
    echo ""
    echo -e "  ${BOLD}0)${NC}  Exit"
    echo ""
}

run_menu() {
    while true; do
        show_banner
        show_menu

        read -r -p "Choose an option [0-5]: " menu_choice
        echo ""

        case "$menu_choice" in
            1)
                if ! action_install_coolify; then
                    warn "Coolify installation did not complete successfully."
                fi
                press_enter
                ;;
            2)
                if ! action_install_addon; then
                    warn "Addon installation did not complete successfully."
                fi
                press_enter
                ;;
            3)
                if ! action_uninstall_addon; then
                    warn "Addon uninstall did not complete successfully."
                fi
                press_enter
                ;;
            4)
                action_check_status
                press_enter
                ;;
            5)
                info "Starting full setup: Coolify + Enhanced addon"
                echo ""

                if action_install_coolify && check_coolify_installed; then
                    echo ""
                    separator
                    info "Coolify setup complete. Proceeding to addon installation..."
                    echo ""
                    if ! action_install_addon; then
                        warn "Addon installation did not complete successfully."
                    fi
                else
                    warn "Coolify installation did not complete successfully."
                    warn "Fix any issues above, then install the addon separately (option 2)."
                fi
                press_enter
                ;;
            0|q|Q|exit)
                echo "Goodbye!"
                exit 0
                ;;
            *)
                warn "Invalid option: ${menu_choice}"
                sleep 1
                ;;
        esac
    done
}

# =============================================================================
# Main
# =============================================================================

# Must be root for all operations
check_root

if [ "$HAS_CLI_ACTION" = true ]; then
    # --- CLI mode: run requested actions in order ---

    if [ "$ARG_STATUS" = true ]; then
        action_check_status
    fi

    if [ "$ARG_INSTALL_COOLIFY" = true ]; then
        if ! action_install_coolify; then
            error "Coolify installation failed."
            exit 1
        fi
    fi

    if [ "$ARG_INSTALL_ADDON" = true ]; then
        if ! action_install_addon; then
            error "Addon installation failed."
            exit 1
        fi
    fi

    if [ "$ARG_UNINSTALL" = true ]; then
        if ! action_uninstall_addon; then
            error "Addon uninstall failed."
            exit 1
        fi
    fi
else
    # --- Interactive menu mode ---
    run_menu
fi
