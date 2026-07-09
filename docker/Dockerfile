# =================================================================
# Corelix Platform
# =================================================================
# This Dockerfile extends the official Coolify image to add:
#   - Granular user role and project-level access management
#   - Encrypted S3 backups using rclone crypt backend
#   - Whitelabeling via PAAS_* build arguments
#
# Usage:
#   docker build -t corelix-platform:latest .
#   # Override the pinned base for local testing only:
#   docker build --build-arg COOLIFY_VERSION=latest -t corelix-platform:dev .
#
# Whitelabeling:
#   docker build \
#     --build-arg PAAS_BRAND_NAME="MyPaaS" \
#     --build-arg PAAS_DOCS_URL="https://docs.mypaas.io" \
#     -t mypaas:latest .
#
# Coolify base version pinning (DO NOT use a floating tag here):
#   This default is the canonical pin for every published Corelix image.
#   Bumping it requires re-running the upstream sync skill in the same PR
#   (overlays must move together with the base) — see docs/syncs/.
# =================================================================

ARG COOLIFY_VERSION=4.1.2

FROM ghcr.io/coollabsio/coolify:${COOLIFY_VERSION}


# Edition flag: free community images set CORELIX_EDITION=free at build time.
ARG CORELIX_EDITION=pro
ENV CORELIX_EDITION=${CORELIX_EDITION}

USER root

WORKDIR /var/www/html

# Copy the corelix-platform package into the web root so Composer (PHP) can read it.
# /tmp is outside open_basedir in the Coolify image, so Composer cannot discover
# composer.json from a /tmp path and reports "package not found".
# vendor/ and the docker/ build tooling are kept out of the package via .dockerignore.
# The docker/ assets that dedicated COPY steps below need (s6-overlay service, whitelabel.sh,
# brands) MUST remain in the build context (.dockerignore re-includes them), so they also
# arrive here via `COPY .` — strip the docker/ subtree from the installed package right after
# to keep it clean (the dedicated COPY steps below place them at their real destinations).
COPY --chown=www-data:www-data . /var/www/html/packages/corelix-platform/
RUN rm -rf /var/www/html/packages/corelix-platform/docker

# Install the local package via a relative path repository (within open_basedir).
RUN composer config repositories.corelix-platform path ./packages/corelix-platform && \
    composer require corelix-io/platform:^1.0 --no-interaction --no-progress --update-no-dev --ignore-platform-req=ext-sockets

# =================================================================
# Overlay: Replace Coolify files with encryption-aware versions
# =================================================================
# These are modified versions of Coolify's original files that add
# rclone-based encryption support. When encryption is not enabled
# on an S3 destination, they fall back to the original behavior.
# =================================================================

# Override DatabaseBackupJob with encryption-aware + classification-aware version
COPY --chown=www-data:www-data src/Overrides/Jobs/DatabaseBackupJob.php \
    /var/www/html/app/Jobs/DatabaseBackupJob.php

# Override StartDatabaseProxy with expanded database port mapping
COPY --chown=www-data:www-data src/Overrides/Actions/Database/StartDatabaseProxy.php \
    /var/www/html/app/Actions/Database/StartDatabaseProxy.php

# Override ServiceDatabase model with wire-compatible database type mappings
COPY --chown=www-data:www-data src/Overrides/Models/ServiceDatabase.php \
    /var/www/html/app/Models/ServiceDatabase.php

# Override ImportForm Livewire component with encryption-aware version
# (upstream v4.1.x split the database import form out of Import.php into ImportForm.php;
#  the thin Import.php parent is used unmodified, so only ImportForm.php is overlaid)
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Database/ImportForm.php \
    /var/www/html/app/Livewire/Project/Database/ImportForm.php

# Override constants helper with expanded DATABASE_DOCKER_IMAGES list
COPY --chown=www-data:www-data src/Overrides/Helpers/constants.php \
    /var/www/html/bootstrap/helpers/constants.php

# Override databases helper with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Helpers/databases.php \
    /var/www/html/bootstrap/helpers/databases.php

# Override storage show view to include encryption form natively
COPY --chown=www-data:www-data src/Overrides/Views/livewire/storage/show.blade.php \
    /var/www/html/resources/views/livewire/storage/show.blade.php

# Override settings backup view to include instance file backup section
COPY --chown=www-data:www-data src/Overrides/Views/livewire/settings-backup.blade.php \
    /var/www/html/resources/views/livewire/settings-backup.blade.php

# Override resource configuration views to add Resource Backups sidebar item
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/application/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/application/configuration.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/database/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/database/configuration.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/service/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/service/configuration.blade.php

# Override server sidebar to add Resource Backups item
COPY --chown=www-data:www-data src/Overrides/Views/components/server/sidebar.blade.php \
    /var/www/html/resources/views/components/server/sidebar.blade.php

# Override application/service Links dropdowns to add DNS status badges (DNS Provider Management)
COPY --chown=www-data:www-data src/Overrides/Views/components/applications/links.blade.php \
    /var/www/html/resources/views/components/applications/links.blade.php
COPY --chown=www-data:www-data src/Overrides/Views/components/services/links.blade.php \
    /var/www/html/resources/views/components/services/links.blade.php

# Override settings navbar to add Restore + Templates + Networks + Appearance tabs
COPY --chown=www-data:www-data src/Overrides/Views/components/settings/navbar.blade.php \
    /var/www/html/resources/views/components/settings/navbar.blade.php

