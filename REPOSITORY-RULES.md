# Favorite CMS Universal — Permanent Repository Governance Rules

This document establishes the permanent human-readable governance policy for **Favorite-CMS-Universal** ([https://github.com/favoritecode/Favorite-CMS-Universal](https://github.com/favoritecode/Favorite-CMS-Universal)).

Every contributor, developer, maintainer, release engineer, and automated AI agent must adhere to these policies without exception.

---

## 1. Primary Repository Identity

Favorite-CMS-Universal has two permanent, authoritative responsibilities:

1. **MASTER DEVELOPMENT SOURCE:** The complete, authoritative source repository for the ongoing development of the Favorite CMS Universal core engine.
2. **OFFICIAL CORE RELEASE REPOSITORY:** The sole repository for publishing official, tagged releases of Favorite CMS Universal core.

This repository is **NOT** a general asset bucket.  
It is **NOT** a repository for standalone plugins, custom themes, independent products, or unrelated distributions.

---

## 2. Core-Only Policy (Absolute Boundary)

Only content directly belonging to **Favorite CMS Universal CORE** is permitted in this repository:

### Allowed Core Content:
- Application framework (`app/Core/`, `app/Http/`, `app/Rendering/`, `app/Services/`)
- Core administration panel and views (`resources/views/admin/`)
- Core frontend dispatch and default theme (`themes/default/`)
- Web setup wizard and environment checker (`app/Installer/`)
- Database migrations and schema definitions (`database/migrations/`)
- Generic plugin manager and extension hooks (`app/Plugins/`, `app/Core/Hook.php`)
- Generic theme manager, customizer, and widget engine (`app/Themes/`, `app/Widgets/`)
- Core authentication, authorization, and 6-role permission matrix
- Built-in media subsystem, upload hardening, and HTMLPurifier integration
- Core site backup, restore, and update engine (`app/Services/Update/`)
- Automated test suites (`tests/`, `phpunit.xml`)
- Official core documentation (`docs/`, `README.md`)
- Repository governance policies (`REPOSITORY-RULES.md`, `AGENTS.md`)
- Release verification scripts

### Strictly Forbidden Content:
- **Favorite Multimedia** (source code, templates, assets, or releases)
- **Favorite Web Tools** (source code, tools, assets, or releases)
- **Favorite Shop** (source code, shopping carts, assets, or releases)
- Any standalone, domain-specific plugins or plugin ZIP files
- Any standalone themes, theme packs, or theme ZIP files
- Asset bundles, media libraries, audio/video binary packs
- Real production `.env` files, database passwords, API secrets
- Runtime generated caches, logs, sessions, and `storage/installed.lock`
- Local IDE directories (`.idea/`, `.vscode/`) and OS metadata (`.DS_Store`)

---

## 3. Non-Core Content Redirect Rule

If any developer, release manager, automation pipeline, or AI agent is asked to upload, commit, or release content that is **not** Favorite CMS core:

**DO NOT place it in Favorite-CMS-Universal.**

Redirect the task to:
- **General Themes, Plugins, and Assets:**  
  [https://github.com/favoritecode/Favorite-CMS-Assets](https://github.com/favoritecode/Favorite-CMS-Assets)
- **Standalone Products:**  
  The product's own dedicated GitHub repository (e.g. Favorite-Multimedia, Favorite-Web-Tools, Favorite-Shop).

---

## 4. Master Source Policy & Installer ZIP Separation

### Master Repository ≠ Public Installer ZIP

| Component | Target Location | Contents & Purpose |
|---|---|---|
| **Master Repository** | GitHub `main` branch | The complete developer workspace. Contains core runtime, automated test suites (`tests/`), developer documentation, repository governance rules, and release verification tools. |
| **Public Installer ZIP** | GitHub Release Asset | A clean, production-ready package containing strictly the runtime files necessary for end-users to install and run the CMS. Excludes dev-only tests and governance docs. |

### Rule on Development Source Preservation
**A developer or automated agent must NEVER delete a legitimate development source file (e.g. tests, development configs, developer documentation) merely because that file is not included inside the public installer ZIP.**

Installer cleanliness is achieved during the packaging step. It must never be achieved by stripping the master development repository.

---

## 5. Disaster Recovery Policy

The master GitHub repository must always be complete enough that if the local development workstation or server is completely wiped, the Favorite CMS development project can be fully restored by:

1. Running `git clone https://github.com/favoritecode/Favorite-CMS-Universal.git`
2. Restoring local environment configuration from `.env.example`
3. Configuring a local database and web server
4. Restoring separate operational data (database dump and user uploads) if applicable
5. Resuming development and automated testing immediately

Detailed disaster recovery procedures are documented in [`docs/disaster-recovery.md`](docs/disaster-recovery.md).

---

## 6. GitHub Release Policy

1. **Exclusive Core Releases:**  
   GitHub Releases in Favorite-CMS-Universal are reserved exclusively for Favorite CMS Universal core builds (e.g. `v1.0.0`, `v1.0.1`, `v1.1.0`).
2. **Release Asset Naming:**  
   Allowed release asset format: `Favorite-CMS-Universal-vX.X.X.zip`.  
   Never attach plugin packages (`favorite-multimedia*.zip`), theme archives, or external products here.
3. **Historical Tag & Release Immutability:**  
   - Existing released versions and Git tags (including `v1.0.0`) are permanent and immutable.
   - Never move, retag, overwrite, or re-upload assets for an existing version.
   - Any modification or bug fix requires incrementing the version number (e.g. `v1.0.1`) and producing a new release.

---

## 7. AI & Automated Agent Enforcement

All AI coding assistants (including Antigravity, Claude, Copilot, ChatGPT, and custom bots) operating on this repository are bound by the instructions in [`AGENTS.md`](AGENTS.md).

AI agents must immediately decline and redirect any request to commit non-core assets or modify immutable historical releases.
