# JSON API (iOS app)

Bearer-token authenticated JSON API for the iOS client, living alongside the
session-cookie web app. Entry point: `api/index.php`, reached via `.htaccess`
rewrites for any request under `/api/`.

## Auth

1. `POST /api/auth/login` with `{ "email", "password", "device_name"? }` →
   `{ "token", "user" }`. The token is a 64-char hex string; store it in the
   iOS Keychain and send it as `Authorization: Bearer <token>` on every
   other request. A user can hold multiple tokens (one per device) — each
   is independent and only its SHA-256 hash is stored server-side.
2. `POST /api/auth/logout` (authenticated) revokes the token used on the
   request.
3. `GET /api/me` (authenticated) returns the current user's profile.

Login mirrors the web login's checks: account must be active, email
verified, and (if maintenance mode is on) the user must be an admin.
Failed attempts are rate-limited the same way (5 per 15 min per IP+email).

## Request/response format

- Send a JSON body with `Content-Type: application/json` for POST/PUT.
- Every response is JSON. Errors are `{ "error": "message" }` with a
  matching HTTP status (401/403/404/422/429/500/503).
- Dates are `YYYY-MM-DD` strings; datetimes are whatever MySQL returns
  (`YYYY-MM-DD HH:MM:SS`).

## Endpoints (v1 — core only)

| Method | Path                              | Notes |
|--------|-----------------------------------|-------|
| POST   | `/auth/login`                     | Public |
| POST   | `/auth/logout`                    | |
| GET    | `/me`                             | |
| GET    | `/vehicles`                       | Active vehicles for the user |
| POST   | `/vehicles`                       | `make`, `model`, `year` required |
| GET    | `/vehicles/{id}`                  | |
| PUT    | `/vehicles/{id}`                  | Partial update, any subset of fields |
| GET    | `/insurance`                      | Current policy + status per vehicle |
| GET    | `/insurance/vehicle/{vehicleId}`  | Current + full history for one vehicle |
| POST   | `/insurance`                      | `vehicle_id`, `provider`, `expiry_date` required |
| DELETE | `/insurance/{policyId}`           | |
| GET    | `/driving-license`                | Current licence (with status) + history |
| POST   | `/driving-license`                | `surname`, `other_names`, `license_number`, `expiry_date` required; `categories: ["B2", "C1", ...]` optional |
| DELETE | `/driving-license/{id}`           | |
| GET    | `/vehicles/{id}/service-records`  | All service records for one vehicle |
| POST   | `/service-records`                | `vehicle_id`, `mileage`, `oil_interval` required; `mileage` must be ≥ the vehicle's current mileage, `oil_interval` must be one of the admin-configured intervals (default 7000–10000 km, 500 km steps) |
| GET    | `/item-types`                     | `{id, name, km_interval, months_interval}` — the admin-managed catalog used for `item_type_id` below |
| GET    | `/service-records/{id}/items`     | Parts/items logged against one service record |
| POST   | `/service-records/{id}/items`     | `item_type_id` required (must reference `/item-types`); accepts `item_name`, `brand`, `part_number`, `quantity` (default 1), `cost`, `notes` |
| PUT    | `/service-items/{id}`             | Same fields as create |
| DELETE | `/service-items/{id}`             | |
| GET    | `/vehicles/{id}/fuel-logs`        | All fuel log entries for one vehicle |
| POST   | `/fuel-logs`                      | `vehicle_id`, `mileage`, `liters`, `price_per_liter` required; `total_cost` is computed server-side |
| PUT    | `/fuel-logs/{id}`                 | Same fields as create — `vehicle_id` may be reassigned to a different (own) vehicle |
| DELETE | `/fuel-logs/{id}`                 | |
| GET    | `/expense-categories`             | `{id, name, icon}` — admin-managed, not a fixed enum |
| GET    | `/vehicles/{id}/expenses`         | All expenses for one vehicle, each joined with its category name/icon |
| POST   | `/expenses`                       | `vehicle_id`, `category_id` required, plus `amount` OR `quantity` + `cost_per_unit` (auto-multiplied unless the category is "Mechanic") |
| PUT    | `/expenses/{id}`                  | Same fields as create; 404s if the expense mirrors a service item (`service_item_id` set) — those are edited via their service item, not directly |
| DELETE | `/expenses/{id}`                  | Same service-item lock as `PUT` |
| GET    | `/vehicles/{id}/maintenance-schedule` | Every tracked part for one vehicle, each with a computed `status` (`overdue`/`due_soon`/`upcoming`/`ok`) |
| PUT    | `/maintenance-schedule/{id}`      | Only `interval_km`, `interval_months`, `priority` (`low`/`medium`/`high`/`critical`) are editable |
| GET    | `/reports?year=YYYY&vehicle_id={id}` | Spend summary — both query params optional (`year` defaults to the current year, omitting `vehicle_id` covers every vehicle) |

Creating a service record, fuel log entry, or expense with a `mileage`
bumps the vehicle's `current_mileage` the same way the web forms do (and
logs to `mileage_log`, except fuel entries — matching an existing
inconsistency in the web app). An expense with an `item_type_id` on a
tracked category (anything but Mechanic/Accessories) feeds
`maintenance_schedule` via `PartMaintenanceSyncService`, same as the web
form.

There's no `POST` for maintenance schedule — rows are created only as a
side effect of an expense on a tracked item type (see `POST /expenses`
above and `PartMaintenanceSyncService`), same as the web app.

`GET /reports` covers: all-time totals, the selected year's totals +
monthly breakdown, a per-vehicle breakdown for that year, and expense
totals by category for that year. It does **not** include the web
report's service-items breakdown, per-category transaction drill-down, or
Parts Longevity — the last one substantially overlaps with
`GET /vehicles/{id}/maintenance-schedule`, which is the mobile-friendly
equivalent.

Service records have no edit/delete endpoint — the web app doesn't offer
those either (only the linked `service_items` sub-resource and its
cascading `service_cost` can change a record after creation), so this
matches existing behavior rather than being a gap.

Adding/editing/deleting a service item recomputes its parent service
record's `service_cost` (`SUM(cost × quantity)` across all its items) and
mirrors the change into `expenses` via `ServiceItemExpenseSync` — so it
shows up in `GET /vehicles/{id}/expenses` and `GET /reports` with
`service_item_id` set, same as the web app. Those mirrored expense rows
stay locked (see `PUT /expenses/{id}` above) — edit them through their
service item instead.

**Not yet supported in the API** (use the web pages for these): insurance
sticker / driving licence scan upload / service dashboard photo / expense
receipt upload, vehicle deletion. These are the next milestone once the
core flow above is working end-to-end on the iOS side.

## Adding this to a new host

The `api_tokens` table is created lazily on first request (same
self-migrating pattern as `vehicle_insurance` / `driving_licenses`) — no
manual migration step needed. Just make sure `mod_rewrite` is on and
`.htaccess` overrides are allowed for the app's directory.