# Override main navbar to support brand name/logo and whitelabeled support links
COPY --chown=www-data:www-data src/Overrides/Views/components/navbar.blade.php \
    /var/www/html/resources/views/components/navbar.blade.php

# Override app layout to support brand name/logo in mobile top bar
COPY --chown=www-data:www-data src/Overrides/Views/layouts/app.blade.php \
    /var/www/html/resources/views/layouts/app.blade.php

# Override profile appearance page to surface the instance-wide UI theme settings link (owner/admin only)
COPY --chown=www-data:www-data src/Overrides/Views/livewire/profile/appearance.blade.php \
    /var/www/html/resources/views/livewire/profile/appearance.blade.php

# Override base layout to inject enhanced UI theme when enabled
COPY --chown=www-data:www-data src/Overrides/Views/layouts/base.blade.php \
    /var/www/html/resources/views/layouts/base.blade.php

# Enhanced UI themes (CSS + self-hosted fonts, selectable in Settings > Appearance)
RUN mkdir -p /var/www/html/public/vendor/corelix-platform/themes
COPY --chown=www-data:www-data resources/assets/themes/ \
    /var/www/html/public/vendor/corelix-platform/themes/

# Override shared helper to merge custom template sources into service templates
COPY --chown=www-data:www-data src/Overrides/Helpers/shared.php \
    /var/www/html/bootstrap/helpers/shared.php

# Override proxy helper for proxy network isolation (Phase 2)
COPY --chown=www-data:www-data src/Overrides/Helpers/proxy.php \
    /var/www/html/bootstrap/helpers/proxy.php

# Override docker helper for Traefik/Caddy proxy network label injection (Phase 2)
COPY --chown=www-data:www-data src/Overrides/Helpers/docker.php \
    /var/www/html/bootstrap/helpers/docker.php

# Override Service/Index Livewire component with multi-port proxy support
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Service/Index.php \
    /var/www/html/app/Livewire/Project/Service/Index.php

# Override service index view with multi-port proxy UI
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/service/index.blade.php \
    /var/www/html/resources/views/livewire/project/service/index.blade.php

# Override New Resource select view to show custom template source labels
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/select.blade.php \
    /var/www/html/resources/views/livewire/project/new/select.blade.php

# Override web resource creation Livewire components with authorize('create') gates
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Resource/Create.php \
    /var/www/html/app/Livewire/Project/Resource/Create.php
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/New/PublicGitRepository.php \
    /var/www/html/app/Livewire/Project/New/PublicGitRepository.php
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/New/SimpleDockerfile.php \
    /var/www/html/app/Livewire/Project/New/SimpleDockerfile.php
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/New/DockerImage.php \
    /var/www/html/app/Livewire/Project/New/DockerImage.php
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/New/GithubPrivateRepository.php \
    /var/www/html/app/Livewire/Project/New/GithubPrivateRepository.php
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/New/GithubPrivateRepositoryDeployKey.php \
    /var/www/html/app/Livewire/Project/New/GithubPrivateRepositoryDeployKey.php

# =================================================================
# Overlay: Additional build types (Railpack, Heroku, Paketo)
# =================================================================

# Override BuildPackTypes enum with additional build types
COPY --chown=www-data:www-data src/Overrides/Enums/BuildPackTypes.php \
    /var/www/html/app/Enums/BuildPackTypes.php

# Override ApplicationDeploymentJob with additional build type support
COPY --chown=www-data:www-data src/Overrides/Jobs/ApplicationDeploymentJob.php \
    /var/www/html/app/Jobs/ApplicationDeploymentJob.php

# Override application general view with additional build type options
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/application/general.blade.php \
    /var/www/html/resources/views/livewire/project/application/general.blade.php


# Override new resource creation views with additional build type options
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/public-git-repository.blade.php \
    /var/www/html/resources/views/livewire/project/new/public-git-repository.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/github-private-repository.blade.php \
    /var/www/html/resources/views/livewire/project/new/github-private-repository.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/github-private-repository-deploy-key.blade.php \
    /var/www/html/resources/views/livewire/project/new/github-private-repository-deploy-key.blade.php

# Override debugbar config to prevent OOM on pages with many env vars / Livewire components
COPY --chown=www-data:www-data src/Overrides/Config/debugbar.php \
    /var/www/html/config/debugbar.php


# Copy the s6-overlay script for addon migrations
COPY --chmod=755 docker/s6-overlay/s6-rc.d/addon-migration/ /etc/s6-overlay/s6-rc.d/addon-migration/

# Register addon-migration in s6 user services and gate workers on addon schema
RUN mkdir -p /etc/s6-overlay/s6-rc.d/user/contents.d && \
    touch /etc/s6-overlay/s6-rc.d/user/contents.d/addon-migration && \
    mkdir -p /etc/s6-overlay/s6-rc.d/horizon/dependencies.d && \
    touch /etc/s6-overlay/s6-rc.d/horizon/dependencies.d/addon-migration && \
    mkdir -p /etc/s6-overlay/s6-rc.d/scheduler-worker/dependencies.d && \
    touch /etc/s6-overlay/s6-rc.d/scheduler-worker/dependencies.d/addon-migration

# Update autoloader and fix ownership
# composer require/dump-autoload run as root, which leaves vendor/ owned by root.
# Coolify's s6 startup script runs composer install as www-data and will fail
# with "Permission denied" if vendor/ is still root-owned.
RUN composer dump-autoload && \
    chown -R www-data:www-data /var/www/html/vendor /var/www/html/bootstrap/cache

USER www-data
