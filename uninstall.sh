#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Corelix Platform - Uninstaller
# =============================================================================
# Removes the enhanced addon from a running Coolify v4 instance.
# Optionally cleans up database tables.
#
# Usage:
#   bash uninstall.sh                  # Interactive uninstall
#   bash uninstall.sh --keep-db        # Skip database cleanup prompt
#   bash uninstall.sh --clean-db       # Remove database tables without prompting
#   bash uninstall.sh --unattended     # Non-interactive (accept defaults)
# =============================================================================

COOLIFY_BASE="${COOLIFY_BASE:-/data/coolify}"
COOLIFY_SOURCE="${COOLIFY_SOURCE:-${COOLIFY_BASE}/source}"
COMPOSE_FILE="${COOLIFY_SOURCE}/docker-compose.yml"
PROD_COMPOSE="${COOLIFY_SOURCE}/docker-compose.prod.yml"
CUSTOM_COMPOSE="${COOLIFY_SOURCE}/docker-compose.custom.yml"
UPGRADE_SCRIPT="${COOLIFY_SOURCE}/upgrade.sh"
ENV_FILE="${COOLIFY_SOURCE}/.env"
BACKUP_SUFFIX=".backup.$(date +%Y%m%d_%H%M%S)"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Parse arguments
DB_ACTION="prompt"
UNATTENDED=false
REMOVE_IMAGES=false
for arg in "$@"; do
    case "$arg" in
        --keep-db)       DB_ACTION="keep" ;;
        --clean-db)      DB_ACTION="clean" ;;
        --unattended|--yes) UNATTENDED=true ;;
        --remove-images) REMOVE_IMAGES=true ;;
        --help|-h)
            echo "Usage: bash uninstall.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --keep-db         Keep database tables (permission data preserved)"
            echo "  --clean-db        Remove database tables without prompting"
            echo "  --unattended, --yes  Non-interactive mode (accept defaults)"
            echo "  --remove-images   Remove local enhanced Docker images (non-interactive)"
            echo "  --help, -h        Show this help message"
            exit 0
            ;;
    esac
done

# --- Helper functions ---

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; }
step()    { echo -e "\n${CYAN}==>${NC} $*"; }

