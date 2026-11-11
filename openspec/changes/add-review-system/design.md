## Context

Greenfield project. See proposal.md for motivation and specs/review-system/spec.md for the behavioural contract. Constraints that shape the design:

- Self-hosted, single-user: no multi-tenancy, no external identity provider, no cloud services.
- Privacy: images of identifiable people must never leave the environment; no external APIs, CDNs, or font/image CDNs.
- Frigate is integrated read-only over its HTTP API; Frigate itself is never modified.
- Original full-resolution snapshots are immutable once stored; the UI uses separately generated thumbnails.
- Data preservation for a future dataset-export/retraining spec is a hard requirement: stable class IDs, a configurable taxonomy, and an extensible data model.

## Goals / Non-Goals

**Goals:**

- A single deployable Docker container running the full Symfony app with SQLite on a volume.
- A data model that keeps Frigate-originated facts (event ID, camera, source class, confidence, timestamps, original box) immutable and separate from human annotations.
- Stable exported class IDs assigned at label-creation time and never reassigned, so a future export can emit a name-to-class-ID manifest.
- An SVG bounding-box editor in the browser with no Node build step.
- Simple, repeatable sync that is safe to re-run.

**Non-Goals:**

- Dataset export, YOLO/COCO formats, dataset packaging, training, retraining, model evaluation, or model deployment — a future spec will cover these.
- Multi-user auth, roles, or an external identity provider.
- Modifying Frigate configuration, events, or metadata.
- Real-time streaming or live-view features.

## Decisions

### D1. Symfony 7.4 LTS with strict PHP >= 8.2, Twig, UX Twig Components + Stimulus, AssetMapper, Tailwind CSS 4

**Why:** The confirmed stack. AssetMapper serves versioned assets directly from the container with hashed filenames and no Node build, which keeps the Docker image small and CI simple. Tailwind CSS 4 is consumed via its standalone CLI (or the Symfony binary bridge) producing one static CSS file checked into the AssetMapper pipeline. UX Twig Components + Stimulus give componentised interactive pieces (the SVG box editor, filters, autocomplete) without a SPA framework.

**Alternative considered:** A JavaScript SPA with a Node build — rejected: violates the no-Node-build constraint, inflates the image, and is unnecessary for a single-user review tool.

### D2. SQLite as the only database, volume-mounted file, in development and production

**Why:** Single-user workload with low write concurrency; zero-configuration backups (copy one file); identical behaviour in development and production removes environment drift. Doctrine DBAL/ORM talks to it through PDO.

**Alternatives considered:** PostgreSQL — rejected: extra container and operational burden unjustified for one user. MariaDB — same reasoning.

**Consequence:** Doctrine migrations target SQLite portability only. Avoid SQLite-only column types where a future move to PostgreSQL is plausible; do not contort the model for it now.

### D3. Immutable Frigate facts vs mutable annotations as separate tables

**Why:** The core modelling decision. Tables:

- `frigate_event` — synced immutable metadata (Frigate event ID, camera, timestamps, clip path where available).
- `detection` — the Frigate-originated object: source class, confidence, original bounding box, snapshot link, origin (`frigate` or `manual`), FK to event.
- `snapshot` — stored once; content hash; never overwritten; thumbnails generated alongside.
- `review` (per detection object) — validity enum, identity FK, corrected bounding box (nullable), false-positive reason, reviewer notes.
- `label` — name, kind (`person`|`dog`), sort order, active flag, exported class ID.
- `audit_entry` — who, when, field, previous value, new value, FK to reviewed object.

Original Frigate bounding box stays on `detection`; the corrected box lives on the review row. Nothing in the sync path ever writes to reviewed fields; nothing in the review path ever writes to synced fields.

**Alternative considered:** One wide table per detection — rejected: immutability and audit become enforceable only by convention, and manual objects (no Frigate facts) would carry mostly-null columns.

### D4. Manual objects as first-class rows with an `origin` column

**Why:** Reviewer-drawn boxes (origin `manual`) and Frigate detections (origin `frigate`) share the same review lifecycle, so they live in one table distinguished by origin rather than in parallel structures. Manual objects have null Frigate facts.

### D5. Labels as database rows with permanent exported class IDs

**Why:** Exported class IDs must never be reassigned, so they are allocated monotonically at creation (max+1 or a dedicated sequence) inside a transaction, and never freed on deactivate or delete. Kind constrains which Frigate source classes a label may be applied to, enforced at the application layer. The five initial labels are seeded by a migration.

**Alternative considered:** Config-file taxonomy — rejected: the spec requires labels in the database and addable without code changes.

### D6. Sync as a console command (and schedulable service), idempotent by Frigate event ID

