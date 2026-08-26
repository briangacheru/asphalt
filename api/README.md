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
| GET    | `/vehicles/{id}/fuel-logs`        | All fuel log entries for one vehicle |
| POST   | `/fuel-logs`                      | `vehicle_id`, `mileage`, `liters`, `price_per_liter` required; `total_cost` is computed server-side |
| GET    | `/expense-categories`             | `{id, name, icon}` — admin-managed, not a fixed enum |
| GET    | `/vehicles/{id}/expenses`         | All expenses for one vehicle, each joined with its category name/icon |
| POST   | `/expenses`                       | `vehicle_id`, `category_id` required, plus `amount` OR `quantity` + `cost_per_unit` (auto-multiplied unless the category is "Mechanic") |

Creating a service record, fuel log entry, or expense with a `mileage`
bumps the vehicle's `current_mileage` the same way the web forms do (and
logs to `mileage_log`, except fuel entries — matching an existing
inconsistency in the web app). An expense with an `item_type_id` on a
tracked category (anything but Mechanic/Accessories) feeds
`maintenance_schedule` via `PartMaintenanceSyncService`, same as the web
form.

**Not yet supported in the API** (use the web pages for these): insurance
sticker / driving licence scan upload / service dashboard photo / expense
receipt upload, vehicle deletion, editing or deleting service
records/fuel logs/expenses, service items (the sub-resource under a
service record), maintenance schedule, reports. These are the next
milestone once the core flow above is working end-to-end on the iOS side.

## Adding this to a new host

The `api_tokens` table is created lazily on first request (same
self-migrating pattern as `vehicle_insurance` / `driving_licenses`) — no
manual migration step needed. Just make sure `mod_rewrite` is on and
`.htaccess` overrides are allowed for the app's directory.
