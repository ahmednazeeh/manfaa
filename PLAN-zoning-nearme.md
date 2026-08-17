# Round: Island Zoning, Near Me & Compact Discover

Owner-requested (2026-08-17): compact Discover with expandable search + location
picker + Near me; admin-drawn island zones; branch geolocation; store page
contact + map.

## Concepts

- **Zone** = an island, drawn by the admin as a polygon ("line of dots" around
  the island) and named. If the admin skips the name, we reverse-geocode the
  polygon centroid (Nominatim/OSM) and use the place name from the map.
- **A branch falls into the zone containing its point** (ray-cast
  point-in-polygon). Assignment is stored (`merchant_branches.zone_id`) and
  recomputed whenever a zone or a branch's coordinates change — reads stay
  cheap, geometry runs at write time.
- **Near me** uses the device location against branch coordinates (existing
  Haversine nearby shelf). The location picker offers: Near me (GPS) or any
  zone by name.

## Task list

### 1. API (Laravel) — the contract everything else builds on
- [x] `zones` table: `name`, `name_dv?`, `polygon` (jsonb, ≥3 [lat,lng] points), timestamps.
- [x] `merchant_branches.zone_id` nullable FK + index.
- [x] `Domain/Zoning`: `PointInPolygon` (ray casting), `ZoneAssigner`
      (assign one branch / reassign all branches for a zone), centroid helper.
- [x] Skipped-name resolution happens CLIENT-side in admin (google.maps
      .Geocoder on the polygon centroid — the key and loader already exist);
      the API requires a non-empty name. No server-side geocoding dependency.
- [x] Admin CRUD: `GET/POST /admin/zones`, `PUT/DELETE /admin/zones/{zone}`
      (+ branch counts in the list). Saving a zone reassigns affected branches.
- [x] Branch save hooks (admin + merchant settings): accept `lat`/`lng`,
      assign `zone_id` on write.
- [x] Customer: `GET /discover/zones` (id, name, name_dv, store_count);
      `/discover?zone=ID` filters every shelf to merchants with a branch in
      that zone (Near me continues to use lat/lng — mutually exclusive).
- [x] Store page payload: branches carry `lat`/`lng` (v9 cache bump if shape changes).
- [x] Tests: polygon math edges, assignment on zone/branch writes, zone filter,
      skipped-name geocode (faked HTTP), admin authz.

### 2. Admin web (Next.js) — zoning UI
- [x] Zones page: Google Map (the shared `loadGoogleMaps` loader; add
      `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY` to apps/admin env) with the `drawing`
      library — click dots around an island to close a polygon; optional name
      field ("leave empty to use the island's name from the map") resolved via
      reverse-geocoding the centroid; list existing zones with branch counts,
      edit polygon, rename, delete.

- [x] Replace the Metronic template logo in the admin panel with a Manfaa
      LETTER logo (the coral twin-peak "M" mark, matching the app).

### 3. Merchant settings web — branch location
- [x] ALREADY EXISTS (survey 2026-08-17): apps/merchant branch dialog + signup
      wizard both mount `LocationPicker` (Maldives-restricted Places + pin
      drag), and the API validates lat/lng as a pair. Nothing to build —
      verify only.

### 4. Customer app (Flutter)
- [x] **Compact Discover**: tighter header; search collapses to an icon that
      expands into the field; location pill opens a picker sheet (Near me +
      zone names from `/discover/zones`); category chips + featured card
      slimmed; merchant cards EXACTLY per the mockup: 2-column grid — round
      logo, bold name + grey blurb beside it, violet `X% Cashback` + category
      chip row, divider, `pin 0.3 km away` footer.
- [x] **Near me**: `geolocator` permission flow (ask on first use of Near me,
      never at launch); passes lat/lng to `/discover`; distances render on
      cards. Denied permission → picker falls back to zones gracefully.
- [x] **Store page**: contact card (call + website, `url_launcher`) and an
      OSM map (`flutter_map`) with branch pins when coordinates exist; hidden
      cleanly when not.
- [x] Android manifest: coarse+fine location permissions.
- [x] **Compact pass app-wide** (owner, mid-round): every screen's sizes come
      down — paddings, tile sizes, type scale, button heights. Scroll content
      must CLEAR the floating nav bar (Discover offers currently touch it):
      shared bottom clearance for all tab screens. Bottom bar gets MORE corner
      rounding than content cards (stadium pill), slightly slimmer.

### 4b. Admin follow-ups (owner, mid-round)
- [x] admin.manfaa.app/settings/notifications: REMOVE the Dhivehi body —
      notifications are always English (UI field gone; sending ignores dv;
      API stops claiming a dv channel).
- [x] App release flags are not editable anywhere: surface the mobile version
      gate (min/latest build + store URL per platform, currently env-only in
      config/mobile.php) as admin settings backed by the DB with env fallback.

### 5. Production bugs (owner, mid-round)
- [x] App payout detail: the bank transfer reference is missing — surface it.
- [x] Admin marked payout paid (admin.manfaa.app/payouts/3) but the customer
      got NO app push — trace mark-paid → notification → push job → device
      token and fix the break.

### 6. Landing v2 + admin merchant controls + avatars (owner, 2026-08-17)
- [x] Web landing (signed-in): show EVERY non-empty section — Featured,
      Boosted, Near me (browser geolocation on gesture), In Store, Online,
      Dining, Newly joined, Highest cashback. Owner's rule replaces the
      dedup-collapse: a section renders whenever it has stores.
- [x] Web landing (signed-out): merchant-side pitch — why join, the cashback
      model's advantages, why Rakuten/Honey run this model and brands like
      Dell/Microsoft/Samsung participate in it.
- [x] Admin /merchants: full superadmin controls — view (detail), edit
      profile, manual suspend + reinstate, staff list with password reset.
- [x] Customer profile pictures: upload/replace/remove via API (web session +
      mobile token), shown in the app (Profile + top-bar avatar) and web
      (header/dashboard). Merchant-logo storage pattern reused.

### 7. Web dashboard v2 + mobile web + admin customers (owner, 2026-08-17)
- [x] Dashboard desktop: Available-cashback HERO (32–36px value, tinted/
      accent card, View-transactions inside), Paid-this-month + Next-payout
      merged into one summary card, code card with bigger QR + fullscreen +
      copy, payout-minimum PROGRESS bar, brand accent used deliberately,
      stronger sidebar active state, consistent muted labels, recent-activity
      rows (merchant · amount · +cashback · date), wider content, footer gone
      from authed screens.
- [x] Mobile web: bottom nav (Home/Dashboard/Transactions/Bank Acc/More),
      proper side margins in en + dv, code card first.
- [x] Admin: superadmin customer management — search/list, detail (masked
      payout account), edit name/email/PHONE, one-time password reset,
      enable/disable with token+session revocation.

## Decisions
- **Web maps = Google Maps** (the shared loader + key already power the
  merchant picker and web nearby map — consistency wins). **App map =
  flutter_map + OSM tiles** — no key baked into the APK, sidestepping the
  still-open key-restriction blocker.
- Zone polygons are small (island-scale, dozens of points) — jsonb + PHP
  ray-casting is plenty; no PostGIS dependency.
- Distance strings stay server-side (`distance_m` already in the feed).
