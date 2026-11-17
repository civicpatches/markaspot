# Mark-a-Spot Nuxt

This module provides integration with Nuxt.js for a decoupled front-end experience in Mark-a-Spot.

## Features

- JSON API endpoints for Nuxt.js front-end
- Configuration sharing between Drupal and Nuxt
- Authentication handling for decoupled front-end
- Form mode settings for Nuxt integration

## Integration

This module works with:

- Mark-a-Spot service request system
- JSON API module
- Nuxt.js front-end application
- Configuration management

## Technical Notes

- Provides custom endpoints for Nuxt.js integration
- Implements settings controllers for configuration sharing
- Handles authentication and authorization for API access
- Enables progressive web app capabilities

## Correlation logging

The module requires Monolog and configures every channel to write JSON lines to
`php://stderr`, alongside all enabled Drupal loggers, including dblog, ECA and
syslog. Drupal log lines still reach Drush's console logger during CLI commands
such as `drush deploy`.
No site services file is required. Defaults are registered before site service
overrides, so a site can replace `monolog.channel_handlers` and
`monolog.logger.processors` in its own services YAML.
Sites importing configuration must also include `monolog` in the source
`core.extension` module list. Update hook `markaspot_nuxt_update_11921()` enables
it in active configuration, but a later configuration import must retain it.

`X-Request-Id` accepts a UUID v4. Invalid or missing values are replaced. Main
requests, subrequests, early HTTP bootstrap logs and response headers use the
same correlation ID. An outer middleware refreshes the header on Internal Page
Cache hits. Entries outside an HTTP request use a null ID; the bootstrap
fallback is disabled for CLI. This UUID is separate from the report number
also named `request_id` elsewhere in the application.

Each stderr line contains only `timestamp`, `service`, `request_id`, `channel`,
`level` and `message`. Context, exceptions and request metadata are never dumped.
Only numeric/boolean operational placeholders (counts, limits, timing, status,
codes, attempts and sizes), the validated correlation UUID and exception code
locations (`%type`, `%function`, `%file`, `%line`) are substituted unchanged.
Exception messages and backtraces remain redacted. Other placeholder values
are replaced with `[redacted]`. Message templates must not contain literal
request bodies or personal data; common
credential, URL, address and IP patterns receive additional redaction. Dblog
keeps its existing message template and variables, plus `@correlation_id` for
lookup. Context uses `correlation_id` and `@correlation_id`, preserving existing
report-number keys; the JSON field remains `request_id`. Messages are capped at
16 KiB, followed by `…[truncated]` when shortened. JSON escaping is also bounded
so that even control characters keep the complete line below 64 KiB.

Collect stderr with the container runtime or cluster log collector. This module
does not configure PHP-FPM stream forwarding, log rotation or retention.
