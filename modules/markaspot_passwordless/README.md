# Personal dashboard form layout

`GET|PATCH /api/auth/preferences/form-layout/{jurisdiction_id}` stores a
personal arrangement of whole dashboard form sections. This endpoint changes
no form display, field value, visibility rule or access grant.

The account comes exclusively from the authenticated Drupal cookie session.
The jurisdiction must resolve to a published jurisdiction group readable by
that account. PATCH additionally requires the standard `X-CSRF-Token` header.
Responses use `Cache-Control: private, no-store`. CivicSpot workspace demo
accounts (`DemoStaffAccount`) get the default layout and cannot save a
preference. Other shared logins share one layout per account.

GET returns `{ "layout": null, "writable": true, "workspaceDefault": null,
"workspaceDefaultWritable": false }` when no override exists.
`workspaceDefault` is the workspace default described below for the
jurisdiction in the path (demo accounts get it as well). The effective layout
is the personal one, else the workspace default, else the built-in layout.
`workspaceDefaultWritable` says whether the account may change that default:
it applies the same rule as the PATCH below (tenant settings access to the
jurisdiction and to its root) and is always `false` for demo accounts, so the
edit form can hide "save as default" instead of guessing.
PATCH accepts exactly one `layout` property:

```json
{
  "layout": {
    "version": 1,
    "left": ["details", "location", "media", "contact", "status_history"],
    "right": ["status_actions", "context", "service_provider", "internal", "related"]
  }
}
```

Each stable section ID must appear exactly once. Unknown keys, versions,
missing/duplicate IDs and bodies larger than 2 KiB are rejected. To restore
the shared default, PATCH `{ "layout": null }`.

Storage uses core `user.data`, module `markaspot_passwordless`, name
`form_layout.{resolved_jurisdiction_id}` and the current UID. No field schema,
update hook or user-entity save is needed. The existing language-preference
endpoint remains unchanged. A regular cache rebuild discovers the new route.

## Workspace default

`GET|PATCH /api/tenant-settings/{jurisdiction_id}/form-layout` reads and sets
one layout for the whole tenant. It is stored on the ROOT jurisdiction's
`field_nuxt_config` at `staffForms.editLayout`; sub-jurisdictions read the root
value. Access follows `TenantSettingsController::accessCheck()` (tenant admin
of the jurisdiction or a parent, or a platform administrator), cookie session
only, and PATCH requires `X-CSRF-Token`. A write through a sub-jurisdiction
also requires that access for the root. Demo accounts get 403.

- GET answers `{ "editLayout": <layout or null> }`.
- PATCH accepts exactly `{ "editLayout": <layout or null> }` and answers with
  the stored value; `null` removes the default. Other keys of
  `field_nuxt_config` stay unchanged.
- 400 for a body that is not JSON or larger than 2 KiB, 422 for any other
  body or a layout that fails `validLayout()`, 403 as described above.

`staffForms` is deliberately not one of the `field_nuxt_config` keys that the
public settings response (`/api/mark-a-spot-settings`) passes through, so the
staff form layout never reaches anonymous visitors. Keep it out of that list.

This feature belongs to the active Drupal 11 line. It has no Drupal 10
backport.
