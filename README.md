# Corelix Platform

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Build and Publish Docker Image](https://github.com/corelix-io/coolify-enhanced/actions/workflows/docker-build-publish.yml/badge.svg)](https://github.com/corelix-io/coolify-enhanced/actions/workflows/docker-build-publish.yml)
[![Docker Image](https://img.shields.io/badge/ghcr.io-coolify--enhanced-blue)](https://ghcr.io/corelix-io/coolify-enhanced)

**The missing enterprise features for Coolify v4 — granular permissions, encrypted backups, volume/config backups, custom service templates, enhanced database classification, Docker network isolation, Docker Swarm cluster management, Docker registry management, and additional build types (Railpack, Heroku/Paketo Buildpacks).**

Corelix Platform is a drop-in addon for [Coolify](https://coolify.io) that adds access control, backup security, template extensibility, and Docker Swarm cluster management that teams need when running Coolify in production. It installs in under 2 minutes, requires zero changes to your existing setup, and can be removed cleanly at any time.

> If you're coming from Dokploy, Portainer, CapRover, or another open-source PaaS and chose Coolify for its simplicity — this addon fills the remaining gaps for team management and backup security.

---

## Why Corelix Platform?

Coolify v4 is an excellent self-hosted PaaS, but ships with a few limitations for production team use:

| Gap | Without This Addon | With Corelix Platform |
|-----|--------------------|-----------------------|
| **Access control** | All team members see and can manage all projects | Project-level and environment-level permissions with View Only, Deploy, and Full Access roles |
| **Backup encryption** | Database backups stored as plaintext on S3 | NaCl SecretBox encryption (XSalsa20 + Poly1305) — military-grade, at rest |
| **Volume & config backups** | Only database dumps are backed up | Docker volumes, app configuration, and full resource backups on schedule |
| **Service templates** | Limited to Coolify's built-in 200+ templates | Add unlimited custom templates from any GitHub repository |
| **Database classification** | Many databases (Memgraph, Milvus, Qdrant, etc.) misclassified as applications | 50+ additional database images recognized; explicit label and comment overrides |
| **Network isolation** | All containers share a single flat Docker network | Per-environment bridge networks, dedicated proxy network, cross-env shared networks, Docker Swarm overlay support |
| **MCP Server (AI Assistant Integration)** | None | 148 MCP tools covering all Coolify (and Corelix Platform) API endpoints |
| **Cluster management** | Checkbox-only Swarm config, no dashboard, no node management | Full cluster dashboard, node management, service/task viewer, visualizer, secrets/configs, structured deploy config |
| **Docker registries** | Per-server manual `docker login` | Team-level registry management with provider-specific auth; auto-sync to all servers; ECR token refresh; Settings > Registries |
| **UI theme** | Single default look | Multiple selectable themes — Default (Coolify), Enhanced (Linear), TailAdmin — Settings > Appearance; stock Coolify by default |
| **Build options** | Nixpacks, Dockerfile, Docker Compose, Static only | Railpack, Heroku Buildpacks, and Paketo Buildpacks as additional build options alongside the standard choices |


All features are **independent** — enable only what you need. When disabled, Coolify behaves exactly as stock.

---

## Table of Contents

- [Features at a Glance](#features-at-a-glance)
- [Screenshots](#screenshots)
- [Quick Start](#quick-start)
- [Feature Details](#feature-details)
  - [Granular Permissions](#1-granular-permissions)
  - [Encrypted S3 Backups](#2-encrypted-s3-backups)
  - [Resource Backups (Volumes, Config, Full)](#3-resource-backups)
  - [Custom Template Sources](#4-custom-template-sources)
  - [Enhanced Database Classification](#5-enhanced-database-classification)
  - [Network Management](#6-network-management)
  - [MCP Server](#7-mcp-server)
  - [Cluster Management](#8-cluster-management)
  - [Enhanced UI Theme](#9-enhanced-ui-theme)
  - [Additional Build Types](#10-additional-build-types)
  - [Traefik Label Overrides](#11-traefik-label-overrides)
  - [Docker Registry Management](#12-docker-registry-management)
  - [DNS Provider Management](#13-dns-provider-management)
- [Editions (Free & Pro)](#editions-free--pro)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [Upgrading & Reverting](#upgrading--reverting)
- [Architecture Overview](#architecture-overview)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

---

## Features at a Glance

### Granular Permissions
- Project-level and environment-level access control
- Three permission tiers: **View Only**, **Deploy**, **Full Access**
- Visual Access Matrix UI on the Team Admin page
- Environment-level overrides that cascade from project permissions
- Owner/Admin bypass — only Members and Viewers are restricted
- Full REST API for automation

### Encrypted S3 Backups
- Per-storage encryption using rclone's crypt backend (NaCl SecretBox)
- Transparent encrypt-on-upload, decrypt-on-download
- Optional filename and directory name encryption
- Configurable per S3 storage destination
- Backward compatible — existing unencrypted backups keep working

### Resource Backups
- **Volume backups** — tar.gz snapshots of Docker named volumes and bind mounts
- **Configuration backups** — JSON export of resource settings, environment variables, docker-compose, labels
- **Full backups** — volumes + configuration in one shot
- **Coolify instance backups** — full `/data/coolify` directory backup
- Independent cron scheduling per resource
- Retention policies (by count, by age, or by storage)
- Same S3 upload pipeline with optional encryption

### Enhanced Database Classification
- **50+ additional database images** recognized out of the box (graph, vector, time-series, document, search, column-family, NewSQL, OLAP)
- **`coolify.database` Docker label** — explicitly mark any service as a database (or not) in your compose files
- **`# type: database` comment convention** — template-level metadata that automatically classifies all services
- **Wire-compatible backup support** — YugabyteDB, TiDB, FerretDB, Percona, and Apache AGE get native dump-based backups
- **Expanded port mapping** — "Make Publicly Available" works for all recognized database types
- **Meaningful error messages** — unsupported backup types guide users to `custom_type` or Resource Backups

### Network Management
- **Per-environment isolation** — each environment gets its own Docker bridge network
- **Shared networks** — user-created cross-environment communication channels
- **Proxy network isolation** — dedicated proxy network prevents the reverse proxy from accessing internal services
- **Docker Swarm support** — automatic overlay networks for multi-host clusters with optional IPsec encryption
- **Three isolation modes**: `none` (manual only), `environment` (auto per-env), `strict` (disconnects from default)
- **Post-deployment hooks** — zero overlay files for Phase 1; containers joined to networks after Coolify deploys normally
- Server-level network management UI + per-resource network assignment UI
- REST API for automation

### Custom Template Sources
- Add any GitHub repository (public or private) as a template source
- Templates appear alongside Coolify's built-in services on the New Resource page
- **Filter by source** — dropdown to show All, Coolify Official, or a specific custom source
- Auto-sync on configurable schedule (default: every 6 hours)
- Same YAML format as Coolify's built-in templates — zero learning curve
- Name collision handling — built-in templates always take precedence
- Deployed services are independent of template sources (write-once)

### Cluster Management (Docker Swarm)
- **Cluster Dashboard** — real-time status cards, node listing, service/task counts
- **Node Management** — drain/activate/pause, promote/demote, add/remove nodes, label CRUD
- **Service/Task Viewer** — table with inline task expansion, scaling, rollback, force update
- **Cluster Visualizer** — dual view: Portainer-style column-per-node grid + topology map
- **Swarm Secrets & Configs** — full CRUD for Docker Swarm primitives
- **Structured Swarm Config** — form replacing raw YAML textarea for replicas, placement, rollback, health checks, resource limits
- **Event Log** — real-time cluster event stream with type/action filters
- **Auto-detection** — discovers Swarm clusters from existing manager servers
- **K8s-ready architecture** — orchestrator abstraction layer (`ClusterDriverInterface`) for future Kubernetes support
- **20 MCP tools** for AI-driven cluster management
- **Status notifications** — alerts when clusters degrade or become unreachable

### Enhanced UI Theme
- **Multiple selectable themes** — Default (Coolify), Enhanced (Linear), TailAdmin
- **CSS-only** — no DOM changes; same layout and framework (Tailwind); fonts self-hosted (WOFF2 in image)
- **Settings > Appearance** — theme dropdown; **Default (Coolify)** by default
- **Instance-wide** — admin-controlled; preference stored in database; reload after changing to see updates

### Additional Build Types
- **Heroku Buildpacks and Paketo Buildpacks** (Cloud Native Buildpacks) as build options alongside Nixpacks, Dockerfile, Docker Compose, Static — and Railpack
- Heroku Buildpacks — Cloud Native Buildpacks via `heroku/builder`; standard `pack` CLI
- Paketo Buildpacks — Cloud Native Buildpacks via `paketobuildpacks/builder`; Java, Node, Go, and more
- Railpack — now a **native** Coolify v4.1.x build type (ships in the pinned base image; no longer provided by this addon)
- Build Pack dropdown available on application General settings and New Resource pages

### Traefik Label Overrides
- **Per-container label control** — Override or augment any auto-generated Traefik label for any container in a service template or Docker Compose application
- **YAML textarea UI** — Collapsible textarea per container in the Advanced section of the service index page
- **User always wins** — User-defined labels are deep-merged over Coolify's auto-generated ones; last write wins per key
- **`coolify.*` keys protected** — Coolify's internal monitoring labels are silently filtered from user input to prevent accidental misuse
- **REST API** — 6 endpoints for programmatic management of overrides per ServiceApplication and ServiceDatabase
- **Zero parsers.py overlay** — Implemented via an Eloquent `updating` observer on the `Service` model; fires inside `serviceParser()`'s own `$resource->save()` call

### Docker Registry Management
- **Multiple container registries** — Docker Hub, GHCR, GitLab, AWS ECR, Quay, Azure ACR, and custom registries with provider-specific authentication
- **Team-level management** — Registries defined once and automatically synced to all servers via `docker login` over SSH
- **Connection testing** — Validate credentials via Docker Registry V2 API before saving
- **ECR token refresh** — Automatic 12-hour token refresh via AWS SDK (IAM or access keys)
- **Settings > Registries** — Centralized management UI
- **Server > Registries** — Per-server sync status in the sidebar
- **REST API** — Full CRUD plus test and sync endpoints
- **MCP tools** — AI assistant integration for registry management
- **Zero deployment changes** — Leverages Coolify's existing `config.json` mounting; no deployment job modifications

### DNS Provider Management
- **Automatic DNS + ingress** — deployed resources become reachable with zero manual DNS work; FQDN changes reconcile automatically across create/rename/move/delete
- **Cloudflare Tunnel driver first** — Corelix deploys and manages the `cloudflared` daemon for you (no host SSH needed)
- **Settings > DNS Providers** — add a provider with encrypted credentials, test the connection inline, add a domain, done
- **Per-resource Domains panel** + links-menu status dots (`synced | pending | error | drifted | unmanaged`)
- **Pro depth** — multiple domains/providers, per-hostname routing, environment→domain bindings, database A/AAAA records, drift detection + health dashboard, Cloudflare Access policies
- **REST API + MCP tools** — built for agent-driven workflows; the wildcard happy path needs no DNS call at all
- Disabled by default (`CORELIX_DNS_PROVIDER_MANAGEMENT=true` to enable); fully revertible — disabling restores stock Coolify behavior

### MCP Server (AI Assistant Integration)
- **148 MCP tools** wrapping all Coolify API endpoints for AI-driven infrastructure management
- Works with **Claude Desktop**, **Cursor**, **VS Code Copilot**, **Kiro IDE**, and any MCP-compatible client
- **Core tools** (72) work with standard Coolify — no addon required
- **Enhanced tools** (71) for permissions, resource backups, custom templates, network management, DNS management, cluster management, and registry management
- **Auto-detection** of corelix-platform features — falls back gracefully to core tools
- **Tool annotations** — read-only, destructive, and idempotent hints for AI safety
- Install via `npx @amirhmoradi/coolify-enhanced-mcp`

#### Quick Setup (Claude Desktop)

Add to your `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token"
      }
    }
  }
}
```

See [mcp-server/README.md](mcp-server/README.md) for full documentation and the complete tool reference.

---

## Screenshots

### Access Matrix — Team Permission Management

<!-- SCREENSHOT: Access Matrix on the Team > Admin page showing the user/project/environment permission grid with dropdowns -->
<img width="2372" height="1956" alt="coolify-granular-user-permissions" src="https://github.com/user-attachments/assets/ab96988f-5f77-4dbe-8371-d9f093f8ccf7" />

*The Access Matrix provides a unified view of all users, projects, and environments with per-cell permission controls.*

### S3 Storage — Encryption Settings

<!-- SCREENSHOT: S3 Storage detail page showing the encryption toggle, password fields, filename encryption dropdown -->
<img width="2360" height="1938" alt="coolify-s3-pathprefix-encryption" src="https://github.com/user-attachments/assets/8a6f2713-83d6-4153-a32b-0f1f928ba613" />

*Enable per-storage encryption with a single toggle. Configure encryption password, salt, and filename encryption mode.*

### Resource Backups — Application Configuration Page

<!-- SCREENSHOT: Application configuration page with Resource Backups sidebar item selected, showing backup schedules and executions -->
<img width="2364" height="1584" alt="coolify-resource-app-full-new-backup" src="https://github.com/user-attachments/assets/846964fa-ea29-4442-8579-f0f2409c544f" />

<img width="2362" height="1386" alt="coolify-resource-app-full-executions-backup" src="https://github.com/user-attachments/assets/5672339f-6886-452d-9310-de52d4284e2a" />


*Schedule volume, configuration, or full backups for any application, database, or service with independent cron expressions.*

### Resource Backups — Server Overview

<!-- SCREENSHOT: Server sidebar with Resource Backups item, showing all resource backups for the server -->
<img width="2366" height="1960" alt="coolify-instance-file-backups" src="https://github.com/user-attachments/assets/977c6e2c-4802-4aaf-8a85-5b944a9dbfc8" />

*View and manage all resource backups across a server from a single page.*

### Settings — Restore & Import

<!-- SCREENSHOT: Settings > Restore page showing the JSON backup viewer and env var bulk import -->
<img width="2362" height="1852" alt="import-server-backups" src="https://github.com/user-attachments/assets/8aa300f0-2703-4975-9fbe-1e33a15c3de1" />

*Browse configuration backup contents, bulk-import environment variables, and follow step-by-step restoration guides.*

### Custom Template Sources — Settings Page

<!-- SCREENSHOT: Settings > Templates page showing added sources with sync status, template count, and template previews -->
<img width="2368" height="1076" alt="custom-template-sources-settings" src="https://github.com/user-attachments/assets/7a1e67c6-7b7e-471f-b413-54b387c2c2a5" />

*Add GitHub repositories as template sources, view sync status, preview discovered templates, and trigger manual syncs.*

### New Resource Page — Source Filter & Labels

<!-- SCREENSHOT: New Resource page showing service cards with custom template source labels and the source filter dropdown -->
<img width="2364" height="1030" alt="New-Resources-From-Custom-templates-sources" src="https://github.com/user-attachments/assets/41fbeb33-832c-4aec-a3de-646ba738b2c7" />

*Custom templates appear alongside built-in services with source labels. Use the source filter dropdown to narrow by origin.*

### Instance File Backup — Settings Page

<!-- SCREENSHOT: Settings > Backup page showing the Instance File Backup section below the native database backup -->
<img width="2366" height="1960" alt="coolify-instance-file-backups" src="https://github.com/user-attachments/assets/fcb1e267-1408-437b-8095-d775181f61e2" />


*Schedule full `/data/coolify` directory backups (excluding backup directories) from the Settings page.*

### Network Management

<!-- SCREENSHOT: Network Management -->
#### Server level Network Management
<img width="2544" height="1814" alt="coolify-server-network-management" src="https://github.com/user-attachments/assets/e2194a98-b49f-4931-8618-cf7816e5a0cf" />

#### Project level Network Settings

<img width="2556" height="1806" alt="coolify-project-level-networks" src="https://github.com/user-attachments/assets/365fb628-4a35-4e8a-ad6e-53ac7cabd8b5" />

### MCP Server



---

## Quick Start

### One-Line Install (Coolify Already Running)

```bash
git clone https://github.com/corelix-io/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh --install-addon
```

### Fresh Server (Coolify + Addon)

```bash
git clone https://github.com/corelix-io/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh --install-coolify --install-addon --unattended
```

### Manual Install (2 Files)

Create `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: ghcr.io/corelix-io/coolify-enhanced:latest
    environment:
      - CORELIX_PLATFORM=true
```

Restart Coolify:

```bash
cd /data/coolify/source && bash upgrade.sh
```

> Coolify natively supports `docker-compose.custom.yml` — it is merged with the main compose file and survives upgrades.

That's it. Navigate to **Team > Admin** to see the Access Matrix, open any **S3 Storage** page for encryption settings, check **Settings > Templates** to add custom template sources, check **Settings > Registries** for Docker registry management (Pro), and enable `CORELIX_CLUSTER_MANAGEMENT=true` to access the cluster dashboard.

---

## Feature Details

### 1. Granular Permissions

Coolify v4 gives all team members full access to everything. Corelix Platform adds project-level and environment-level access control so you can restrict who sees, deploys, and manages each project.

#### Permission Levels

| Level | View | Deploy | Manage | Delete |
|-------|:----:|:------:|:------:|:------:|
| **View Only** | Yes | — | — | — |
| **Deploy** | Yes | Yes | — | — |
| **Full Access** | Yes | Yes | Yes | Yes |

#### Role Hierarchy

| Role | Behavior |
|------|----------|
| **Owner** | Full access to everything (bypasses all checks) |
| **Admin** | Full access to everything (bypasses all checks) |
| **Member** | Requires explicit project/environment access |
| **Viewer** | Requires explicit project/environment access |

#### How Permission Resolution Works

```
1. Is the user an Owner or Admin?  -->  Allow (bypass)
2. Does an environment-level override exist?  -->  Use it
3. Does a project-level permission exist?  -->  Use it (cascades to environments)
4. No access record found  -->  Deny
```

Environment overrides take precedence over project permissions. This lets you give a developer Deploy access to a project but restrict production to View Only.

#### Access Matrix UI

The Access Matrix is injected into the **Team > Admin** page. It provides:

- A grid of all users vs. all projects/environments
- Per-cell dropdown to set permission level (None, View Only, Deploy, Full Access)
- **All/None** buttons per row (set all resources for a user) and per column (set all users for a resource)
- User search/filter
- Visual indicators for role bypass, inheritance, and current level

<!-- SCREENSHOT: Close-up of the Access Matrix grid with dropdowns and All/None buttons -->
![Access Matrix Close-up](<!-- INSERT_SCREENSHOT_URL: access-matrix-closeup.png -->)

---

### 2. Encrypted S3 Backups

Every S3 storage destination in Coolify can independently enable encryption. When enabled, all database backups to that storage are encrypted before upload using [rclone's crypt backend](https://rclone.org/crypt/) — NaCl SecretBox (XSalsa20 + Poly1305), an industry-standard authenticated encryption scheme.

#### Configuration Options

| Setting | Description |
|---------|-------------|
| **Enable Encryption** | Toggle on/off per storage |
| **Encryption Password** | Main encryption key (required) |
| **Salt (password2)** | Optional secondary key for extra security |
| **Filename Encryption** | `off` (default), `standard`, or `obfuscate` |
| **Directory Name Encryption** | Encrypt directory names on S3 (requires filename encryption) |
| **S3 Path Prefix** | Optional path prefix for multi-instance bucket sharing |

#### How It Works

```
Backup Job starts
     |
     v
S3 Storage has encryption enabled?
     |
  No --> mc upload (standard Coolify behavior)
  Yes --> rclone crypt upload:
           1. Build env config (S3 remote + crypt remote + obscured passwords)
           2. Write env file to server
           3. Docker run rclone container with --env-file
           4. Upload encrypted backup
           5. Mark execution as is_encrypted=true
           6. Cleanup env file + container
```

- Each backup execution tracks its encryption status (`is_encrypted` field)
- Restore operations auto-detect whether a backup is encrypted and use rclone to decrypt
- Existing unencrypted backups continue to work — no migration needed

> **Warning**: If you lose the encryption password, your encrypted backups cannot be recovered. Store it securely.

<!-- SCREENSHOT: S3 storage page showing encryption form with all options filled in -->
![Encryption Form](<!-- INSERT_SCREENSHOT_URL: encryption-form-detail.png -->)

---

### 3. Resource Backups

Coolify's built-in backup system only covers database dumps. Corelix Platform extends this to support **Docker volume snapshots**, **configuration exports**, and **full backups** for Applications, Services, and Databases.

#### Backup Types

| Type | What It Backs Up | Format |
|------|------------------|--------|
| **Volume** | All Docker named volumes and bind mounts for the resource | `tar.gz` per volume |
| **Configuration** | Resource model, environment variables, persistent storages, docker-compose, custom labels | `JSON` |
| **Full** | Both volume + configuration in one execution | `tar.gz` + `JSON` |
| **Coolify Instance** | Full `/data/coolify` directory (minus backups and metrics) | `tar.gz` |

#### Key Capabilities

- **Independent scheduling** — Each resource gets its own cron expression
- **Retention policies** — Limit by count, by age (days), or by storage destination
- **S3 upload** — Same pipeline as database backups, with optional encryption
- **Restore/Import** — Browse JSON backup contents, bulk-import environment variables, step-by-step restoration guide
- **Feature flag safety** — Queued jobs exit silently if the feature is disabled

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Application/Database/Service > Configuration** | "Resource Backups" sidebar item with backup manager |
| **Server > Resource Backups** | All resource backups for the server in one page |
| **Settings > Backup** | "Instance File Backup" section for Coolify directory backups |
| **Settings > Restore** | Browse and import configuration backups |

#### Backup Directory Structure

```
/data/coolify/backups/resources/{team-slug}-{team-id}/{resource-name}-{uuid}/
```

<!-- SCREENSHOT: Resource backup manager showing a scheduled backup with recent executions and download links -->
![Resource Backup Manager](<!-- INSERT_SCREENSHOT_URL: resource-backup-detail.png -->)

---

### 4. Custom Template Sources

Coolify ships with 200+ one-click service templates. Custom Template Sources lets you extend this list with templates from any GitHub repository — public or private.

#### How It Works

1. You point Corelix Platform at a GitHub repository containing YAML template files
2. The system fetches and validates templates using the same format as Coolify's built-in ones
3. Templates are cached locally and merged into the New Resource service list
4. Each template card shows a small source label so you know where it came from
5. A **source filter dropdown** lets you filter by All, Coolify Official, or a specific source

```
GitHub Repository
       |
       v
SyncTemplateSourceJob
  |-- List YAML files via GitHub Contents API
  |-- Download and parse metadata headers
  |-- Validate docker-compose structure
  |-- Cache to /data/coolify/custom-templates/{source-uuid}/templates.json
       |
       v
New Resource page --> get_service_templates()
  |-- Load built-in templates
  |-- Load custom source caches
  |-- Handle name collisions (built-in always wins)
  |-- Return merged collection with _source metadata
```

#### Adding a Source

**Via UI:** Go to **Settings > Templates** > click **+ Add Source** > enter repository URL, branch, folder path, and optional auth token > click **Save & Sync**.

**Via API:**

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Templates",
    "repository_url": "https://github.com/myorg/coolify-templates",
    "branch": "main",
    "folder_path": "templates/compose"
  }' \
  https://your-coolify.example.com/api/v1/template-sources
```

#### Template Format

Templates use the exact same YAML format as Coolify's built-in templates — a docker-compose file with comment metadata headers:

```yaml
# documentation: https://docs.example.com/
# slogan: A brief description of your service.
# tags: monitoring,devops
# category: monitoring
# logo: svgs/myservice.svg
# port: 8080

services:
  myservice:
    image: myorg/myservice:latest
    environment:
      - SERVICE_FQDN_MYSERVICE_8080
      - DATABASE_URL=${DATABASE_URL:?}
      - DEBUG=${DEBUG:-false}
    volumes:
      - myservice-data:/app/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 5s
      timeout: 20s
      retries: 10
```

See the full [Custom Template Creation Guide](docs/custom-templates.md) for metadata headers, magic environment variables, volume patterns, logos, and a complete example.

#### Source Filter

The New Resource page includes a **Filter by source** dropdown next to the existing category filter:

| Option | Shows |
|--------|-------|
| **All Sources** | All services (built-in + custom) |
| **Coolify Official** | Only Coolify's built-in templates |
| **\<Source Name\>** | Only templates from that specific source |

The dropdown only appears when at least one custom template source has been configured.

#### Key Behaviors

- **Write-once**: After deploying a service, the compose YAML lives in the database. Removing a source has zero impact on running services.
- **Name collisions**: Built-in templates always win. Custom templates with matching names get a `-{source-slug}` suffix.
- **Auto-sync**: Configurable cron schedule (default: every 6 hours). Manual sync also available.
- **Private repos**: Add a GitHub Personal Access Token for private repository access.
- **Rate limits**: Unauthenticated GitHub API: 60 req/hr. Authenticated: 5,000 req/hr.

<!-- SCREENSHOT: Settings > Templates page with an expanded source showing its template list -->
![Template Sources Expanded](<!-- INSERT_SCREENSHOT_URL: template-sources-expanded.png -->)

---

### 5. Enhanced Database Classification

Coolify classifies service containers as either `ServiceDatabase` or `ServiceApplication` based on a hardcoded image list. Many modern databases — Memgraph, Milvus, Qdrant, Cassandra, Neo4j, and dozens more — are missing from this list, causing them to be misclassified as applications. This breaks features like "Make Publicly Available", scheduled backups, database import, and backup UI visibility.

Corelix Platform solves this through three complementary mechanisms.

#### Mechanism 1: Expanded Image List

The `DATABASE_DOCKER_IMAGES` constant is expanded with ~50 additional database images, organized by category:

| Category | Databases Added |
|----------|----------------|
| **Graph** | Memgraph, Neo4j, ArangoDB, OrientDB, Dgraph, JanusGraph, Apache AGE |
| **Vector** | Milvus, Qdrant, Weaviate, ChromaDB |
| **Time-series** | QuestDB, TDengine, VictoriaMetrics, InfluxDB |
| **Document** | CouchDB, Couchbase, FerretDB, SurrealDB, RavenDB, RethinkDB |
| **Search** | Elasticsearch, OpenSearch, Meilisearch, Typesense, Manticore, Solr |
| **Key-value** | Valkey, Memcached |
| **Column-family** | Cassandra, ScyllaDB |
| **NewSQL** | CockroachDB, YugabyteDB, TiDB, Vitess |
| **OLAP** | Druid, Pinot, DuckDB |
| **Other** | EdgeDB, EventStoreDB, ImmuDB, Percona, FoundationDB, Hazelcast, Ignite |

These are automatically recognized when deploying one-click services — no configuration needed.

#### Mechanism 2: `coolify.database` Docker Label

For databases not in the expanded list (or to explicitly override classification), add the `coolify.database` label to your docker-compose services:

```yaml
services:
  my-custom-db:
    image: myorg/custom-database:latest
    labels:
      coolify.database: "true"
```

This works in:
- Custom template YAML files
- Any docker-compose file deployed as a Coolify service
- Per-service granularity (e.g., mark the database container but not the admin UI)

Set to `"false"` to force-classify a database image as an application:

```yaml
services:
  redis-cache:
    image: redis:7
    labels:
      coolify.database: "false"  # Treat as application, not database
```

The label check is case-insensitive and accepts `true/false/1/0/yes/no/on/off`.

#### Mechanism 3: `# type: database` Comment Convention

Template authors can add a `# type:` metadata header to classify all services in the template at once:

```yaml
# documentation: https://memgraph.com/docs
# slogan: Real-time graph database
# tags: graph,database,cypher
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    # ...
```

This injects `coolify.database: "true"` labels into all services in the compose YAML during parsing. Per-service labels take precedence over the template-level header, so multi-service templates can have mixed classifications:

```yaml
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    # Gets coolify.database=true from # type: database

  memgraph-lab:
    image: memgraph/lab:latest
    labels:
      coolify.database: "false"  # Override: this is a web UI, not a database
```

#### Wire-Compatible Backup Support

Databases that speak a standard protocol AND produce correct backups with standard dump tools are automatically mapped to their parent backup type:

| Database | Mapped To | Dump Tool | Backup UI | Import |
|----------|-----------|-----------|:---------:|:------:|
| **YugabyteDB** | PostgreSQL | `pg_dump` | Yes | Yes |
| **Apache AGE** | PostgreSQL | `pg_dump` | Yes | Yes |
| **TiDB** | MySQL | `mysqldump` | Yes | Yes |
| **Percona Server** | MySQL | `mysqldump` | Yes | Yes |
| **FerretDB** | MongoDB | `mongodump` | Yes | Yes |

Databases where standard tools fail (CockroachDB, Vitess, ScyllaDB) are intentionally **not** mapped. For these, use [Resource Backups](#3-resource-backups) for volume-level backups, or set `custom_type` on the service database if you know your setup is compatible.

#### Setting `custom_type` for Manual Override

If a database isn't automatically recognized or wire-compatible, you can set `custom_type` directly on the ServiceDatabase record to force a specific backup type:

```bash
# Via Coolify's database or tinker — set custom_type to a supported type
# Supported types: postgresql, mysql, mariadb, mongodb
```

This overrides all automatic detection and enables dump-based backups for that service.

#### Expanded Port Mapping ("Make Publicly Available")

The "Make Publicly Available" feature (database proxy) now supports all recognized database types with correct default ports. If a database type isn't in the built-in map, the system:

1. Looks up the base image name in an expanded port map (~50 entries)
2. Tries partial string matching for variants (e.g., `timescaledb-ha` matches `timescale`)
3. Extracts the port from the service's docker-compose configuration
4. Provides a helpful error message if all methods fail

#### Multi-Port Database Proxy

Some databases (e.g., Memgraph, Neo4j) expose multiple ports — one for database queries and others for admin UIs, log viewers, or alternate protocols. Coolify's built-in proxy only supports a single port. Corelix Platform adds multi-port proxy support via the `coolify.proxyPorts` Docker label.

**How to use:** Add the `coolify.proxyPorts` label to your service in the docker-compose template:

```yaml
services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
```

The label format is `"internalPort:label,internalPort:label,..."`. Each entry declares an internal container port and a human-readable label.

**What happens in the UI:**

When you view the service database in Coolify, instead of a single "Make Publicly Available" toggle, you'll see a port mapping table:

| Port | Label | Enabled | Public Port |
|------|-------|---------|-------------|
| 7687 | bolt | Toggle | 17687 |
| 7444 | log-viewer | Toggle | 17444 |

Each port can be independently enabled/disabled with its own public port number. The system creates a single nginx proxy container that handles all enabled ports.

**Key behaviors:**

- When the `coolify.proxyPorts` label is absent, the UI shows the stock single-port toggle (fully backward compatible)
- Port configurations are stored in the `proxy_ports` JSON column on the service database
- All enabled ports share one nginx proxy container with multiple `stream` server blocks
- Disabling all ports stops and removes the proxy container
- Public URLs for all enabled ports are displayed in the UI

#### What Works Automatically

| Feature | Recognized DBs (expanded list) | Label/Comment Override | Wire-Compatible | Other DBs |
|---------|:-----------------------------:|:---------------------:|:--------------:|:---------:|
| Correct classification | Yes | Yes | Yes | Via label |
| "Make Publicly Available" | Yes | Yes | Yes | Yes (port map) |
| Multi-port proxy | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label | Via `coolify.proxyPorts` label |
| Backup UI visible | — | — | Yes | Set `custom_type` |
| Dump-based backups | — | — | Yes | Set `custom_type` |
| Import UI visible | — | — | Yes | Set `custom_type` |
| Volume-level backups | Yes | Yes | Yes | Yes (Resource Backups) |

---

### 6. Network Management

Coolify's default networking puts all containers on a single flat `coolify` Docker network. This means every container can reach every other container by name — no isolation between environments, projects, or teams.

Corelix Platform adds per-environment Docker network isolation, a dedicated proxy network, shared cross-environment networks, and Docker Swarm overlay support.

#### Three Isolation Modes

| Mode | Behavior |
|------|----------|
| **`none`** | Manual only — no auto-created networks; users manage via UI/API |
| **`environment`** (default) | Each environment gets its own Docker bridge network (`ce-env-{uuid}`). Resources in the same environment communicate by container name. Cross-environment requires explicit shared networks |
| **`strict`** | Like `environment`, but also disconnects containers from the default `coolify` network. Only use when all services are properly assigned |

#### Proxy Network Isolation (Phase 2)

When enabled, a dedicated proxy network (`ce-proxy-{server_uuid}`) separates the reverse proxy from internal services. Only resources with FQDNs join it. This prevents Traefik/Caddy from having network-level access to backend databases and internal services.

The system automatically injects `traefik.docker.network` (and `caddy_ingress_network`) labels for **all** resource types — Dockerfile/Nixpacks applications, Docker Compose applications, and services — preventing intermittent 502 errors in multi-network setups. If you set `traefik.docker.network` yourself (raw compose or label overrides), your value always wins.

The reverse proxy is connected to the proxy network automatically the first time an FQDN resource is reconciled — no proxy restart required.

#### Docker Swarm Support (Phase 3)

For Swarm clusters, the system automatically:
- Creates overlay networks (instead of bridge) with `--attachable` flag
- Uses `docker service update --network-add` for network assignment (Swarm tasks can't use `docker network connect`)
- Batches network changes into single service updates to minimize rolling restarts
- Optionally enables IPsec encryption between Swarm nodes via `CORELIX_SWARM_OVERLAY_ENCRYPTION=true`

> **Note:** `docker network inspect` is node-local — on a Swarm cluster it only lists tasks scheduled on the node you queried. Seeing fewer members than expected on one manager is normal, not a sign of drift.

#### Persistent Membership & Self-Healing

Runtime `docker network connect` is ephemeral — Docker drops the attachment on every container recreation (redeploy, `docker compose up`, server reboot). Network Management now closes that gap two ways:

- **Compose-level persistence** — for Docker Compose applications and services, each managed network is written into the compose file itself as an `external: true` network, so Docker reattaches it automatically on every recreation. Only networks already verified to exist on the host are written in, so a first-ever deploy (before the network exists) can never fail — the post-deploy reconcile creates the network and the *next* deploy persists it. Standalone databases don't have a compose file to persist into, so they continue to rely on runtime connect plus the self-heal below.
- **Scheduled self-heal** — every `CORELIX_NETWORK_MEMBERSHIP_INTERVAL` minutes (default 5), a background job re-verifies every resource's network membership against live Docker, reconnects anything that drifted (e.g. after a server reboot), and corrects the network status shown in the UI if it no longer matches reality.

This also fixes a bug where the UI could show a resource as "connected" to a network after a redeploy or reboot even though `docker network inspect` showed nothing — the underlying `resource_networks` record is now re-verified rather than trusted at face value.

#### Proxy Reachability for Multi-Homed Backends

Persisting membership (above) means an FQDN backend can end up multi-homed on both the default `coolify` network and its environment network. In the default configuration (proxy isolation off), the reverse proxy is now automatically kept attached to every active environment network on the server — both as part of its own network configuration (so this survives proxy restarts) and immediately the moment a resource is attached during reconcile. This closes a bug where the proxy could become slow/unresponsive for the whole stack until manually restarted, if a resource was ever adopted onto its environment network without a fresh deploy (e.g. via the scheduled self-heal above, "Reconcile Existing Resources", or enabling network management on an already-running resource). No action is required — this is automatic and no proxy restart is ever needed. Proxy isolation mode is unaffected: environment networks are intentionally not added to the proxy when isolation is enabled.

#### How It Works

```
Resource deployed normally by Coolify
     |
     v
ApplicationDeploymentQueue status → 'finished'
     |
     v
NetworkReconcileJob triggered
     |
     v
1. Ensure environment network exists (ce-env-{uuid})
2. Connect all containers to environment network
3. If resource has FQDN + proxy isolation: ensure proxy network,
   connect the reverse proxy and the resource containers to it
4. If strict mode: disconnect from default 'coolify' network
   (FQDN containers only after verified proxy-network membership
   and a non-default traefik.docker.network label)
5. Update resource_networks pivot table
```

This post-deployment hook approach avoids overlaying Coolify's 4,130-line `ApplicationDeploymentJob.php`.

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `CORELIX_NETWORK_MANAGEMENT` | `false` | Enable/disable network management |
| `CORELIX_NETWORK_ISOLATION` | `environment` | Isolation mode: `none`, `environment`, `strict` (`CORELIX_NETWORK_ISOLATION_MODE` also supported) |
| `CORELIX_PROXY_ISOLATION` | `false` | Enable dedicated proxy network |
| `CORELIX_SWARM_OVERLAY_ENCRYPTION` | `false` | Enable IPsec for Swarm overlay networks |
| `CORELIX_MAX_NETWORKS` | `200` | Max managed networks per server (iptables safety limit) |
| `CORELIX_NETWORK_PERSIST_IN_COMPOSE` | `true` | Persist managed-network membership into the compose file (`external: true` network) so it survives redeploys/reboots for compose apps and services |
| `CORELIX_NETWORK_MEMBERSHIP_INTERVAL` | `5` | Minutes between scheduled membership self-heal runs (re-verifies + reconnects drifted containers, corrects stale "connected" status) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Server > Networks** | Network management with create/delete/sync, **Reconcile Existing Resources**, Docker networks tab |
| **Resource > Configuration > Networks** | Per-resource network assignment and disconnection |
| **Settings > Networks** | Global network policy configuration |

If network management is enabled on a server with pre-existing deployments, run **Reconcile Existing Resources** once from **Server > Networks** to adopt already-running resources into managed networks.

#### Proxy Migration Workflow

1. Set `CORELIX_PROXY_ISOLATION=true` in your environment
2. Go to **Server > Networks** and click **Run Proxy Migration**
3. This creates the proxy network, connects the proxy container, and connects all FQDN resources
4. Redeploy all resources (so they get `traefik.docker.network` labels)
5. Optionally click **Cleanup Old Networks** to disconnect the proxy from non-proxy networks

### 7. MCP Server

See [mcp-server/README.md](mcp-server/README.md) for detailed information.

---

### 8. Cluster Management

Coolify v4 has experimental Docker Swarm support but lacks any cluster management or monitoring UI. Corelix Platform adds a comprehensive cluster dashboard, node management, service/task monitoring, a dual-view visualizer, Swarm secrets and configs management, and a structured deployment configuration form — all behind a K8s-ready orchestrator abstraction layer.

#### What It Provides

| Capability | Description |
|------------|-------------|
| **Cluster Dashboard** | Status cards (health, nodes, services, tasks), resource usage, full node listing table with auto-refresh |
| **Node Management** | Drain/Activate/Pause availability, Promote/Demote role, Remove node, Label CRUD, Add Node wizard with join command |
| **Service/Task Viewer** | Table of all Swarm services with inline task expansion — click a service to see its tasks per node, status, and errors |
| **Cluster Visualizer** | Dual view toggle — **Grid View** (Portainer-style columns per node with color-coded task blocks) and **Topology View** (interactive node hierarchy with manager/worker relationships) |
| **Swarm Secrets** | Create and remove Docker secrets with local metadata tracking |
| **Swarm Configs** | Create, view content, and remove Docker configs |
| **Structured Swarm Config** | Replaces Coolify's raw YAML textarea with a form: mode (replicated/global), replicas, update/rollback policy, placement constraints, resource limits, health check, restart policy |
| **Event Log** | Real-time stream of Swarm events (service updates, node status changes, task rescheduling) with type/action filters and configurable retention |
| **Auto-detection** | Discovers Swarm clusters from existing manager servers; auto-links workers by IP matching |
| **Status Notifications** | Alerts when clusters degrade (nodes down) or become unreachable, and recovery notifications |
| **MCP Tools** | 20 AI tools for cluster management via the MCP server |

#### Architecture

```
UI (Livewire Components)
    ↓
Cluster::driver()
    ↓
ClusterDriverInterface (Contract)
    ↓
SwarmClusterDriver (SSH → docker CLI → JSON parse → cached results)
    ↓
Future: KubernetesClusterDriver (kubectl / K8s API)
```

The orchestrator abstraction layer means Kubernetes support can be added later by implementing `ClusterDriverInterface` — no UI or business logic changes needed.

#### How It Works

```
1. Auto-detect: Scan team's servers for Swarm managers via `docker info`
2. Create Cluster record (uuid, name, type, team-scoped)
3. Sync metadata: node count, manager count, join tokens (encrypted), service count
4. Background jobs: ClusterSyncJob (metadata refresh), ClusterEventCollectorJob (event stream)
5. Dashboard: Real-time view via Livewire polling with configurable cache TTL
```

All Docker commands execute via SSH through Coolify's `instant_remote_process()`. JSON output format (`--format '{{json .}}'`) ensures reliable parsing. All interpolated values pass through `escapeshellarg()` to prevent command injection.

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `CORELIX_CLUSTER_MANAGEMENT` | `false` | Enable cluster management feature |
| `CORELIX_CLUSTER_SYNC_INTERVAL` | `60` | Metadata sync interval (seconds) |
| `CORELIX_CLUSTER_CACHE_TTL` | `30` | Docker API response cache TTL (seconds) |
| `CORELIX_CLUSTER_EVENT_RETENTION` | `7` | Event log retention (days) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Sidebar > Clusters** | Card grid of team's clusters with status indicators and metadata |
| **Cluster > Overview** | Dashboard with status cards, node listing table |
| **Cluster > Nodes** | Node management with action dropdowns and label editor |
| **Cluster > Services** | Service table with inline task expansion, scaling, rollback |
| **Cluster > Visualizer** | Dual-view task placement visualization |
| **Cluster > Events** | Filterable event log with type/action filters |
| **Cluster > Secrets** | Docker secret management |
| **Cluster > Configs** | Docker config management with content viewer |
| **Application > Swarm** | Structured deployment config (when cluster management enabled) |
| **Any Swarm resource** | Inline task status widget showing Swarm task distribution |

#### Key Design Decisions

| Decision | Why |
|----------|-----|
| Orchestrator abstraction (`ClusterDriverInterface`) | K8s-ready without rewrite |
| SSH-based Docker CLI (not Docker API socket) | Consistent with Coolify's pattern |
| Explicit Cluster model (not implicit grouping) | Supports naming, settings, multi-type |
| Livewire polling (not WebSocket) | Consistent with Coolify; no extra infra |
| Cached with configurable TTL | Prevents SSH storm on dashboards |
| Team-scoped clusters | Inherits Coolify's multi-tenancy |

Cluster Management is available in the pro distribution. In the public mirror, it is presented as an upsell path toward the Corelix managed platform or an enterprise self-hosted license.

### 9. Enhanced UI Theme

Optional corporate-grade themes selectable via **Settings > Appearance**. **CSS-only** — no DOM changes; same layout and Tailwind framework. Themes are instance-wide (admin-controlled); fonts are self-hosted (WOFF2 bundled in Docker image).

**Available themes:**
- **Default (Coolify)** — stock Coolify styling (no theme applied)
- **Enhanced (Linear)** — deep neutrals, crisp borders, restrained accent usage; inspired by Linear
- **TailAdmin** — clean enterprise dashboard with brand blues, warm grays, Outfit font; inspired by TailAdmin

Preference is stored in the database; reload the page after changing themes to see updates.

**Adding custom themes:** Create a CSS file in `resources/assets/themes/`, add an entry to the `ui_theme.themes` array in `config/corelix-io-platform.php`, self-host any fonts in `resources/assets/themes/fonts/`, and rebuild the Docker image.

See [Enhanced UI Theme](docs/features/enhanced-ui-theme/README.md) for feature overview, and [PRD](docs/features/enhanced-ui-theme/PRD.md) / [plan](docs/features/enhanced-ui-theme/plan.md) for full implementation details.

### 10. Additional Build Types

Coolify supports Nixpacks, Dockerfile, Docker Compose, and Static builds out of the box. Corelix Platform adds three additional build options:

| Type | Description |
|------|-------------|
| **Railpack** | Railway's buildpack; **native** in Coolify v4.1.x (ships in the base image, not provided by this addon) |
| **Heroku Buildpacks** | Cloud Native Buildpacks via `heroku/builder:24`; Node, Python, Ruby, Go, PHP, Java |
| **Paketo Buildpacks** | Cloud Native Buildpacks via `paketobuildpacks/builder-jammy-base`; broad language support |

All three appear in the Build Pack dropdown on the application General settings page and when creating new resources from public Git, GitHub private, or GitHub deploy-key repositories. No configuration changes required — select the build type and deploy.

See [Additional Build Types](docs/features/additional-build-types/) for full documentation.

### 11. Traefik Label Overrides

Coolify auto-generates Traefik routing labels for every container in a service template. Corelix Platform lets you override or extend any of those labels per container — without converting to raw Docker Compose.

#### How It Works

The override is applied inside `serviceParser()`'s own `$resource->save()` call via an Eloquent `updating` observer on the `Service` model. No overlay of `parsers.php` is needed. When `serviceParser` freshly computes `docker_compose`, the observer runs `LabelOverrideService::applyToService()`, which deep-merges user-supplied labels over the auto-generated set. The result is written to `docker_compose` before it is persisted.

#### Merge Strategy

| Rule | Behavior |
|------|----------|
| **User wins** | For any label key present in both auto-generated and user-supplied sets, the user value wins |
| **Additive** | User-supplied keys that Coolify doesn't generate are appended as-is |
| **`coolify.*` filtered** | Keys prefixed with `coolify.` are silently dropped from user input to protect internal monitoring labels |
| **Format preserved** | Coolify uses list format (`["key=value"]`); the service converts to map for merging and back to list format |

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `FEATURE_TRAEFIK_LABEL_OVERRIDES` | `true` (Pro) | Enable Traefik label overrides (Pro feature) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Service > Index > Advanced** | Collapsible YAML textarea per container for label overrides |
| **REST API** | `/api/v1/service-applications/{uuid}/label-overrides` and `/api/v1/service-databases/{uuid}/label-overrides` |

#### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/service-applications/{uuid}/label-overrides` | Get current overrides for a service application |
| `PUT` | `/api/v1/service-applications/{uuid}/label-overrides` | Set overrides (body: `{"yaml": "traefik.enable: \"true\""}`) |
| `DELETE` | `/api/v1/service-applications/{uuid}/label-overrides` | Clear all overrides for a service application |
| `GET` | `/api/v1/service-databases/{uuid}/label-overrides` | Get current overrides for a service database |
| `PUT` | `/api/v1/service-databases/{uuid}/label-overrides` | Set overrides |
| `DELETE` | `/api/v1/service-databases/{uuid}/label-overrides` | Clear all overrides for a service database |

Traefik Label Overrides is available in the Pro distribution. In the public mirror, a subtle upsell card appears where the override textarea would be.

See [Traefik Label Overrides](docs/features/traefik-label-overrides/) for full documentation.

### 12. Docker Registry Management

Coolify v4 requires per-server manual `docker login` for private registries. Corelix Platform adds team-level Docker registry management — define registries once and have credentials automatically synced to all servers. Deployment jobs continue to work unchanged by leveraging Coolify's existing `config.json` mounting.

#### Supported Providers

| Provider | Authentication | Notes |
|---------|----------------|-------|
| **Docker Hub** | Username + password or token | Standard and legacy token support |
| **GHCR** | GitHub PAT or OAuth token | For `ghcr.io` repositories |
| **GitLab** | Deploy token or registry token | GitLab Container Registry |
| **AWS ECR** | IAM role or access keys | Automatic 12-hour token refresh via AWS SDK |
| **Quay** | Robot account or OAuth token | Red Hat Quay |
| **Azure ACR** | Service principal or admin credentials | Azure Container Registry |
| **Custom** | Username/password or token | Any Docker Registry V2–compatible registry |

#### What It Provides

| Capability | Description |
|------------|-------------|
| **Centralized management** | Add, edit, and remove registries from **Settings > Registries** |
| **Auto-sync to servers** | Credentials synced to all team servers via `docker login` over SSH |
| **Connection testing** | Validate credentials before saving via Docker Registry V2 API |
| **ECR token refresh** | Background job refreshes ECR tokens every 12 hours (IAM or access keys) |
| **Per-server status** | **Server > Registries** sidebar shows sync status per server |
| **REST API** | Full CRUD, plus `POST /test` and `POST /sync` endpoints for automation |
| **MCP tools** | AI assistant integration for listing, creating, testing, and syncing registries |
| **Zero deployment changes** | No modifications to deployment jobs — uses Coolify's existing `config.json` mounting |

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `FEATURE_DOCKER_REGISTRY_MANAGEMENT` | `true` (Pro) | Enable Docker registry management (Pro feature) |

#### Where to Find It

| Location | What You See |
|----------|-------------|
| **Settings > Registries** | Add, edit, test, and delete team-level registries |
| **Server > Registries** | Sync status per registry; manual sync button |
| **REST API** | `/api/v1/registries` for CRUD, test, and sync |
| **MCP tools** | `coolify_registry_list`, `coolify_registry_create`, `coolify_registry_test`, etc. |

#### Key Design Decisions

| Decision | Why |
|----------|-----|
| Team-level scope | Registries are shared across the team; no per-project/per-environment complexity |
| Sync via `docker login` | Reuses Coolify's existing SSH and config.json flow; no deployment job overlays |
| ECR background refresh | ECR tokens expire in 12 hours; `EcrTokenRefreshJob` keeps credentials valid |
| Provider-specific forms | Each provider has tailored fields (region for ECR, registry URL for custom, etc.) |

See the full [Docker Registry Management documentation](docs/features/docker-registry-management/) for PRD, implementation plan, and feature overview.

### 13. DNS Provider Management

Automatic DNS records and ingress for your deployed resources. Configure a DNS provider and a domain once — every application FQDN under that domain then resolves and routes automatically, with no per-deploy DNS work.

#### How to use it

1. Set `CORELIX_DNS_PROVIDER_MANAGEMENT=true` and restart
2. Go to **Settings > DNS Providers**, add a Cloudflare Tunnel provider (API token with `Account:Cloudflare Tunnel:Edit`, `Zone:DNS:Edit`, `Zone:Zone:Read`), and test the connection
3. Add a managed domain (e.g. `apps.example.com`) — Corelix creates the tunnel, the wildcard + apex DNS records, and deploys the `cloudflared` daemon on your server
4. Deploy resources with FQDNs under that domain — they are routed automatically

| Capability | What it does |
|------------|--------------|
| **Managed `cloudflared`** | Corelix deploys and owns the tunnel daemon container — no host SSH or manual tunnel setup |
| **Automatic reconcile** | FQDN create/change/remove and deployment completion trigger background reconciliation |
| **Status everywhere** | Links dropdown shows per-hostname status dots; a per-resource **Domains** panel shows details and a resync button |
| **Wildcard happy path** | Resources under a wildcard-mode domain need zero provider API calls per deploy |
| **Non-destructive** | Coolify stays the owner of the FQDN; disabling the feature restores stock behavior, persisted data stays inert |

#### Pro capabilities

| Capability | What it adds |
|------------|--------------|
| **Multi-domain** | Multiple providers/domains per team; pin a resource to a specific domain |
| **Per-hostname routing** | `per_hostname` and `hybrid` modes — individual proxied CNAMEs instead of (or alongside) the wildcard |
| **Environment bindings** | Route production vs. non-production environments to different domains automatically |
| **TCP records** | Publicly exposed standalone databases get DNS-only A/AAAA records to the server IP |
| **Drift sync & health** | Scheduled provider sync, drift detection (`alert_only` or `reconcile` — never deletes), orphan adopt-or-ignore, DNS health dashboard |
| **Access policies** | Per-domain Cloudflare Access (Zero Trust) template — allowed email domains/emails + session duration, applied to every managed hostname (off by default) |

#### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `CORELIX_DNS_PROVIDER_MANAGEMENT` | `false` | Enable DNS provider management |
| `CORELIX_DNS_MANAGE_CLOUDFLARED` | `true` | Let Corelix deploy and manage the `cloudflared` daemon (set `false` to self-run) |
| `CORELIX_DNS_CLOUDFLARED_IMAGE` | `cloudflare/cloudflared:latest` | Pinnable cloudflared image (digest recommended in production) |
| `CORELIX_DNS_SYNC_INTERVAL` | `15` | Drift sync interval in minutes (Pro; clamped 5–1440) |
| `CORELIX_DNS_DRIFT_POLICY` | `alert_only` | On detected drift: `reconcile` (re-apply) or `alert_only` — never auto-deletes |

See the full [DNS Provider Management documentation](docs/features/dns-provider-management/) for PRD, implementation plan, and feature overview.

---

## Editions (Free & Pro)

Corelix Platform is available in two editions, both built from the same codebase:

| Edition | Description | Distribution |
|---------|-------------|--------------|
| **Free (Community)** | All core enhanced features. No pro code ships — it's stripped at build time, not just disabled | Public `corelix-platform` repository on GitHub |
| **Pro** | Everything in Free, plus advanced operational features | Private repository, GHCR image |

### Feature Split

**Free Tier** (12 features — ship in both editions):

| Feature | Description |
|---------|-------------|
| Granular Permissions | Project-level and environment-level access control |
| Encrypted S3 Backups | Per-storage NaCl SecretBox encryption for backups |
| Resource Backups | Volume, configuration, full, and instance backups |
| Custom Template Sources | GitHub repos as external template sources |
| Enhanced Database Classification | 50+ DB images, `coolify.database` label, multi-port proxy |
| Network Management | Per-environment Docker network isolation |
| Proxy Isolation | Dedicated proxy network for FQDN-only routing |
| Swarm Overlay Encryption | IPsec encryption for Swarm overlay networks |
| MCP Enhanced Tools | AI-driven infrastructure management tools |
| Enhanced UI Theme | Multiple selectable themes (Linear, TailAdmin) |
| Additional Build Types | Railpack, Heroku Buildpacks, Paketo Buildpacks |
| DNS Provider Management | Automatic DNS + ingress (Cloudflare Tunnel) for deployed resources, managed `cloudflared`, per-resource Domains panel, audit hooks *(disabled by default via `CORELIX_DNS_PROVIDER_MANAGEMENT`)* |

**Pro Tier** (11 features — stripped from free builds):

| Feature | Description | Status |
|---------|-------------|--------|
| Cluster Management | Swarm dashboard, node management, visualizer, secrets/configs | Available |
| Whitelabeling | Build-time brand replacement, runtime URL overrides | Available |
| Docker Registry Management | Team-level registry management with provider-specific auth (Docker Hub, GHCR, GitLab, ECR, Quay, Azure ACR, Custom); auto-sync to servers; ECR token refresh; Settings > Registries; MCP tools | Available |
| Traefik Label Overrides | Per-container override of auto-generated Traefik labels; YAML textarea per container; Eloquent observer hook; user labels win; `coolify.*` protected | Available |
| DNS Multi-Domain | Multiple DNS providers and managed domains per team; per-resource domain pinning | Available |
| DNS Per-Hostname Routing | `per_hostname` and `hybrid` routing modes (individual CNAMEs instead of wildcard) | Available |
| DNS Environment Bindings | Bind managed domains to specific environments or roles (production / non-production) | Available |
| DNS TCP Records | Direct A/AAAA records for publicly exposed standalone databases | Available |
| DNS Drift Sync & Health | Scheduled provider sync, drift detection (alert or auto-reconcile, never delete), orphan adopt-or-ignore, DNS health dashboard | Available |
| DNS Access Policies | Per-domain Cloudflare Access (Zero Trust) policy template applied to managed hostnames (allowed email domains/emails, session duration); default off | Available |
| Audit Trail | Audit trail with export/retention for all user actions | Planned |

### How It Works

The feature flag system uses **compile-time stripping** — pro code is physically removed from the free edition, not just disabled at runtime. There are no license checks, no phone-home, and no hidden endpoints.

- **Feature registry** — All features are declared in `config/features.php` with metadata (tier, category, description)
- **`Feature` helper** — PHP: `Feature::enabled('CLUSTER_MANAGEMENT')`. Blade: `@feature('CLUSTER_MANAGEMENT')`. JS: `window.__FEATURES__.CLUSTER_MANAGEMENT`
- **Route middleware** — Pro API endpoints return HTTP 402 with structured JSON: `{"error": "premium_feature", "feature": "...", "upgrade_url": "..."}`
- **Upsell cards** — Where pro features would appear in the free edition, a subtle card shows the feature name, description, and an upgrade link
- **API endpoint** — `GET /api/v1/features` returns the current edition, enabled features, and upgrade URL

Each feature flag can be overridden via `FEATURE_<KEY>` environment variables. The edition is identified by `CORELIX_EDITION` (default: `pro` in private builds, `free` in public builds).

### Repository Model

- **Private canonical source** — `corelix-io/platform` is the full source-of-truth repo for free + pro development.
- **Public community mirror** — `corelix-io/platform` is a CI-managed free-edition mirror for installs, issues, discussions, and public contributions.
- **Public history model** — The public repo is published as fresh snapshots from the stripped free tree, so it does not expose private/pro commit history.
- **Public release history** — `CHANGELOG.md` is the human-readable record of approved public changes.

### Simulating a Free Build Locally (Canonical Private Repo)

This workflow is for maintainers working in the canonical private repository. The public mirror is CI-published and does not include the private free-build pipeline helpers.

```bash
./scripts/build-free.sh           # Strip + validate
./scripts/build-free.sh --docker  # Also build Docker image
```

See the [Feature Flag Gating documentation](docs/features/feature-flag-gating/) for full technical details.

---

## Installation

### Requirements

- Coolify v4.x running on your server (overlays and Docker base image are validated against upstream **v4.1.2**, pinned in `docker/Dockerfile`)
- Docker & Docker Compose
- Root/sudo access

### Coolify base version pinning

The Corelix Platform Docker image pulls a **specific** Coolify base, never `latest`. The current pin is `4.1.2` (`docker/Dockerfile` `ARG COOLIFY_VERSION=4.1.2`). The same value is set in CI (`.github/workflows/docker-build-publish.yml` and `build-free-edition.yml`).

This guarantees the overlay files in `src/Overrides/` always match the helpers, signatures, and routes the base image expects. Bumping the pin requires re-running the upstream sync skill (`.claude/skills/sync-coolify-source/SKILL.md`) in the **same** PR — overlays must move together with the base. Local override is supported via `--build-arg COOLIFY_VERSION=...` for testing only.

### Interactive Setup

```bash
git clone https://github.com/corelix-io/coolify-enhanced.git
cd coolify-enhanced
sudo bash install.sh
```

The interactive menu provides:

1. **Install Coolify** — from the official repository (fresh servers)
2. **Install Enhanced Addon** — add the addon to existing Coolify
3. **Uninstall Enhanced Addon** — cleanly remove the addon
4. **Check Status** — verify what's installed and running
5. **Full Setup** — Coolify + addon in one step

### CLI Arguments (Automation / CI)

```bash
# Fresh server: install everything
sudo bash install.sh --install-coolify --install-addon --unattended

# Existing Coolify: just the addon
sudo bash install.sh --install-addon

# Local build instead of pulling from GHCR
sudo bash install.sh --install-addon --local

# Check installation
sudo bash install.sh --status

# Uninstall
sudo bash install.sh --uninstall
```

Run `sudo bash install.sh --help` for all options.

### Build Locally

```bash
docker build \
  --build-arg COOLIFY_VERSION=latest \
  -t coolify-enhanced:latest \
  -f docker/Dockerfile .
```

### Image Tags

| Tag | Description |
|-----|-------------|
| `latest` | Latest stable release |
| `vX.Y.Z` | Specific release version |
| `coolify-X.Y.Z` | Built against specific Coolify version |
| `sha-XXXXXX` | Specific commit SHA |

### What the Installer Does

1. Verifies Coolify at `/data/coolify/source/`
2. Pulls the pre-built image from GHCR (or builds locally)
3. Creates `docker-compose.custom.yml` with the enhanced image + env var
4. Sets `CORELIX_PLATFORM=true` in `.env`
5. Restarts Coolify via `upgrade.sh`
6. Verifies the installation

For detailed instructions including manual install, database migrations, and troubleshooting, see the [Installation Guide](docs/installation.md).

---

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `CORELIX_PLATFORM` | `false` | Master switch — enable/disable all enhanced features |
| `CORELIX_RCLONE_IMAGE` | `rclone/rclone:latest` | Docker image for rclone operations |
| `CORELIX_TEMPLATE_SYNC_FREQUENCY` | `0 */6 * * *` | Cron expression for auto-syncing template sources (empty to disable) |
| `CORELIX_TEMPLATE_SYNC_ON_STARTUP` | `true` | Auto-sync enabled template sources when cached template files are missing (for example after restart) |
| `CORELIX_TEMPLATE_CACHE_DIR` | `storage/app/custom-templates` | Cache directory for fetched templates |
| `CORELIX_NETWORK_MANAGEMENT` | `false` | Enable per-environment Docker network isolation |
| `CORELIX_PROXY_ISOLATION` | `false` | Enable dedicated proxy network (requires network management) |
| `CORELIX_NETWORK_ISOLATION` | `environment` | Isolation mode: `none`, `environment`, or `strict` (`CORELIX_NETWORK_ISOLATION_MODE` also supported) |
| `CORELIX_MAX_NETWORKS` | `200` | Maximum managed networks per server |
| `CORELIX_SWARM_OVERLAY_ENCRYPTION` | `false` | Enable IPsec for Swarm overlay networks |
| `CORELIX_NETWORK_PERSIST_IN_COMPOSE` | `true` | Persist managed-network membership into compose apps/services so it survives redeploys/reboots |
| `CORELIX_NETWORK_MEMBERSHIP_INTERVAL` | `5` | Minutes between scheduled network membership self-heal runs |
| `CORELIX_CLUSTER_MANAGEMENT` | `false` | Enable Docker Swarm cluster management dashboard |
| `CORELIX_CLUSTER_SYNC_INTERVAL` | `60` | Cluster metadata sync interval in seconds |
| `CORELIX_CLUSTER_CACHE_TTL` | `30` | Docker API response cache TTL in seconds |
| `CORELIX_CLUSTER_EVENT_RETENTION` | `7` | Cluster event log retention in days |
| `CORELIX_DNS_PROVIDER_MANAGEMENT` | `false` | Enable DNS provider management (automatic DNS + ingress) |
| `CORELIX_DNS_MANAGE_CLOUDFLARED` | `true` | Let Corelix deploy/manage the `cloudflared` daemon |
| `CORELIX_DNS_CLOUDFLARED_IMAGE` | `cloudflare/cloudflared:latest` | Pinnable cloudflared image |
| `CORELIX_DNS_SYNC_INTERVAL` | `15` | DNS drift sync interval in minutes (Pro; clamped 5–1440) |
| `CORELIX_DNS_DRIFT_POLICY` | `alert_only` | Drift handling: `reconcile` or `alert_only` (never deletes) |
| `CORELIX_PLATFORM_UI_THEME` | — | Default theme slug when no DB value exists (e.g., `enhanced`, `tailadmin`); acts as fallback for Settings > Appearance |
| `CORELIX_EDITION` | `pro` | Build edition identifier (`free` or `pro`) |
| `CORELIX_UPGRADE_URL` | `https://corelix.io/pricing` | Upgrade link shown in upsell cards and API responses |
| `FEATURE_DOCKER_REGISTRY_MANAGEMENT` | `true` (Pro) | Enable Docker registry management (Pro feature) |
| `FEATURE_<KEY>` | `true` | Per-feature override (e.g., `FEATURE_CLUSTER_MANAGEMENT=false` to disable) |

> For backward compatibility, `CORELIX_GRANULAR_PERMISSIONS=true` also enables the addon.

### Config File

Publish for customization:

```bash
php artisan vendor:publish --tag=corelix-io-platform-config
```

This creates `config/corelix-io-platform.php` with options for permission levels, bypass roles, cascade behavior, auto-grant settings, encryption image, and template source limits.

### Feature Flag Behavior

| State | Behavior |
|-------|----------|
| **Enabled** (`CORELIX_PLATFORM=true`) | All features active — permissions enforced, encryption available, templates loaded, cluster management available |
| **Disabled** (`CORELIX_PLATFORM=false`) | Standard Coolify behavior — all team members have full access, no encryption, no custom templates, no cluster dashboard |

Permission and encryption settings are preserved in the database when disabled. Re-enabling restores them instantly.

---

## API Reference

All endpoints require Bearer token authentication (Laravel Sanctum).

### Permissions API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/permissions/project` | List project permissions |
| `GET` | `/api/v1/permissions/project/{id}` | Get specific permission |
| `POST` | `/api/v1/permissions/project` | Grant project access |
| `PUT` | `/api/v1/permissions/project/{id}` | Update permission level |
| `DELETE` | `/api/v1/permissions/project/{id}` | Revoke access |
| `POST` | `/api/v1/permissions/project/bulk` | Grant access to all team members |
| `DELETE` | `/api/v1/permissions/project/bulk/{uuid}` | Revoke all project access |
| `GET` | `/api/v1/permissions/environment` | List environment overrides |
| `POST` | `/api/v1/permissions/environment` | Create environment override |
| `DELETE` | `/api/v1/permissions/environment/{id}` | Remove environment override |

### Template Sources API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/template-sources` | List all template sources |
| `POST` | `/api/v1/template-sources` | Create a new source |
| `GET` | `/api/v1/template-sources/{uuid}` | Get source details |
| `PUT` | `/api/v1/template-sources/{uuid}` | Update a source |
| `DELETE` | `/api/v1/template-sources/{uuid}` | Delete a source |
| `POST` | `/api/v1/template-sources/{uuid}/sync` | Trigger sync for one source |
| `POST` | `/api/v1/template-sources/sync-all` | Trigger sync for all sources |

### Resource Backups API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/resource-backups` | List resource backup schedules |
| `POST` | `/api/v1/resource-backups` | Create a backup schedule |
| `GET` | `/api/v1/resource-backups/{id}` | Get schedule details |
| `PUT` | `/api/v1/resource-backups/{id}` | Update a schedule |
| `DELETE` | `/api/v1/resource-backups/{id}` | Delete a schedule |

### Network Management API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/networks/{serverUuid}` | List managed networks for a server |
| `POST` | `/api/v1/networks/{serverUuid}` | Create a shared network |
| `GET` | `/api/v1/networks/{serverUuid}/{networkUuid}` | Get network details + Docker info |
| `DELETE` | `/api/v1/networks/{serverUuid}/{networkUuid}` | Delete a managed network |
| `POST` | `/api/v1/networks/{serverUuid}/sync` | Sync networks from Docker |
| `POST` | `/api/v1/networks/{serverUuid}/proxy/migrate` | Run proxy isolation migration |
| `POST` | `/api/v1/networks/{serverUuid}/proxy/cleanup` | Disconnect proxy from non-proxy networks |
| `GET` | `/api/v1/networks/resource/{type}/{uuid}` | List networks for a resource |
| `POST` | `/api/v1/networks/resource/{type}/{uuid}/attach` | Attach a resource to a network |
| `DELETE` | `/api/v1/networks/resource/{type}/{uuid}/{networkUuid}` | Detach a resource from a network |

### Features API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/features` | List edition, enabled features, and upgrade URL |

### Docker Registry Management API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/registries` | List team registries |
| `POST` | `/api/v1/registries` | Create registry |
| `GET` | `/api/v1/registries/{uuid}` | Get registry details |
| `PUT` | `/api/v1/registries/{uuid}` | Update registry |
| `DELETE` | `/api/v1/registries/{uuid}` | Delete registry |
| `POST` | `/api/v1/registries/{uuid}/test` | Test registry connection |
| `POST` | `/api/v1/registries/{uuid}/sync` | Sync registry to servers |
| `GET` | `/api/v1/registries/{uuid}/servers` | Get per-server sync status |

### Cluster Management API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/clusters` | List team's clusters |
| `POST` | `/api/v1/clusters` | Create cluster |
| `GET` | `/api/v1/clusters/{uuid}` | Get cluster details |
| `PATCH` | `/api/v1/clusters/{uuid}` | Update cluster settings |
| `DELETE` | `/api/v1/clusters/{uuid}` | Delete cluster |
| `POST` | `/api/v1/clusters/{uuid}/sync` | Force metadata sync |
| `GET` | `/api/v1/clusters/{uuid}/nodes` | List nodes |
| `POST` | `/api/v1/clusters/{uuid}/nodes/{id}/action` | Node action (drain/activate/promote/demote) |
| `DELETE` | `/api/v1/clusters/{uuid}/nodes/{id}` | Remove node |
| `GET` | `/api/v1/clusters/{uuid}/services` | List services |
| `GET` | `/api/v1/clusters/{uuid}/services/{id}/tasks` | Get service tasks |
| `POST` | `/api/v1/clusters/{uuid}/services/{id}/scale` | Scale service |
| `POST` | `/api/v1/clusters/{uuid}/services/{id}/rollback` | Rollback service |
| `GET` | `/api/v1/clusters/{uuid}/events` | Get cluster events |
| `GET` | `/api/v1/clusters/{uuid}/visualizer` | Get visualizer data |
| `GET` | `/api/v1/clusters/{uuid}/secrets` | List secrets |
| `POST` | `/api/v1/clusters/{uuid}/secrets` | Create secret |
| `DELETE` | `/api/v1/clusters/{uuid}/secrets/{id}` | Remove secret |
| `GET` | `/api/v1/clusters/{uuid}/configs` | List configs |
| `POST` | `/api/v1/clusters/{uuid}/configs` | Create config |
| `DELETE` | `/api/v1/clusters/{uuid}/configs/{id}` | Remove config |

### DNS API

All endpoints require `CORELIX_DNS_PROVIDER_MANAGEMENT=true`. Mutations are owner/admin only; credentials are always masked in responses. Pro-only endpoints return HTTP 402 in the free edition.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/dns-providers` | List team DNS providers |
| `POST` | `/api/v1/dns-providers` | Create provider (free tier: 1) |
| `GET` | `/api/v1/dns-providers/{uuid}` | Get provider details |
| `PATCH` | `/api/v1/dns-providers/{uuid}` | Update provider |
| `DELETE` | `/api/v1/dns-providers/{uuid}` | Delete provider (refused while domains exist) |
| `POST` | `/api/v1/dns-providers/{uuid}/test` | Test provider connection |
| `GET` | `/api/v1/domains` | List managed domains |
| `POST` | `/api/v1/domains` | Create domain + queue provisioning (free tier: 1) |
| `GET` | `/api/v1/domains/{uuid}` | Get domain details |
| `PATCH` | `/api/v1/domains/{uuid}` | Update domain (incl. `access_policy`, Pro) |
| `DELETE` | `/api/v1/domains/{uuid}` | Delete domain |
| `POST` | `/api/v1/domains/{uuid}/sync` | Re-queue domain provisioning |
| `GET` | `/api/v1/domains/{uuid}/hostnames` | List managed hostnames for a domain |
| `GET` | `/api/v1/dns/resource/{type}/{uuid}` | Per-resource DNS status |
| `POST` | `/api/v1/dns/resource/{type}/{uuid}/resync` | Queue a resource reconcile |
| `POST` | `/api/v1/dns/resource/{type}/{uuid}/assign-domain` | Pin/unpin resource domain (Pro) |
| `GET` | `/api/v1/domains/{uuid}/environment-bindings` | List environment bindings (Pro) |
| `POST` | `/api/v1/domains/{uuid}/environment-bindings` | Create/update binding (Pro) |
| `DELETE` | `/api/v1/domains/{uuid}/environment-bindings/{id}` | Delete binding (Pro) |

For full request/response examples, see the [API Documentation](docs/api.md).

---

## Upgrading & Reverting

### Upgrading Coolify

```bash
cd /data/coolify/source
docker pull ghcr.io/corelix-io/coolify-enhanced:latest
bash upgrade.sh
```

Or rebuild locally:

```bash
docker build --build-arg COOLIFY_VERSION=v4.x.x -t coolify-enhanced:latest -f docker/Dockerfile .
```

### Uninstalling

```bash
sudo bash uninstall.sh
```

The uninstaller will:
1. Optionally clean up database tables (prompted)
2. Back up and remove `docker-compose.custom.yml`
3. Remove the environment variable
4. Restart Coolify with the original image

### Reverting Is Non-Destructive

- All projects, resources, users, and deployments remain intact
- Permission and encryption settings stay in the database (harmless, ignored by stock Coolify)
- Encrypted backups remain encrypted on S3 (need the addon or rclone with the same password to restore)
- All team members regain full access to all projects (standard Coolify v4 behavior)

For detailed revert instructions and database cleanup options, see the [Installation Guide](docs/installation.md#reverting-to-original-coolify).

---

## Architecture Overview

Corelix Platform is a **Laravel package** that extends Coolify via its service provider system. It does **not** modify Coolify's source code directly.

### How It Integrates

| Mechanism | Used For |
|-----------|----------|
| **Policy overrides** via `Gate::policy()` in `$app->booted()` | Granular permissions — replaces Coolify's permissive defaults |
| **View overlays** — modified copies of Coolify Blade views | Backup sidebar items, encryption form, template source labels, build type dropdown (general.blade.php, New Resource views) |
| **Middleware injection** | Access Matrix on Team Admin page, Clusters sidebar link |
| **File overlays in Docker image** | Encryption-aware backup/restore jobs, custom template loading, expanded database classification, additional build types (ApplicationDeploymentJob, BuildPackTypes enum) |
| **S6-overlay service** | Auto-run database migrations on container start |

### Database Schema (Additions)

```
project_user              environment_user          s3_storages (added columns)
--------------            ----------------          -------------------------
id                        id                        encryption_enabled
project_id (FK)           environment_id (FK)       encryption_password
user_id (FK)              user_id (FK)              encryption_salt
can_view                  can_view                  filename_encryption
can_deploy                can_deploy                directory_name_encryption
can_manage                can_manage                path (S3 prefix)
can_delete                can_delete

scheduled_resource_backups              scheduled_resource_backup_executions
--------------------------              ------------------------------------
id                                      id
resource_type / resource_id             backup_id (FK)
backup_type (volume/config/full/...)    status
frequency (cron)                        size / filename
s3_storage_id                           is_encrypted
enabled                                 created_at

service_databases (added columns)
---------------------------------
proxy_ports (JSON, nullable)

custom_template_sources
-----------------------
id / uuid
name / slug
repository_url / branch / folder_path
auth_token (encrypted)
is_enabled
sync_status / last_synced_at / sync_error

clusters                             cluster_events
--------                             --------------
id / uuid                            id
name / description                   cluster_id (FK)
type (swarm|kubernetes)              event_type / action
status (healthy|degraded|...)        actor_id / actor_name
manager_server_id (FK)               attributes (JSON)
team_id (FK)                         scope / event_time
settings (encrypted JSON)
metadata (JSON)

servers (added column)               swarm_secrets
----------------------               -------------
cluster_id (FK, nullable)            id / docker_id
                                     cluster_id (FK)
swarm_configs                        name / labels (JSON)
-------------                        description
id / docker_id
cluster_id (FK)
name / data / labels (JSON)

managed_networks                     resource_networks
----------------                     -----------------
id / uuid                            id
docker_network_name (unique w/ srv)  resource_type / resource_id (morph)
server_id (FK)                       managed_network_id (FK)
team_id / project_id / env_id        is_auto_attached / is_connected
scope (environment/shared/proxy)     connected_at / aliases (JSON)
driver / status / docker_id
is_proxy_network / is_attachable
is_encrypted_overlay / options
```

For the full architecture document including flow diagrams, security considerations, and extensibility points, see [Architecture](docs/architecture.md).

### Feature Documentation

Each feature has detailed documentation under `docs/features/<feature-name>/`:

| Feature | Folder | Contents |
|---------|--------|----------|
| Enhanced Database Classification | [`docs/features/enhanced-database-classification/`](docs/features/enhanced-database-classification/) | PRD, implementation plan, feature overview |
| Network Management | [`docs/features/network-management/`](docs/features/network-management/) | PRD, implementation plan, feature overview |
| MCP Server | [`docs/features/mcp-server/`](docs/features/mcp-server/) | PRD, implementation plan, feature overview |
| Cluster Management | Pro / Corelix offering | Available through the managed platform or enterprise self-hosted license |
| Enhanced UI Theme | [`docs/features/enhanced-ui-theme/`](docs/features/enhanced-ui-theme/) | PRD, implementation plan, feature overview |
| Additional Build Types | [`docs/features/additional-build-types/`](docs/features/additional-build-types/) | PRD, implementation plan, feature overview |
| Feature Flag Gating | [`docs/features/feature-flag-gating/`](docs/features/feature-flag-gating/) | PRD, implementation plan, feature overview |
| Docker Registry Management | [`docs/features/docker-registry-management/`](docs/features/docker-registry-management/) | PRD, implementation plan, feature overview |
| Traefik Label Overrides | [`docs/features/traefik-label-overrides/`](docs/features/traefik-label-overrides/) | PRD, implementation plan, feature overview |

Each feature folder contains:
- **PRD.md** — Product Requirements Document (problem, goals, design, rationale, risks)
- **plan.md** — Technical implementation plan (code snippets, architecture, testing checklist)
- **README.md** — Feature overview and quick reference

---

## FAQ

**Does this modify Coolify's source code?**
No. It's a Laravel package installed via Composer inside a custom Docker image. Overlay files are image-specific — reverting to the official image restores the originals.

**Will this break Coolify updates?**
The `docker-compose.custom.yml` file survives Coolify upgrades. However, major Coolify updates may require a new addon build. Pre-built images on GHCR track the latest Coolify version.

**What happens to encrypted backups if I uninstall?**
They remain encrypted on S3. You can restore them by reinstalling the addon or using rclone directly with the same encryption password.

**Can I use this with Coolify v5?**
This addon is for Coolify v4. Coolify v5 is expected to include similar features natively. We will provide a migration guide when v5 is released.

**Does removing a custom template source break running services?**
No. After deployment, the docker-compose YAML is stored in the database. Removing a template source has zero impact on services already deployed from it.

**What encryption algorithm is used?**
NaCl SecretBox (XSalsa20 stream cipher + Poly1305 MAC) via rclone's crypt backend. This is the same algorithm used by tools like age, WireGuard, and libsodium.

**How does it compare to Dokploy / Portainer / CapRover?**

| Feature | Coolify + Enhanced | Dokploy | Portainer CE | CapRover |
|---------|-------------------|---------|--------------|----------|
| Granular project permissions | Yes | Partial | Teams only (BE) | No |
| Encrypted S3 backups | Yes | No | No | No |
| Volume & config backups | Yes | No | No | No |
| Custom service templates | Yes | No | App Templates | One-click apps |
| Cluster management | Yes (Swarm) | Basic (Swarm) | Full (Swarm + K8s) | No |
| Open source | MIT | MIT | Partial (CE/BE) | Apache 2.0 |
| Self-hosted PaaS | Yes | Yes | Container mgmt | Yes |

---

## Contributing

Contributions are welcome.

**Two repositories:**

- **Private canonical repo** (`corelix-io/platform`) — Full pro edition with all features. Issues, PRs, and internal processes live here.
- **Public free mirror** (`corelix-io/platform`) — CI-managed snapshot of the free edition, auto-published from the private repo. Community issues and discussions are welcome here.

**For community contributors (free edition):**

1. Fork `corelix-io/platform`
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Describe the problem and proposed change clearly in the PR
4. Keep changes focused and user-relevant
5. Update public-facing documentation when behavior changes
6. Open a Pull Request against `corelix-io/platform`

Notes:
- Public PR commit history may not be preserved verbatim in the public mirror because the mirror is snapshot-based.
- Accepted changes are applied in the private canonical repo first and then republished back to the public mirror through the free-edition pipeline.
- Full maintainer workflows, premium-feature implementation rules, and internal release processes live in the canonical private repo.

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

## Support

- [GitHub Issues](https://github.com/corelix-io/coolify-enhanced/issues)
- [GitHub Discussions](https://github.com/corelix-io/coolify-enhanced/discussions)
- [CHANGELOG](CHANGELOG.md)
- [Coolify Discord](https://discord.gg/coolify)

---

## Acknowledgments

- [Coolify](https://coolify.io) — The self-hostable platform this addon extends
- [rclone](https://rclone.org) — Cloud storage tool providing the encryption backend
- [Dokploy](https://dokploy.com) — Inspiration for the granular permission model

---

**Built for teams that self-host with Coolify and need production-grade access control, backup security, template flexibility, and cluster management.**
