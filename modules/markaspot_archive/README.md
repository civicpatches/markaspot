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
- Category-specific overrides via taxonomy term fields
- Implements data privacy protections through field anonymization