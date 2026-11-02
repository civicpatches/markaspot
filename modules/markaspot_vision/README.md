# Imported image screening

Open311 `media_url` imports and promoted email attachments create unpublished
`request_image` media and enqueue one `markaspot_media_intake` job per image.
The POST keeps its existing response and does no synchronous blur or AI call.
Sources stay under `private://request-imports/`, including after screening.
A configured private filesystem is required for imports; mail attachments stay
in staging and are not attached to the report if private storage or the move
fails. The runtime requirements report missing private storage for both import
paths. Private downloads and image styles use `Cache-Control: private, no-store` without validators.
Mail media are registered before saving the report, so even legacy publication
hooks cannot publish an attachment before the screening hold exists.

The worker reads the stored source, calls
`ImageProcessingService::blurSensitiveAreas()` with the normal `BlurPolicy`,
and persists the result through `ImageProcessingService::persistBlurResult()`.
The vision controller uses the same persistence path, including ownership
checks, optional original retention, overwrite and derivative invalidation. A
successful blur step writes `field_ai_metadata`, the marker the strict
publication gate already uses. Only published reports release eligible media
with no privacy hold. Explicit GeoReport media publication decisions remain
authoritative after screening completes for the current bytes. Required blur
cannot be bypassed by direct media saves or editorial publication. Only media
administrators may edit the AI metadata and privacy verdict fields.

## Worker operation

Run the following command **every minute** from the tenant's existing scheduler
or supervisor. Use a scheduler lock (for example `flock`) to prevent
overlapping queue runners. This is required deployment wiring: the cloud
image's 15-minute cron loop alone is insufficient. The worker is also
registered for Drupal cron as a fallback.

```sh
drush queue:run markaspot_media_intake --time-limit=50 --lease-time=900
```

Each image gets at most five privacy attempts. Backoff is 60, 120, 240 and 480
seconds. The queue uses `DelayedRequeueException`; the durable next-attempt
timestamp also prevents immediate manual runs from bypassing backoff. Failed
images remain hidden, with a privacy-issues message visible to staff.
`markaspot:health` reports exhausted attempts and work waiting more than **1200 seconds**
(20 minutes). This tolerates the existing 15-minute cloud cron fallback while the
minute runner is pending deployment. Cron-only tenants can experience up to
20 minutes of screening delay; use the minute runner for timely processing.
Completed jobs without either privacy screening or successful AI analysis produce a separate warning. No historical
images are backfilled automatically.

The durable checkpoint collection is `markaspot_vision.media_intake`, keyed by
media ID. It records attempts and privacy/analysis state, never image bytes or
provider errors. Failed queue insertion is recoverable on the next cron run.
The output fingerprint is stored before overwrite so a retry after a stopped
process recognises the transformed file and finishes invalidating old image
variants before publication. The controller and worker share a per-image lock,
so concurrent requests cannot blur the same import twice. Completed jobs do
not blur or charge again. Replacing a media source invalidates its checkpoint
and schedules a new job. Publication verifies the current source fingerprint;
a mismatch is held
and reported by the health check. After fixing a terminal failure, staff may
use the existing vision analysis flow or make an explicit editorial publication
decision.

Report saves wait up to five seconds for a busy publication lock, then leave
media publication unchanged and queue another attempt without aborting the
report save. No unpublished image is released while the lock is busy.

## Optional analysis

`markaspot_vision.settings.imported_image_analysis` accepts `off`,
`without_description` (default, also for existing installs missing this key),
or `always`. The Vision settings form exposes this choice. No update hook
writes config; config/sync tenants may export the key when choosing a
non-default policy.

Analysis requires the media's trusted tenant scope, its effective `aiAnalysis`
feature, and available token and SaaS analysis budgets. The worker reserves one
SaaS analysis slot before the external call and checkpoints the attempt. An
interrupted or failed optional analysis has an explicit metadata state and
health warning. Transient and uncertain provider failures are not retried for imported images.
Only a rejected unsupported parameter may be repaired before resubmission.
The persisted attempt is not repeated or charged twice; successful blur still
allows publication. AI output is stored as staff suggestions in
`field_ai_metadata.intake_suggestions`; citizen description, title and category
are never overwritten. Privacy findings still hold the media for staff review.

With `without_description`, imports containing citizen text skip additional AI
only after blur actually processed the image. Choose `always` to request that
additional check, subject to the tenant feature and budgets.
When blur is neither configured nor required, the fallback is recorded as
`processed: false` and `privacy_screened: false`. The description policy is
ignored in that case: successful AI analysis bound to the current file hash
can authorize publication. Explicit `off`, tenant feature gates and budgets
still apply; without a successful check the import stays held and health warns.
Enabling required blur invalidates older checkpoints
that were not processed under that requirement, before publication or AI use.
Cron recovery also revokes publication of completed imports with those older
checkpoints, resets them to pending and queues required blur without another
report save. The existing AI checkpoint is retained to prevent duplicate charges.

Without the optional Vision intake service, Open311 retains its legacy
publication behavior only when blur is optional: clean imports publish, while
privacy-flagged imports and all imports requiring blur stay unpublished. Private
import storage is still required; unavailable storage is a server error and is reported in the runtime requirements.

Orphaned intake entries do not fail health checks and can be queued again when
associated. An explicit editorial hold stops optional AI but keeps the privacy work and its checkpoint.
Report unpublishing records a separate temporary hold, so historical media can
return on republishing while privacy holds remain effective. Pruning and
indexing the intake key/value collection remain follow-up work.

## Controller before worker

If staff run controller analysis before the intake worker, a harmless
`Blocked direct publication` warning can appear. The controller attempts to
publish while the intake hold is still pending; the publication guard correctly
keeps the image hidden. The worker subsequently reconciles the screening
checkpoint and releases eligible media. The warning alone does not mean the
analysis failed and does not justify bypassing the intake hold.
