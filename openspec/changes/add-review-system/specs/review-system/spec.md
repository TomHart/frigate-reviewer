## Purpose

Provides detection review for a self-hosted Frigate NVR: it syncs detections from the Frigate HTTP API, stores them immutably alongside the original snapshots, and lets a single reviewer correct validity, identity, and bounding boxes per object, preserving every record for future dataset export and model retraining.

## ADDED Requirements

### Requirement: Detection sync from Frigate
The system SHALL sync detections from the Frigate HTTP API and store, for each detection: the Frigate event ID, camera, source object class (for example person, dog), confidence, timestamps, and the original bounding box. The system SHALL store links to the original full-resolution snapshot and, where available, the associated video clip. The system SHALL NOT modify any original Frigate metadata.

#### Scenario: Successful sync of a detection
- **WHEN** the system syncs and Frigate reports a detection on camera "front_door" with source class "person", confidence 0.87, a bounding box, and an available clip
- **THEN** a detection record is stored with that event ID, camera, source class, confidence, timestamps, original bounding box, and links to both the snapshot and the clip

#### Scenario: Sync never modifies Frigate
- **WHEN** any sync runs
- **THEN** all requests to the Frigate HTTP API are read-only and no Frigate event or metadata is changed

### Requirement: Immutable original snapshots
The system SHALL store each original full-resolution snapshot exactly once and SHALL never overwrite, alter, or delete it except through explicit user-initiated permanent deletion. The system SHALL generate separate thumbnails for the web UI.

#### Scenario: Snapshot stored once
- **WHEN** a snapshot is first stored for a detection
- **THEN** subsequent syncs and reviews reuse the stored original and never replace or re-encode it

#### Scenario: Thumbnails are separate
- **WHEN** the web UI displays a detection
- **THEN** it renders a generated thumbnail, not the original full-resolution snapshot file

### Requirement: Review listing with filters
The system SHALL provide a web UI listing detections, filterable by camera, date, validity, class, and review state, supporting per-object review.

#### Scenario: Filter by camera and validity
- **WHEN** the reviewer filters the listing to camera "front_door" with validity "false positive"
- **THEN** only detections from that camera with false-positive objects are shown

#### Scenario: Filter by review state
- **WHEN** the reviewer filters to unreviewed detections
- **THEN** only objects whose validity is still "unreviewed" appear

### Requirement: Per-object review
For each detected object the reviewer SHALL be able to set a validity (unreviewed, true positive, false positive, unsure), an identity label, a corrected bounding box drawn or edited in the UI, a false-positive reason, and reviewer notes. The reviewer SHALL be able to change any of these at any time. The default validity SHALL be unreviewed.

#### Scenario: Reviewer marks a true positive
- **WHEN** the reviewer sets an object's validity to true positive
- **THEN** the object's validity is stored as true positive and its review state updates accordingly

#### Scenario: Reviewer edits a corrected bounding box
- **WHEN** the reviewer draws or adjusts a bounding box over the object in the snapshot
- **THEN** the corrected bounding box is stored separately from the original Frigate bounding box

#### Scenario: Reviewer adds notes and a reason
- **WHEN** the reviewer saves reviewer notes and, for a false positive, a reason
- **THEN** both are stored against the object and remain editable

#### Scenario: Any field can be revised later
- **WHEN** the reviewer changes an object's previously saved validity, identity, bounding box, reason, or notes
- **THEN** the new values are stored and the change is recorded in the audit trail

### Requirement: Manual objects added by the reviewer
The reviewer SHALL be able to add new objects to an image by drawing a bounding box, for objects the Frigate detector never fired on. Objects added this way SHALL have origin "manual"; objects originating from Frigate SHALL have origin "frigate".

#### Scenario: Reviewer adds a missed object
- **WHEN** the reviewer draws a new box on a snapshot where Frigate detected nothing
- **THEN** a new object is created with origin "manual" and default validity "unreviewed"

#### Scenario: Frigate-originated objects keep their origin
- **WHEN** a detection was created by sync from Frigate
- **THEN** its object origin is "frigate" and remains "frigate"

