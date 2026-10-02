# Changelog

This changelog is the public release history for `corelix-platform`.

It is curated from the private canonical repository history and intentionally summarizes approved, user-facing changes without exposing private development history or pro-only implementation details.

## 2026-06-18

### Security audit: fix cross-tenant IDOR, registry SSRF, and a restore-backup authorization gap
- **Cross-tenant IDOR (CWE-639)** in the Traefik label-overrides API: all nine endpoints loaded resources by UUID without scoping to the caller's team, so a team admin could read or modify another tenant's container labels by UUID. Every lookup is now scoped to the API token's team.
- **SSRF (CWE-918)** in the Docker registry connection test: a user-supplied registry URL was fetched server-side, and the OAuth Bearer flow then followed an attacker-controlled auth realm host with the user's credentials attached. Added a centralized SSRF guard (`Support\SsrfGuard`) that blocks loopback / cloud-metadata / reserved targets (private ranges gated by `CORELIX_REGISTRY_ALLOW_PRIVATE_NETWORKS`), disables redirect-following, and always verifies TLS. The existing DNS-provider SSRF guard now shares this single implementation.
- **Authorization gap (CWE-285)** in the backup-restore screen: a non-admin team member could import environment variables because the admin check used an unreturned `redirect()` and the import action had no re-check. Both now `abort(403)`.
- Hardening: registry connection tests no longer disable TLS verification outside production. Added `SsrfGuardTest` unit coverage.
- Methodology: audit grounded in Anthropic's claude-code-security-review prompt + the OWASP Laravel cheat sheet (data-flow tracing, adversarial exploitability verification, confidence ≥0.8). See `docs/audits/2026-06-18-security-audit.md`.

### Full-project audit: fixes for free-build crash, API tenant scoping, and proxy state
- **Critical free-build fix**: corrected a misordered `@feature`/`PREMIUM` marker in the service configuration page that stripped the `@feature` directive out of free builds, leaving a dangling `@else`/`@endfeature` that fatally broke Blade compilation on every service page. The Traefik-label upsell now survives free builds on both the service and application Advanced tabs.
- **API tenant isolation**: the DNS and Network management API endpoints resolved resources via the session-based `ownedByCurrentTeam()` on stateless Sanctum token routes, which could 500 (no session) or mis-scope to a leaked web-session team. They now derive the team from the API token and scope every lookup accordingly.
- **Network reliability**: proxy-network migration recorded resources as "connected" without verifying the Docker `network connect` succeeded, masking failures as healthy attachments (intermittent 502s). It now records the verified connection state and reports partial failures.
- **MCP client**: non-idempotent `POST` requests are no longer retried on `503` (which could duplicate create/deploy operations); enhanced-mode auto-detection now retries transient errors instead of disabling all enhanced tools for the session on a single blip.
- **Hardening & docs**: guarded the free-shipped label-override Livewire methods against the stripped pro service, registered the whole free-edition test suite into the `free` Pest group (the documented `--group=free` command previously ran only 3 of 41 tests), restored regression tests for the `PermissionService` authorization boundary, and corrected stale MCP tool counts (143 → 148) and the `AUDIT_TRAIL` placeholder status across the docs.

## 2026-05-06

### Sync upstream Coolify v4.0.0 GA + freeze base image
- Pinned the published Docker image's Coolify base to `4.0.0` GA (was floating `latest`). The CI workflows and Dockerfile now share a single canonical pin; bumping requires re-running the upstream sync skill in the same PR. This eliminates an entire class of "undefined function / TypeError" 500s caused by the base floating ahead of overlays.
- Resolved the production 500 `Call to undefined function find_destination_for_current_team()` seen on Corelix Open running against v4.0.0 GA. Same merge fixes two latent siblings (`validateFilenameSafe` undefined on Postgres init scripts; `create_standalone_*` TypeError when picking a destination).
- Re-based 9 overlay files onto upstream v4.0.0 GA: shared helpers (new helpers + ssh URL hardening + dropped removed helper), database factories (new model-typed signatures), service Index Blade (Advanced sub-page split), application general Blade (https examples + responsive widths), select Blade (AMD/ARM template badges + Swarm deprecated badge), application configuration/swarm/server-sidebar/ApplicationDeploymentJob (Swarm deprecation banners + healthcheck path regex extension).
- Documentation: pinning policy added to README, AGENTS, CLAUDE; sync report archived under `docs/syncs/`.