**Why:** `frigate:sync` fetches events from the Frigate HTTP API, stores new events/detections/snapshots, and skips anything already present (matched by Frigate event ID plus object identity). Re-running is safe; partial runs leave no half-written detection because snapshot download and DB insert are committed together. A web-triggered sync button may reuse the same service later; the command is the canonical entry point. Thumbnails are generated lazily on first UI request and cached.

**Alternative considered:** Webhook receiver for Frigate events — rejected as the primary mechanism: requires Frigate-side configuration and complicates initial correctness checks; HTTP polling is sufficient for a review tool. May be added later without model changes.

### D7. Validity and identity as independent fields; false-positive clearing enforced in the application layer

**Why:** The spec requires that setting false positive clears identity. This is a domain rule, enforced in the entity/domain service (not merely a UI behaviour or DB trigger), so every write path honours it. Identity is nullable; validity defaults to `unreviewed`. Person-vs-Unknown semantics (Person = confidently not Finola or Tom; Unknown = cannot rule out) are label semantics, not code: the seeded `Person` and `Unknown` labels carry the meaning and the UI presents them alongside the known-identity labels.

**Alternative considered:** A single combined "verdict" field — rejected: the spec explicitly makes the two independent, and export will need them separately.

### D8. Perceptual-hash grouping for duplicates

**Why:** A perceptual hash (for example dHash or pHash) computed at snapshot-ingest time, stored on the snapshot row and indexed, allows grouping similar snapshots by Hamming distance. Grouping is presentation and bulk-selection only; records are never merged or discarded. Bulk classification applies review changes to every selected object, each individually audited.

**Alternative considered:** Exact-hash deduplication — rejected: the requirement is visual similarity, not byte equality, and nothing may ever be discarded.

### D9. Audit trail as an append-only table written in the same transaction as each change

**Why:** Every classification change and bounding-box edit writes an `audit_entry` (who, when, field, previous, new) atomically with the change itself. Single user, but the reviewer identity column is kept so the schema is honest and future multi-user or API access is auditable.

### D10. False-positive reason suggestions from a distinct query over historical values

**Why:** Suggestions come from `SELECT DISTINCT reason ... ORDER BY usage` — aggregation does not constrain entry; the field remains free text. No separate suggestions table.

### D11. Retention and deletion as explicit, confirmed operations

**Why:** A retention policy is configuration (per-camera or global age limits) executed by a console command run on a schedule inside the container; permanent deletion is a UI action requiring confirmation and deletes the snapshot file(s), thumbnails, and dependent annotation rows in one transaction. No soft-delete tombstones: "permanently delete" means permanently.

### D12. Docker image: single container, PHP-FPM/Angie-or-Caddy or a simple runtime image, SQLite volume

**Why:** One container exposing the app over HTTP; the SQLite file and stored snapshots live on a mounted volume so the image stays stateless. CI (GitHub Actions) builds the image and pushes to the private registry using `DOCKER_REGISTRY_HOST`, `DOCKER_REGISTRY_USER`, `DOCKER_REGISTRY_PASS` secrets; authentication reads those environment variables rather than embedding registry names in the workflow file, since the user configures them when creating the repo.

## Risks / Trade-offs

- [SQLite write contention during sync while reviewing] → Sync is the only bulk writer and runs opportunistically; keep transactions short and enable WAL mode.
- [Snapshot storage growth] → Retention policies and permanent deletion exist; original snapshots are never re-encoded, so sizes are whatever Frigate produced.
- [Perceptual-hash grouping can over-group visually similar but distinct events] → Grouping never deletes or merges records; it only affects presentation and bulk selection, so a mistake costs nothing.
- [Class-ID stability depends on never reusing IDs] → IDs are allocated once at creation and never freed; deactivate rather than delete where history exists, and migrations must never rewrite them.
- [Frigate API changes] → The sync service isolates Frigate API access behind a small client so adaptations stay local.
- [Tailwind via standalone CLI in the image build] → The built CSS is produced at image build time (not on the host with Node), keeping the runtime Node-free.

## Migration Plan

- Greenfield: schema is created by Doctrine migrations on first boot; seed migration inserts the five initial labels.
- Deploy: run the container with a volume for the SQLite file and snapshot storage; configure Frigate API URL; run `frigate:sync`; schedule retention as desired.
- Rollback: none required for this change (no existing system to roll back); the volume is the only state and can be restored from a file copy.

## Open Questions

- Exact Frigate API endpoint versions/fields to consume — resolved during implementation against the user's Frigate instance; the sync client isolates this.
- Registry image name/tag convention — supplied via `DOCKER_REGISTRY_HOST` and CI configuration at repo-creation time.
