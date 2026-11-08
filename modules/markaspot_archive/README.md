# Mark-a-Spot Archive

This module provides automatic archiving functionality for service requests in Mark-a-Spot.

## Features

- Automatically archives service requests based on configurable criteria:
  - Time elapsed since creation
  - Request status
  - Category-specific settings
- Anonymizes personal data during archiving
- Background processing via cron jobs

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Archive Settings** (`/admin/config/markaspot/archive`) to configure:

- Archivable status terms
- Default archiving period (days)
- Field anonymization settings
- Cron settings (enable/disable, interval)
- Category-specific overrides

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Mark-a-Spot privacy features
- Taxonomy-based status management
- Drupal's cron system

## Technical Notes

- Uses a queue worker to process archiving operations
- Anonymizes the configured fields on every change to the archived status,
  whether it comes from the queue worker, a manual status change or an Open311
  status update. Requires the "Anonymize" setting.
- Unpublishes the request on the same transitions when the "Unpublish" setting
  is on, independent of the "Anonymize" setting. Saving an already archived
  request again (for example after staff republished it) leaves it alone.
- Once per site, cron unpublishes archived requests that are still published
  (archived before transition unpublishing existed), up to 50 per run and
  keeping their changed time, when the "Unpublish" setting is on. After one
  pass over all requests it stops, so requests staff republish later stay
  published. `drush state:delete markaspot_archive.unpublish_backlog_done`
  runs the pass again.
- Every cron run anonymizes up to 50 archived requests that still hold plain
  contact data in the current or a previous revision (archived before this
  existed or while anonymization was off), keeping their changed time. Plain
  values are recognized in e-mail, telephone and string fields (anonymized
  strings are 10 character hex tokens).
- `drush markaspot:archive:backfill` (alias `mas:archive-backfill`) runs both
  catch-ups in one go instead of 50 requests per cron run, in batches of 200
  (`--batch-size`). `--dry-run` only counts what is left, `--limit=N` stops
  each step after N requests, `--only=unpublish|anonymize` runs one step.
  The saves are the same as on cron: the changed time is kept and no mail goes
  out. A rerun is safe; cron skips the catch-up while the command runs.
  The command checks that it still holds the catch-up lock before the first
  batch, after every request and after every search indexing chunk. Once the
  lock is lost (cron or another run may hold it now) it stops, records no
  further progress and exits with 1. The catch-up on cron renews the lock
  the same way after every request and before it records progress or
  starts its pass over; once a renewal fails, it stops for this run without
  recording progress and logs a warning.
- Search indexes: an erasure that writes the database directly (previous
  revisions, revision logs, retained PII originals) marks the request for
  reindexing in every Search API index that holds it, and the command
  indexes waiting archived requests right away. Search API's tracker
  swallows its own write errors, so the archive module checks afterwards
  that every enabled, writable index holding the request now waits to
  reindex it; if one does not, the erasure is rolled back, counted as
  failed, and the request stays a candidate for the next run. The same
  check runs when a report is archived; a manual change to the archived
  status then fails with an error and the report keeps its status. Only
  the default tracker (and trackers built on its `search_api_item` table)
  can be checked. An index with another tracker is skipped, and a warning
  "could not verify that erased archived requests wait for reindexing"
  names it once per run.
- The command exits with 1 while a search index may keep erased text: a
  read-only index, an enabled index without a valid tracker or server, or a
  disabled index whose data may still sit on a server. Disabling an index
  removes its data only from an enabled server, and a failed removal waits
  as a Search API task. A disabled index therefore counts unless it sits on
  a Search API database server (`search_api_db`, which drops the tables
  when the index is disabled), that server keeps no tables for it and no
  Search API task waits for it. A disabled index on any other backend
  always counts, even after its data was cleared there: the module cannot
  verify that. A disabled index without a server always counts too, because
  nothing records which backend held it before. To clear the report for an
  index that is no longer used, clear its data on the backend and delete
  the index (Search API index configuration); an index still in use is
  enabled again instead.
- Category-specific overrides via taxonomy term fields
- Implements data privacy protections through field anonymization

## What staff see after anonymization

- **Revision messages are replaced.** When a report is anonymized (on
  archiving, by the catch-up on cron or `markaspot:archive:backfill`, or by
  `drush markaspot:anonymize`), the free-text message of every revision of
  the report is replaced by "Message removed when the report was
  anonymized." This includes neutral internal notes without personal data:
  free text cannot be told apart reliably, so all of it is replaced. The
  structured history (status and field changes, author, date) stays.
- **Later notes are replaced too.** A note staff add to a report that is
  already archived is replaced by a later catch-up pass, not necessarily
  the next cron run: cron works through the archived reports in bounded
  batches from a cursor, so it may take several runs to come back to the
  report. `markaspot:archive:backfill` covers all of them in one go.
- **One combined revision.** The queue archives a report in one save: the
  archived status, unpublishing and anonymization appear as one revision in
  the history, not as two.
- **After a database lock conflict.** Archiving (queue) and the catch-up
  run each report in one transaction. When the database aborts it with a
  deadlock or a lock wait timeout (MySQL errors 1213 and 1205, SQLSTATE
  40001), the whole transaction is rolled back and nothing of the report
  has changed. The catch-up then tries the report again right away, up to
  three attempts with a short pause, each retry logged as a warning in the
  `markaspot_archive` log; after the third failed attempt it tries the
  report again on a later run. Inside an outer database transaction (code
  that calls the catch-up within its own transaction) nothing is retried:
  the conflict rolled back the outer transaction too, so only its owner
  can retry. Archiving from the queue makes one attempt and is not retried
  right away; the queue processes the item again on a later cron run, as
  after any other failure.
- **Known limit: mail before a rollback.** Archiving from the queue saves
  the report like an ordinary status change, so its save hooks run: the
  citizen status notification and ECA models may send mail. When a later
  step of the same archive transaction fails (for example erasing the
  previous revisions, or a lock conflict), the rollback restores the
  report but cannot take back mail that was already sent, and the queue
  retry may send it again. This limit predates the one-transaction archive.
  The catch-up is not affected: its saves are silent and ECA is off.
- **Known limit: lock order.** Archiving locks the report before its
  paragraphs, while saving a report with paragraphs (for example a staff
  edit) locks a paragraph first. When both meet at the same moment, the
  database aborts one of them. The archive side is retried as described; a
  staff save chosen by the database fails with an error and has to be
  saved again. The lock order itself is unchanged.