confirm() {
    if [ "$UNATTENDED" = true ]; then
        return 0
    fi
    local prompt="${1:-Continue?} [y/N] "
    local response=""
    if ! read -r -p "$prompt" response; then
        return 1
    fi
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
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

resolve_coolify_db_container() {
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'coolify-db'; then
        echo "coolify-db"
        return 0
    fi

    if [ -f "$COMPOSE_FILE" ] && [ -f "$PROD_COMPOSE" ]; then
        local cid
        cid=$(
            cd "$COOLIFY_SOURCE"
            docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.prod.yml ps -q coolify-db 2>/dev/null | head -1
        )
        if [ -n "$cid" ]; then
            docker inspect --format '{{.Name}}' "$cid" | sed 's/^\///'
            return 0
        fi
    fi

    return 1
}

# --- Pre-flight checks ---

step "Checking prerequisites..."

if [ "$(id -u)" -ne 0 ]; then
    error "This script must be run as root (or with sudo)."
    exit 1
fi
success "Running as root"

if ! command -v docker &>/dev/null; then
    error "Docker is not installed."
    exit 1
fi
success "Docker is available"

if [ ! -d "$COOLIFY_SOURCE" ]; then
    error "Coolify installation not found at ${COOLIFY_SOURCE}"
    exit 1
fi
success "Coolify directory found"

# --- Confirmation ---

echo ""
echo -e "${YELLOW}This will uninstall the Corelix Platform addon.${NC}"
echo ""
echo "What will happen:"
echo "  - docker-compose.custom.yml will be removed (backed up first)"
echo "  - CORELIX_PLATFORM env var will be removed"
echo "  - Coolify will be restarted without the addon image (no Coolify version upgrade)"
echo "  - All projects, users, and deployments remain intact"
echo ""

if ! confirm "Proceed with uninstall?"; then
    echo "Aborted."
    exit 0
fi

# --- Database cleanup ---

step "Database cleanup..."

if [ "$DB_ACTION" = "prompt" ]; then
    echo ""
    echo "The addon created tables and columns across these feature areas:"
    echo "  - Permissions: project_user, environment_user, users.is_global_admin, users.status"
    echo "  - Backups/encryption: scheduled_resource_backups, s3_storages encryption columns, etc."
    echo "  - Templates, networks, clusters, registries, DNS, UI theme, label overrides"
    echo ""
    echo "migrate:reset removes all addon migration batches while the enhanced image is still running."
    echo "Keeping schema is harmless — standard Coolify ignores unused tables."
    echo ""

    if confirm "Remove all addon database tables and columns? (data will be lost)"; then
        DB_ACTION="clean"
    else
        DB_ACTION="keep"
        info "Database schema will be preserved."
    fi
fi

if [ "$DB_ACTION" = "clean" ]; then
    info "Attempting database cleanup..."

    if docker exec coolify php artisan migrate:reset \
        --path=vendor/corelix-io/platform/database/migrations \
        --force; then
        success "All addon migrations reset via artisan"
    else
        warn "Could not reset migrations via artisan."

        DB_CONTAINER=""
        DB_CONTAINER=$(resolve_coolify_db_container) || DB_CONTAINER=""

        if [ -n "$DB_CONTAINER" ]; then
            DB_USER="coolify"
            DB_NAME="coolify"
            if [ -f "$ENV_FILE" ]; then
                DB_USER=$(grep "^DB_USERNAME=" "$ENV_FILE" 2>/dev/null | cut -d= -f2 || echo "coolify")
                DB_NAME=$(grep "^DB_DATABASE=" "$ENV_FILE" 2>/dev/null | cut -d= -f2 || echo "coolify")
                [ -z "$DB_USER" ] && DB_USER="coolify"
                [ -z "$DB_NAME" ] && DB_NAME="coolify"
            fi

            SQL="DROP TABLE IF EXISTS domain_environment_bindings, managed_hostnames, domain_server, domains, cloudflare_tunnels, dns_providers, docker_registry_server, docker_registries, swarm_configs, swarm_secrets, cluster_events, resource_networks, managed_networks, scheduled_resource_backup_executions, scheduled_resource_backups, custom_template_sources, clusters, enhanced_ui_settings, environment_user, project_user CASCADE; ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin, DROP COLUMN IF EXISTS status; ALTER TABLE servers DROP COLUMN IF EXISTS cluster_id; ALTER TABLE scheduled_database_backup_executions DROP COLUMN IF EXISTS is_encrypted; ALTER TABLE service_applications DROP COLUMN IF EXISTS label_overrides; ALTER TABLE service_databases DROP COLUMN IF EXISTS label_overrides, DROP COLUMN IF EXISTS proxy_ports; ALTER TABLE applications DROP COLUMN IF EXISTS docker_compose_label_overrides;"

            if docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -c "$SQL"; then
                success "Database tables cleaned via SQL on ${DB_CONTAINER}"
            else
                warn "SQL cleanup failed on ${DB_CONTAINER}."
                echo ""
                echo "Run manually while coolify-db is running:"
                echo "  docker exec coolify php artisan migrate:reset --path=vendor/corelix-io/platform/database/migrations --force"
            fi
        else
            warn "coolify-db is not running — cannot run SQL fallback safely."
            echo ""
            echo "Start Coolify, then run:"
            echo "  docker exec coolify php artisan migrate:reset --path=vendor/corelix-io/platform/database/migrations --force"
        fi
    fi
fi

# --- Remove docker-compose.custom.yml ---

step "Removing docker-compose.custom.yml..."

if [ -f "$CUSTOM_COMPOSE" ]; then
    cp "$CUSTOM_COMPOSE" "${CUSTOM_COMPOSE}${BACKUP_SUFFIX}"
    info "Backed up to docker-compose.custom.yml${BACKUP_SUFFIX}"
    rm "$CUSTOM_COMPOSE"
    success "Removed ${CUSTOM_COMPOSE}"
else
    info "No docker-compose.custom.yml found (already removed)"
fi

# --- Remove environment variable ---

step "Cleaning environment variables..."

if [ -f "$ENV_FILE" ]; then
    if grep -q '^CORELIX_PLATFORM=' "$ENV_FILE"; then
        sed -i '/^CORELIX_PLATFORM=/d' "$ENV_FILE"
        success "Removed CORELIX_PLATFORM from .env"
    else
        info "CORELIX_PLATFORM not found in .env (already removed)"
    fi
else
    info "No .env file found"
fi

# --- Restart Coolify ---

step "Restarting Coolify without addon overlay..."

info "Using docker compose directly (avoids upgrade.sh pulling latest Coolify)."
if ! compose_stack_up no; then
    error "Failed to restart Coolify stack."
    exit 1
fi

success "Coolify stack restarted"

# --- Wait and verify ---

step "Waiting for Coolify to be ready..."

MAX_WAIT=120
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'coolify'; then
        if docker ps --format '{{.Names}}\t{{.Status}}' --filter name=^coolify$ 2>/dev/null | grep -q "Up"; then
            break
        fi
    fi
    sleep 2
    WAITED=$((WAITED + 2))
    echo -n "."
done
echo ""

if [ $WAITED -ge $MAX_WAIT ]; then
    warn "Timed out waiting for Coolify. Check: docker logs coolify --tail 50"
else
    success "Coolify is running"
fi

RUNNING_IMAGE=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
info "Running image: ${RUNNING_IMAGE}"

# --- Clean up local Docker image (optional) ---

if docker images --format '{{.Repository}}:{{.Tag}}' | grep -q "corelix-platform"; then
    if [ "$REMOVE_IMAGES" = true ]; then
        docker images --format '{{.Repository}}:{{.Tag}}' | grep "corelix-platform" | while read -r img; do
            docker rmi "$img" 2>/dev/null && info "Removed image: $img" || true
        done
        success "Local images cleaned up"
    elif [ "$UNATTENDED" = true ]; then
        info "Local enhanced images preserved (pass --remove-images to delete)"
    else
        echo ""
        if confirm "Remove local enhanced Docker images?"; then
            docker images --format '{{.Repository}}:{{.Tag}}' | grep "corelix-platform" | while read -r img; do
                docker rmi "$img" 2>/dev/null && info "Removed image: $img" || true
            done
            success "Local images cleaned up"
        else
            info "Local images preserved"
        fi
    fi
fi

# --- Done ---

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  Uninstall Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "What was done:"
echo "  - Removed docker-compose.custom.yml (backup saved)"
echo "  - Removed CORELIX_PLATFORM from .env"
if [ "$DB_ACTION" = "clean" ]; then
    echo "  - Reset addon database migrations"
else
    echo "  - Database schema preserved (safe to keep)"
fi
echo "  - Restarted Coolify without the addon image"
echo ""
echo "Coolify is now running with its default configuration."
echo "All team members have full access to all projects (standard behavior)."
echo ""
echo "To reinstall later, run: bash install.sh"
echo ""