### Requirement: Original and corrected bounding boxes are distinguished
The system SHALL store the original Frigate bounding box and the human-corrected bounding box as separate values and SHALL clearly distinguish them in the UI.

#### Scenario: Both boxes visible in the UI
- **WHEN** an object has both an original Frigate bounding box and a corrected bounding box
- **THEN** the UI displays both distinctly so the reviewer can see what Frigate reported versus what the reviewer corrected

#### Scenario: Correcting never overwrites the original
- **WHEN** the reviewer saves a corrected bounding box
- **THEN** the original Frigate bounding box remains unchanged

### Requirement: Validity and identity are independent
Validity and identity SHALL be independent fields. Validity SHALL be one of unreviewed, true_positive, false_positive, unsure. Identity SHALL be one of the configured labels (initially Finola, Tom, Person, Dougie, Dog, or Unknown). A false positive SHALL never carry a positive identity classification: setting validity to false positive SHALL clear the identity. An unidentified true-positive person SHALL be recorded as validity true positive with identity Person or Unknown. The system SHALL NOT force the reviewer to assign a known identity.

#### Scenario: False positive clears identity
- **WHEN** the reviewer sets an object with identity "Finola" to validity "false positive"
- **THEN** the identity is cleared and the object is stored with no positive identity classification

#### Scenario: Unidentified person true positive
- **WHEN** the reviewer marks an object as a true positive but cannot rule out that it is Finola or Tom
- **THEN** the object may be saved as validity "true positive" with identity "Unknown" without any blocking validation error

#### Scenario: Known-identity labels are optional
- **WHEN** the reviewer marks an object as a true positive and confirms it is a person but not Finola or Tom
- **THEN** the object may be saved with identity "Person"

### Requirement: Configurable labels with stable exported class IDs
Labels SHALL live in the database, not in code. Each label SHALL have: a name, a kind (person or dog) constraining which Frigate source classes it may be applied to, a sort order, an active flag, and a permanent exported class ID assigned at creation time that never changes and is never reassigned. The system SHALL be seeded with the five initial labels: Finola (person), Tom (person), Person (person), Dougie (dog), Dog (dog). New labels SHALL be addable later without code changes. Stable class identifiers and the configurable taxonomy SHALL be maintained so a future export can emit a name-to-class-ID manifest.

#### Scenario: Initial labels exist
- **WHEN** the application is first set up
- **THEN** the labels Finola, Tom and Person (kind person) and Dougie and Dog (kind dog) exist, each with a unique exported class ID

#### Scenario: Adding a label needs no code change
- **WHEN** the reviewer adds a new label "Postman" of kind person
- **THEN** it becomes available for identity assignment and receives a new exported class ID that has never been used before

#### Scenario: Exported class IDs are permanent
- **WHEN** a label is deactivated or renamed
- **THEN** its exported class ID is unchanged and is never reassigned to another label

#### Scenario: Kind constrains source classes
- **WHEN** the reviewer assigns an identity to an object whose Frigate source class is "dog"
- **THEN** only labels of kind dog are offered

### Requirement: Multiple objects per image
An image or event MAY contain multiple objects, and each SHALL be independently annotated with its own validity, identity, and bounding box.

#### Scenario: Reviewing several objects in one event
- **WHEN** a snapshot contains Finola, Tom and Dougie
- **THEN** the reviewer can set each of the three objects' validity, identity, and bounding box independently

### Requirement: Grouping and bulk classification
Detections SHALL be grouped by Frigate event. The system SHALL support identifying visually similar or repeated snapshots (for example using perceptual-hash-based grouping) and convenient bulk classification. The system SHALL NEVER automatically discard duplicate-looking images; underlying records SHALL always be preserved.

#### Scenario: Event grouping
- **WHEN** multiple detections belong to the same Frigate event
- **THEN** they are presented as one grouped detection in the UI

#### Scenario: Similar snapshots are grouped, not deleted
- **WHEN** the system identifies visually similar or repeated snapshots
- **THEN** they are surfaced together for bulk classification and none is removed