## 2026-03-07

### Platform positioning clarified
- Documented the Corelix cloud platform direction as a managed offering built on top of the `corelix-platform` codebase.
- Started formalizing the repository split between the private canonical source and the public free-edition mirror.

## 2026-03-06

### Pro edition and release pipeline hardening
- Added Docker Registry Management for team-level registry credentials, provider-specific authentication, server sync, and ECR token refresh support.
- Refined the feature-gating system so the free and pro editions can be built more reliably from the same codebase.

## 2026-02-28 to 2026-02-27

### Branding, themes, and build workflows
- Added a build-time and runtime whitelabeling system for commercial/platform distributions.
- Introduced additional application build types including Railpack, Heroku Buildpacks, and Paketo Buildpacks.
- Expanded the theming work into a multi-theme system with improved TailAdmin integration and safer SPA navigation behavior.

## 2026-02-26 to 2026-02-25

### Cluster management and UI maturity
- Added Docker Swarm cluster management with dashboards, service and task visibility, visualizer views, structured swarm configuration, and operational tooling.
- Stabilized the theme system with multiple follow-up fixes for PHP errors, route behavior, and color consistency.
- Improved custom template logo rendering and related UI polish.

## 2026-02-21 to 2026-02-20

### MCP server and automation
- Added the standalone MCP server package for AI-driven Coolify management.
- Documented the MCP workflow and added npm publishing automation.
- Expanded the project’s operational docs, including Coolify upstream issue tracking.

### Network management launch
- Delivered environment-level Docker network isolation, proxy isolation, and Docker Swarm overlay network support.
- Addressed early security and reliability findings after the first implementation wave.

## 2026-02-19

### Database classification and networking foundations
- Added enhanced database classification with a broader database image registry and explicit override support.
- Added multi-port database proxy support for database services that expose more than one TCP interface.
- Introduced the initial architecture and documentation framework for large feature work, including feature-specific PRDs, implementation plans, and READMEs.

## 2026-02-17

### Custom template sources
- Added support for external GitHub repositories as custom service template sources.
- Added source labels, source filtering, and warning handling for ignored or untested templates on the New Resource page.
- Reworked the README to cover the expanding feature set with clearer screenshots and onboarding guidance.

## 2026-02-15 to 2026-02-13

### Restore flows and backup reliability
- Added the Restore / Import Backups page in Settings.
- Fixed edge cases in volume backups, including file-based bind mounts.
- Improved backup schedule reliability and fixed `coolify_instance` edge-case crashes.

## 2026-02-12 to 2026-02-11

### Resource Backups
- Added full resource backups covering Docker volumes, configuration snapshots, and full combined backups.
- Added Coolify instance backup support and S3 path-prefix handling.
- Integrated Resource Backups into native Coolify-style navigation and views for applications, databases, services, and server-level management.
- Continued refining the UX to better match native Coolify patterns.

## 2026-02-10 to 2026-02-09

### Encrypted S3 backups and addon identity
- Renamed the project to `corelix-platform`.
- Added encrypted S3 backups using rclone crypt integration.
- Reworked the encryption settings UI to use view overlays and Coolify-native form components for better compatibility.
- Fixed policy coverage gaps and rclone execution issues discovered during early adoption.

## 2026-02-09 to 2026-02-08

### Permissions, install flow, and core usability
- Added the interactive installer and CLI install/uninstall/status flows.
- Expanded and refined the Access Matrix UI.
- Hardened permission enforcement for project, environment, and resource-level access control.
- Fixed boot-order and policy registration issues so custom authorization correctly overrides permissive upstream defaults.

## 2026-02-07 to 2026-02-04

### Initial public foundation
- Published the project to GitHub for public use.
- Added the first Docker image publishing workflow.
- Introduced the initial addon installation model, `docker-compose.custom.yml` integration, access matrix UI, and uninstall support.
- Added early documentation for revert and uninstall operations.

## Notes

- The public repository may use fresh snapshot history as part of the free-edition mirror workflow.
- The canonical implementation history remains in the private source repository.
- Public documentation and this changelog are the intended source of release context for the community edition.
