## 1. Application skeleton

- [x] 1.1 Create the Symfony 7.4 application skeleton (PHP >= 8.2, `declare(strict_types=1)` everywhere, Twig, Symfony UX Twig Components + Stimulus, AssetMapper) and verify `bin/console about` runs
- [x] 1.2 Configure SQLite (volume-mounted file path from environment) as the Doctrine connection in development and production, and enable WAL mode
- [x] 1.3 Add Tailwind CSS 4 built via its standalone CLI at image-build time, wired into AssetMapper, and verify a styled page renders without any Node runtime at application runtime
- [x] 1.4 Create the base layout template and confirm the app runs locally with a single console command

## 2. Data model and labels

- [x] 2.1 Implement Doctrine entities for snapshot, frigate_event, detection, review, label, and audit_entry per design.md D3/D4, with origin (`frigate`/`manual`) on detection
- [x] 2.2 Write the migration creating the schema and a seed migration inserting the five initial labels (Finola, Tom, Person: kind person; Dougie, Dog: kind dog), each with a permanent exported class ID
- [x] 2.3 Implement label management: exported class IDs allocated monotonically at creation inside a transaction and never reassigned; verify deactivation and rename leave IDs unchanged
- [x] 2.4 Enforce the domain rule that setting validity to false positive clears identity, with a failing test first, in the domain layer
- [x] 2.5 Enforce label-kind constraints: a label may only be applied to detections whose Frigate source class matches the label kind (manual objects accept any kind)

## 3. Frigate sync

- [x] 3.1 Implement a small Frigate HTTP API client (read-only) isolated behind an interface, covering events, snapshots, and clips
- [x] 3.2 Implement the `frigate:sync` console command that stores frigate_event, detection, and snapshot rows immutably, idempotent by Frigate event ID, with snapshot download and DB insert committed together
- [x] 3.3 Verify re-running sync stores nothing twice and never modifies existing Frigate facts, including confidence, timestamps, and original bounding boxes
- [x] 3.4 Generate web thumbnails lazily on first UI request, cached on the volume, never touching the original snapshot file

## 4. Review interface

- [x] 4.1 Build the detection listing page with filters for camera, date, validity, class, and review state
- [x] 4.2 Build the event grouping view so detections from one Frigate event are presented together
- [x] 4.3 Build the Stimulus SVG bounding-box editor that displays the original Frigate box and the corrected box distinctly, and lets the reviewer draw and edit the corrected box
- [x] 4.4 Build the per-object review form: validity, identity, false-positive reason, and reviewer notes, all editable at any time
- [x] 4.5 Add manual object creation: the reviewer draws a new box on a snapshot and the object is stored with origin `manual`
- [x] 4.6 Add autocomplete for false-positive reasons sourced from previously used values, accepting new free-text values

## 5. Grouping and bulk review

- [x] 5.1 Compute a perceptual hash per snapshot at ingest and index it on the snapshot row
- [x] 5.2 Build similar-snapshot grouping by Hamming distance, presentation-only, with no record ever merged or discarded
- [x] 5.3 Implement bulk classification over a group or selection, applying validity and reason to every selected object with an individual audit entry each

## 6. Audit trail

- [x] 6.1 Write an audit entry (who, when, field, previous value, new value) in the same transaction as every classification change and bounding-box edit
- [x] 6.2 Add a per-object history view listing audit entries
- [x] 6.3 Verify with tests that no review action can alter stored Frigate metadata (event ID, camera, source class, confidence, timestamps, original box)

## 7. Dashboard

- [x] 7.1 Implement reviewed/unreviewed counts per camera and counts per identity or class
- [x] 7.2 Implement false-positive rates per camera and the false-positive reasons breakdown
- [x] 7.3 Implement confidence score distributions and detection counts over time
- [x] 7.4 Implement counts of manually corrected bounding boxes and distinct Frigate events, and render the full dashboard page

## 8. Privacy, retention, and deletion

- [x] 8.1 Implement configurable retention policies (global and per-camera age limits) executed by a console command suitable for in-container scheduling
- [x] 8.2 Implement confirmed permanent deletion of selected images, deleting snapshot files, thumbnails, and dependent annotation rows in one transaction
- [x] 8.3 Verify no code path uploads image data to any external service (no external CDNs, fonts, or APIs)

## 9. Docker and CI

- [x] 9.1 Write the Dockerfile producing a single-container image running the app, with the SQLite file and snapshot storage on a volume and Tailwind CSS built at image build
- [x] 9.2 Write the GitHub Actions workflow that builds the image and pushes it to the private registry, authenticating with `DOCKER_REGISTRY_HOST`, `DOCKER_REGISTRY_USER`, and `DOCKER_REGISTRY_PASS` from repository secrets
- [x] 9.3 Run the built image locally with a mounted volume, sync from a Frigate instance, review a detection end to end, and verify data persists across container restarts
