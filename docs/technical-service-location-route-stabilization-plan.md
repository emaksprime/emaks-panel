# Technical Service Location & Route Stabilization Plan

## Current Problem

- Geocode currently writes directly onto technician latitude/longitude fields. There is no durable separation between an active, reviewed coordinate and a newly suggested geocode candidate.
- Validate command previously behaved too aggressively and could delete reviewable coordinates. It now preserves reviewable coordinates, but the technical contract still needs to make destructive behavior impossible by design.
- Technician recommendation distance, approximate city/address distance, and Google Routes distance are easy to mix in the UI and backend payloads.
- Route quote is partially bound to technician/request coordinates, but the contract must explicitly require matching selected technician id, origin, destination, threshold, and fee config before showing a fee.
- Manual fee editor exists, but the audit contract is not explicit enough: what was calculated, what was overridden, who changed it, and why must be clear.
- Production export/seeder has a review gate, but the quality gate is not strict enough until active coordinates and candidate geocode results are split.
- Several current UI/test strings show mojibake in source files. This is not part of the route contract itself, but any stabilization phase that touches those files must avoid introducing more encoding damage.

## Non-Negotiable Rules

- Approximate distance never drives fee.
- Google Routes `distanceMeters` is the only automatic fee distance source.
- Technician coordinate cannot be silently overwritten by low-confidence geocode.
- Address/city edit never deletes coordinates automatically; it marks the coordinate stale/review.
- Route quote must match selected technician and current request coordinates.
- Manual override is explicit and auditable.
- `needs_review` coordinates are usable for preview, but cannot be auto-approved/exported.
- Red-zone datasource remains separate.
- Google API keys are server-side only; no key is exposed to browser payloads or logs.
- A failed geocode or failed route quote must not block the service request; it creates an operation warning/state.

## Data Contract

### Technician active coordinates

Active coordinates are the coordinates used for route preview and route quote attempts.

- `technical_service_technicians.latitude`
- `technical_service_technicians.longitude`
- `technical_service_technicians.start_latitude`
- `technical_service_technicians.start_longitude`
- `technical_service_technicians.location_source`
- `technical_service_technicians.route_note`

Rules:

- Active coordinates must be numeric and valid latitude/longitude.
- `0/0`, out-of-range, and outside-Turkey coordinates are not routeable.
- `needs_review=true` does not make a valid coordinate unusable; it only blocks auto-approval/export.

### Technician candidate geocode result

Candidate coordinates are Google-suggested results that have not been accepted as active coordinates yet.

Required contract:

- `candidate_latitude`
- `candidate_longitude`
- `candidate_formatted_address`
- `candidate_source_type`
- `candidate_location_type`
- `candidate_quality`
- `candidate_review_reason`
- `candidate_created_at`

Until this is implemented, new geocode flows must be treated as risky because the candidate can overwrite the active coordinate.

### Review and stale state

- `needs_review`: coordinate exists but requires operation/admin review.
- `coordinate_stale`: address/city/source fields changed after coordinate was recorded.
- `location_source`: `google_geocode`, `google_places`, `manual`, `seeded`, or `legacy`.
- `route_note`: short safe summary only. No raw Google response, no API key, no full debug payload.

Stale triggers:

- city changed
- district changed
- address changed
- Google Plus Code/location code changed
- formatted Google address changed
- default start address changed
- default start Plus Code changed
- cari address/city fields changed

### Request customer coordinates

Customer destination coordinates come from the request:

- `technical_service_requests.location_latitude`
- `technical_service_requests.location_longitude`
- `location_source`
- `location_note` or operation warning payload, when available

Rules:

- If customer selected a map location, use submitted lat/lng.
- If customer entered manual address without lat/lng, backend geocode may attempt to fill coordinates.
- If customer address geocode fails, request creation continues and operation sees a clear warning.

### Route quote origin/destination

Route quote must persist the exact coordinate pair used:

