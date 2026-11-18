# Mark-a-Spot Publisher

This module provides automatic publishing capabilities for service requests in Mark-a-Spot.

## Features

- Configurable automatic publishing of service requests based on:
  - Time elapsed since creation
  - Request status
  - Category-specific settings
- Respects intentional unpublishing by administrators
- Background processing via cron jobs

## Configuration

Navigate to **Admin → Configuration → Mark-a-Spot → Publisher Settings** (`/admin/config/markaspot/publisher`) to configure:

- Publishable status terms
- Default publishing period (days)
- Manual unpublish detection threshold
- Cron settings (enable/disable, interval)
- Category-specific overrides

## Unpublish hold

After upgrade, taking a published report offline places it on hold until someone explicitly republishes it. A citizen who withdraws a report through the privacy form also places it on hold, even if it has never been published. Deleting the report removes the hold.

There is no backfill for reports taken offline before the upgrade. They remain subject to the existing publisher rules. Staff can keep a never-published report offline by assigning it a non-publishable workflow status. Drupal Workspaces and direct SQL writes are outside the scope of the hold.

The hold list grows as reports are archived. The publisher reads the list once per run for all categories. The queue worker checks each report's hold again immediately before publication.

## Integration

This module works with:

- Mark-a-Spot service request workflow
- Taxonomy-based status management
- Drupal's cron system

## Technical Notes

- Uses a queue worker to process publishing operations
- Category-specific overrides via taxonomy term fields
- Implements safeguards against publishing manually unpublished content
