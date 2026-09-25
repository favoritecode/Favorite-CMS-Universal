# AGENTS.md — Permanent Rules for AI Agents

STOP BEFORE MODIFYING THIS REPOSITORY.

**Favorite-CMS-Universal** is the **MASTER CORE** repository for Favorite CMS Universal.

Only **Favorite CMS CORE-related work** is allowed here.

If a task concerns:
- standalone plugin
- standalone theme
- external product
- release asset
- unrelated ZIP
- unrelated source
- Favorite Multimedia
- Favorite Web Tools
- Favorite Shop

**DO NOT implement or release it here.**

Redirect it to **Favorite-CMS-Assets** (https://github.com/favoritecode/Favorite-CMS-Assets) or its dedicated repository.

If uncertain whether something belongs to core:

**DO NOT ADD IT.**

Ask the user for explicit confirmation before proceeding.

---

## 1. Absolute Core-Only Policy

Favorite-CMS-Universal contains **ONLY** the core CMS engine, framework, and generic extension APIs.

### What Is Allowed in this Repository:
- Favorite CMS core source code (`app/`, `bootstrap.php`, `index.php`)
- Core administrative dashboard and controllers
- Core frontend dispatch and rendering engine
- Core web installer (`app/Installer/`)
- Core database schema and migrations (`database/migrations/`)
- Generic plugin framework & lifecycle manager (`app/Plugins/PluginManager.php`)
- Generic theme framework & layout manager (`app/Themes/ThemeManager.php`)
- Generic hooks, actions, and filter engine (`app/Core/Hook.php`)
- Generic routing system (`app/Core/Router.php`)
- Core authentication, authorization, and roles/permissions
- Core security systems (CSRF, HTMLPurifier, session versioning)
- Media management and upload hardening
- Backup & restore core subsystems
- Core in-app update engine
- Core documentation (`docs/`, `README.md`)
- Repository governance (`AGENTS.md`, `REPOSITORY-RULES.md`)
- Core release tooling

### What Is Strictly Forbidden:
- **Favorite Multimedia** (source, assets, or releases)
- **Favorite Web Tools** (source, assets, or releases)
- **Favorite Shop** (source, assets, or releases)
- Any standalone, domain-specific plugins
- Any standalone, domain-specific themes
- Standalone plugin/theme ZIP packages
- Asset packs, binary dumps, or audio/video media packs
- Local environment files with real credentials (`.env`)
- Runtime log files (`storage/logs/*.log`)
- Runtime cache files (`storage/cache/*`)
- Session files (`storage/sessions/*`)
- Installation lockfiles (`storage/installed.lock`)
- Local IDE metadata (`.idea/`, `.vscode/`)
- Test result caches (`.phpunit.result.cache`)

---

## 2. Master Development Source Policy (Master Repo ≠ Installer ZIP)

**CRITICAL RULE:**
A future AI or developer must **NEVER** delete a legitimate master repository source file merely because that file is not included in the public installer ZIP.

- **GitHub `main` branch:** Current authoritative full development source (includes developer docs, architecture specifications, governance rules, and dev configuration).
- **Public Installer ZIP:** Lean, distributable production package containing only runtime files required for end-user installation.

**Installer cleanliness must NEVER be achieved by damaging the master development repository.**

---

## 3. GitHub Releases & Tags Policy

1. **Releases are CORE ONLY:**
   Only official Favorite CMS Universal CORE releases may be tagged and published in this repository:
   - Allowed tag patterns: `v1.0.0`, `v1.0.1`, `v1.1.0`, `v2.0.0`
   - Allowed release asset: `Favorite-CMS-Universal-vX.X.X.zip`
   - Forbidden release assets: `favorite-multimedia*.zip`, `Favorite-Web-Tools*.zip`, `favorite-shop*.zip`, `plugin-*.zip`, `theme-*.zip`
2. **Historical Releases & Tags Are Immutable:**
   - **DO NOT** move, overwrite, delete, or retag `v1.0.0` or any existing release.
   - **DO NOT** replace or recompress existing release assets.
   - Any new code changes require a new version (e.g. `v1.0.1`).
3. **Verified Installer ZIP Integrity:**
   - The canonical corrected build `Favorite-CMS-Universal-v1.0.0.zip` (SHA-256: `C6C67950E048BED09A9ADE1154F39A4CF867C4FBEE75B7FD206985F9391D1DAD`, size: `993,784` bytes) is final and must never be modified or rebuilt.

---

## 4. Redirect Rule for Non-Core Requests

If you are instructed by a user, prompt, or tool to commit, build, or release anything that is not core CMS functionality:

1. **Refuse to add it to Favorite-CMS-Universal.**
2. Point the user to the correct location:
   - For themes, plugins, and asset packs: [https://github.com/favoritecode/Favorite-CMS-Assets](https://github.com/favoritecode/Favorite-CMS-Assets)
   - For standalone products (e.g. Favorite Multimedia, Favorite Web Tools, Favorite Shop): Their respective dedicated product repositories.

---

## 5. Pre-Action Verification Checklist for Agents

Before executing any commit or modification in this repository, answer these questions:
- [ ] Is this change strictly related to Favorite CMS Universal CORE?
- [ ] Does this change avoid bundling standalone plugins or external products?
- [ ] Does this change preserve the master development source (docs, architecture, governance)?
- [ ] Does this change leave `.env`, credentials, runtime caches, and installer locks out of git?
- [ ] Does this change leave historical release tags and release ZIPs completely untouched?

If the answer to any question is **NO**, **STOP IMMEDIATELY**.