#### Scenario: Bulk classification applies to a group
- **WHEN** the reviewer bulk-classifies a group of similar snapshots as false positive with a given reason
- **THEN** every object in the group receives that validity and reason, each recorded in the audit trail

### Requirement: Annotation audit trail
The system SHALL maintain an audit trail of classification changes and bounding-box edits recording who, when, the previous value, and the new value. Original Frigate metadata SHALL remain immutable. Reviewer notes SHALL be stored per object.

#### Scenario: Classification change is recorded
- **WHEN** the reviewer changes an object's validity from unreviewed to false positive
- **THEN** an audit entry records the reviewer, the timestamp, the previous validity, and the new validity

#### Scenario: Bounding-box edit is recorded
- **WHEN** the reviewer adjusts a corrected bounding box
- **THEN** an audit entry records the previous and new box coordinates

#### Scenario: Frigate metadata is immutable
- **WHEN** any review action is performed
- **THEN** the stored original Frigate event ID, camera, source class, confidence, timestamps, and original bounding box are never altered

### Requirement: False-positive reasons with autocomplete
False-positive reasons SHALL be free text with autocomplete suggestions drawn from previously used values. Suggestions SHALL aggregate past usage without constraining new entry.

#### Scenario: Suggestions from previous usage
- **WHEN** the reviewer has previously entered the reason "tree shadow" and starts typing in the reason field
- **THEN** "tree shadow" is offered as an autocomplete suggestion

#### Scenario: New reasons are accepted
- **WHEN** the reviewer types a reason never used before
- **THEN** it is accepted, stored, and becomes available as a future suggestion

### Requirement: Dataset quality dashboard
The system SHALL provide a dashboard showing: reviewed and unreviewed detections per camera; counts per identity or class; false-positive rates per camera; a breakdown of false-positive reasons; confidence score distributions; detection counts over time; the number of manually corrected bounding boxes; and the number of distinct Frigate events represented.

#### Scenario: Review coverage per camera
- **WHEN** the reviewer opens the dashboard
- **THEN** reviewed and unreviewed detection counts are shown for each camera

#### Scenario: False-positive breakdown
- **WHEN** the reviewer opens the dashboard
- **THEN** false-positive rates per camera and a breakdown of false-positive reasons are shown

#### Scenario: Distribution and trend statistics
- **WHEN** the reviewer opens the dashboard
- **THEN** confidence score distributions, detection counts over time, the count of manually corrected bounding boxes, and the count of distinct Frigate events are shown

### Requirement: Privacy and retention
The system SHALL support configurable retention policies and a way to permanently delete selected images and their associated annotations. No images SHALL be uploaded to any external service; everything SHALL remain self-hosted.

#### Scenario: Permanent deletion of selected images
- **WHEN** the reviewer selects images for permanent deletion and confirms
- **THEN** those images, their thumbnails, and their associated annotations are permanently deleted

#### Scenario: Retention policy applies
- **WHEN** a configurable retention policy expires for stored images
- **THEN** the system deletes the expired images and their annotations according to the policy

#### Scenario: No external uploads
- **WHEN** any feature runs, including sync, review, thumbnails, and the dashboard
- **THEN** no image data is sent to any service outside the self-hosted environment

### Requirement: Data preservation for future export and retraining
The system SHALL preserve all data needed for future dataset export and retraining: immutable original Frigate event metadata; source object class; confidence; timestamps and bounding boxes; original full-resolution snapshots without overwriting; human-assigned validity; human-assigned identity class; original and corrected bounding boxes with clear distinction; multiple independently annotated objects per event or image; false-positive reasons, reviewer notes, and annotation history; stable class identifiers and the configurable taxonomy; and camera and event relationships so annotations trace back to original Frigate events. The data model SHALL remain clean and extensible so export and training features can be added later without redesign.

#### Scenario: Annotations trace back to Frigate events
- **WHEN** any annotation is inspected
- **THEN** it can be traced through its object and detection to the original Frigate event, camera, source class, confidence, and original bounding box

#### Scenario: All review data is retained
- **WHEN** an object has been reviewed with validity, identity, a corrected bounding box, a false-positive reason, notes, and prior audit history
- **THEN** all of that data remains stored and queryable for future export