- `technical_service_route_quotes.technical_service_request_id`
- `technical_service_route_quotes.technician_id`
- `origin_latitude`
- `origin_longitude`
- `destination_latitude`
- `destination_longitude`
- `threshold_km`
- `fee_per_km`
- `provider`
- `status`
- `calculated_at`

Rules:

- UI can show a route quote only if `quote.technician_id === selectedTechnicianId`.
- Quote is stale if technician active coordinates changed after calculation.
- Quote is stale if request customer coordinates changed after calculation.
- Quote is stale if threshold or fee-per-km config changed.

### Route quote canonical payload

Canonical route fields:

- `one_way_distance_km`
- `round_trip_distance_km`
- `threshold_km`
- `billable_km`
- `fee_per_km`
- `fee_amount`
- `travel_fee_required`
- `status`
- `message`
- `source`: `google_routes` or `manual_override`

Rules:

- Google Routes `distanceMeters` is one-way.
- `round_trip_distance_km = one_way_distance_km * 2`.
- `billable_km = max(round_trip_distance_km - threshold_km, 0)`.
- `fee_amount = billable_km * fee_per_km`.
- If `fee_per_km` is missing, `fee_amount=null` and UI shows the missing fee setting message.
- Legacy `distance_km` may remain for compatibility, but UI must prefer canonical fields.

### Manual override fields

Manual override must carry:

- `manual_override`
- `manual_note`
- `manual_changed_by`
- `manual_changed_at`
- `manual_previous_payload`
- `manual_reason`

Rules:

- Manual override must never be hidden as an automatic Google Routes result.
- Saving manual fee must return fresh request detail payload.
- Manual override must not close the modal or reset scroll/accordion state.

## Backend Plan

1. Split geocode candidate from active coordinate.
   - Add candidate fields or a dedicated technician geocode candidates table.
   - Geocode command and endpoint write candidate data first.
   - Active technician coordinates change only when candidate is explicitly applied or manual coordinates are saved.

2. Add/apply candidate command or endpoint.
   - `technical-service:geocode-technicians` should support candidate-only mode.
   - Technician API should expose `POST /api/technical-service/technicians/{technician}/geocode-candidate`.
   - Technician API should expose `POST /api/technical-service/technicians/{technician}/apply-coordinate-candidate`.
   - Applying a candidate clears `coordinate_stale` only when quality rules pass or operation explicitly accepts review risk.

3. Make validate command non-destructive by default.
   - Default mode: report and mark `needs_review=true`.
   - `--clear-invalid`: only clears impossible coordinates: `0/0`, out-of-range, outside Turkey.
   - City mismatch, generic city result, duplicate coordinate, low-quality source, and short address never clear coordinates automatically.

4. Make route quote cache match selected technician + request coordinates + fee config.
   - Cache key must include request id, technician id, origin lat/lng, destination lat/lng, threshold km, fee per km, provider, and status.
   - Cached quote is invalid if status is not `calculated`.
   - Cached quote is invalid if fee config changed.
   - Cached quote is invalid if canonical fields are internally inconsistent.

5. Add or finalize manual route fee endpoint.
   - Manual route fee endpoint must save canonical fields plus override metadata.
   - It must not fake Google Routes source.
   - It must sync request travel summary after save.
   - It must return fresh serialized request detail.

6. Add export/seeder only for reviewed safe coordinates.
   - Export excludes `needs_review=true`.
   - Export excludes stale coordinates.
   - Export excludes generic city/country result.
   - Export excludes city mismatch unless explicitly reviewed and accepted.
   - Seeder updates only technical service technicians and never creates full DB dump behavior.

## Frontend Plan

1. Rename "Şehir içi uygun ustalar" to "Mesafe ve önceliğe göre önerilen ustalar".
   - The current list can include different cities, so "Şehir içi" is misleading.

2. Show approximate distance only as approximate.
   - Label: "Yaklaşık şehir/adres mesafesi".
   - This value is only a ranking hint.
   - It must never appear in fee calculation cards.

