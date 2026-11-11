## Why

Frigate misclassifies many detections (for example, random objects detected as person). There is no way to record human corrections — validity, identity, or corrected bounding boxes — against those detections, so the mistakes persist and no clean data exists for future model retraining.

## What Changes

- Add a self-hosted, single-user Symfony web app that syncs detections from the Frigate HTTP API.
- Store Frigate event metadata, snapshots and clip links immutably; original full-resolution snapshots are stored once and never overwritten; thumbnails are generated separately for the web UI.
- Add a review interface listing detections with filters (camera, date, validity, class, review state) and per-object review: validity, identity label, corrected bounding box drawn in SVG, false-positive reason, and reviewer notes.
- Allow reviewers to add manual objects (drawing new boxes) that Frigate never fired on, alongside Frigate-originated objects.
- Store validity and identity as independent fields; setting false positive clears identity.
- Manage labels as configurable database records (name, kind, sort order, active flag, permanent exported class ID) instead of hardcoding them.
- Support multiple independently annotated objects per image/event, duplicate-looking snapshot grouping with bulk classification, and an audit trail of annotation changes.
- Add a false-positive reason field with autocomplete from previously used values.
- Add a dataset quality dashboard (review coverage, identity counts, false-positive rates and reasons, confidence distributions, detections over time, corrected-box counts, distinct events).
- Add configurable retention policies and permanent deletion of selected images and their annotations. No images ever leave the self-hosted environment.
- Ship as a Docker image (SQLite on a volume) built and pushed by CI to a private registry.

## Capabilities

### New Capabilities

- `review-system`: Frigate detection sync, immutable snapshot storage, the review interface with per-object annotation, configurable labels, grouping and bulk review, audit trail, quality dashboard, and privacy/retention controls.

### Modified Capabilities

- None. This is a new project with no existing specs.

## Impact

- New Symfony 7.4 application (PHP >= 8.2 strict types, Twig, Symfony UX Twig Components + Stimulus, AssetMapper, Tailwind CSS 4) with SQLite on a volume-mounted file.
- New Frigate HTTP API integration for detection and snapshot/clip sync (read-only; Frigate is never modified).
- New Docker image and GitHub Actions workflow pushing to a private registry using `DOCKER_REGISTRY_HOST`, `DOCKER_REGISTRY_USER`, `DOCKER_REGISTRY_PASS` secrets.
- Dataset export, training and retraining are explicitly out of scope and will be specified separately; this change only preserves the data they will need.