3. Show active coordinate / candidate coordinate / stale / review badges.
   - "Gerçek koordinat var"
   - "Gerçek koordinat yok"
   - "Koordinat kontrol gerekli"
   - "Adres değişti, koordinat yeniden doğrulanmalı"
   - "Google aday koordinatı var"
   - "Aday koordinat uygulanmadı"

4. Add "Google ile koordinatı güncelle" and "Koordinatı uygula" flows.
   - Google update creates/refreshes candidate.
   - Apply action writes candidate to active coordinate.
   - Manual coordinate save writes directly with `location_source=manual`.

5. Show route quote debug mini info.
   - selected technician id
   - quote technician id
   - route quote id
   - origin map link
   - destination map link
   - origin/destination coordinate pair
   - quote stale status

6. Make manual fee editor fully editable and sticky-save.
   - Editable fields: one-way km, round-trip km, threshold km, fee per km, billable km, fee amount, note.
   - One-way edit updates round-trip.
   - Round-trip edit updates one-way.
   - Threshold or fee-per-km edit recalculates billable km and fee amount.
   - Direct fee amount edit sets manual override.
   - Save returns fresh request payload and keeps modal state.

## Production Plan

- Coolify env:
  - `TECHNICAL_SERVICE_ROUTE_FEE_PER_KM=10`
  - `GOOGLE_GEOCODING_API_KEY`
  - `GOOGLE_PLACES_API_KEY`
  - `GOOGLE_ROUTES_API_KEY`
- Server key must be IP restricted for VPS public IP.
- Rotate any key that was pasted into chat.
- Run migrations.
- Run coordinate seeder only after export review.
- Run post-deploy refresh.
- Never run `db:wipe`, `migrate:fresh`, or `migrate:refresh`.
- Do not deploy safe branch alone if datasource red-zone review is still required for QR/Mount invoice serial flow.

## Test Plan

Add or update these tests:

- Geocode candidate accepted when Plus Code returns detailed same-city result.
- Geocode candidate flagged review when result is generic city/country.
- Geocode candidate flagged review when result city mismatches technician city.
- Candidate geocode does not overwrite active coordinates until explicitly applied.
- Validate command is non-destructive by default.
- `validate-technician-coordinates --clear-invalid` does not clear city mismatch/generic/duplicate coordinates.
- `validate-technician-coordinates --clear-invalid` clears only `0/0`, out-of-range, or outside-Turkey coordinates.
- Address edit sets stale/review state and keeps active coordinates.
- Manual latitude/longitude save validates numeric range and rejects `0/0`.
- Route quote is hidden/stale when selected technician id differs from quote technician id.
- Route quote is hidden/stale when origin/destination coordinates differ from current request/technician coordinates.
- Route quote cache is ignored when fee config changed.
- Manual override save writes explicit manual source and audit metadata.
- Export excludes `needs_review=true`.
- Export excludes stale candidate/active mismatch.
- Export excludes generic city/country result.
- Fee calculation example:
  - one-way Google Routes distance: `87.9`
  - round-trip distance: `175.8`
  - threshold: `30`
  - billable km: `145.8`
  - fee per km: `10`
  - fee amount: `1458`

## Implementation Phases

### Phase A: Stop destructive coordinate clearing

- Keep current non-destructive validation behavior.
- Tighten command help text so `--clear-invalid` clearly means impossible coordinates only.
- Add regression tests for city mismatch and generic result preservation.

### Phase B: Route quote binding and cache

- Make route quote stale checks a shared backend/frontend contract.
- Ensure selected technician mismatch never shows stale fee values.
- Ensure request coordinate changes invalidate route quote display.

### Phase C: Manual fee editor

- Make manual route fee endpoint canonical and auditable.
- Ensure UI editor writes explicit manual override metadata.
- Ensure fresh request payload updates detail panel without modal reset.

### Phase D: Candidate geocode flow

- Add candidate geocode persistence.
- Add apply-candidate action.
- Keep active coordinate stable until operation applies candidate.

### Phase E: Export/seeder production handoff

- Export only reviewed active coordinates.
- Keep raw Excel and raw Google response out of repo.
- Seeder updates only reviewed coordinate fields and remains idempotent.
