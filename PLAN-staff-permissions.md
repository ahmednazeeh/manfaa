# Round plan — fine-grained merchant staff permissions

Started **2026-08-16**. PLAN.md §13b is the long-lived record; this file is the
scratch that survives a context loss. Fold it in and delete it when the round
lands.

Owner instruction, verbatim:

> We need Staff Roles feature fine grained roles. With ability to edit staff
> role and manage role permissions and custom roles
> Must have important Credit customer permissions like
> - Credit Customer
> -- Custom cashback for sales
> - Settlements
> -- View Settlements
> -- Create Settlements
> - Product Categories
> -- View Product Categories
> -- Create New Product Categories
> -- Edit Product Categories
> - Bank Account
> -- View
> -- Update
> etc

The "etc" is doing real work: the catalogue has to cover everything the panel
can do, not only the four groups named. Anything left uncovered silently
becomes either "everyone may" or "nobody may", and both are wrong.

---

## 1. What exists today

Three RANKED tiers — `staff < manager < owner` — compared by array index in
`MerchantUser::hasRoleAtLeast()`. Authority is expressed three different ways:

1. **Route middleware** — `merchant.role:manager|owner`
   (`EnsureMerchantRole`), 403 with code `manager_required` / `owner_required`.
2. **In-controller checks** — the finer decisions the middleware cannot express.
   `CreditController:69` refuses a per-sale `cashback_rate_percent` below
   manager: that IS the owner's "Custom cashback for sales", already real,
   just welded to a tier.
3. **Nothing at all** — every merchant route without a gate is implicitly
   "any authenticated staff member". Each of those needs a deliberate
   permission too, or the refactor quietly opens or closes it.

`StaffService` holds the safety rule: the last active OWNER can be neither
demoted nor deactivated, serialised by a Postgres advisory lock. Managers
deliberately do not count towards it, because a store whose only owner stepped
down could no longer touch its bank account or mint accounts.

The panel mirrors all of this client-side (`lib/roles.ts`, `menu.ts` `minRole`,
`role-gate.tsx`, per-page `canManage` consts).

---

## 2. The shape of the change

### 2.1 The catalogue is CODE, the assignment is DATA

A permission that nothing checks is not a permission, so the list of
permissions is a PHP enum shipped with the code that enforces it. What a
merchant stores is only *which* of them a role holds. This also means adding a
permission is a deploy, not a data migration — and see §2.3 for why that
matters.

Draft catalogue, grouped as the panel is grouped (Till / Money / Marketing /
Store / Account). Final list comes out of the survey; this is the spine:

| Group | Permission | Gates |
|---|---|---|
| Till | `credit.create` | Credit a customer at all |
| | `credit.custom_rate` | Override the cashback rate on one sale |
| | `credit.amend` | Correct a sale inside the validation window |
| | `credit.cancel` | Cancel a sale inside the validation window |
| | `customers.lookup` | Resolve a customer code at the till |
| | `transactions.view` | The sales list |
| Money | `settlements.view` | |
| | `settlements.create` | Submit a settlement with its slip |
| | `dashboard.view` | Money owed, the reconciliation summary |
| Marketing | `promotions.view` / `.create` / `.publish` / `.cancel` | Publish is separate: it is the irreversible one |
| | `rate.view` / `rate.update` | The standing cashback rate |
| Store | `product_categories.view` / `.create` / `.update` | |
| | `branches.view` / `.manage` | Includes the map pin |
| | `profile.view` / `.update` | Name, category, channel, terms, logo |
| Account | `bank_account.view` / `.update` | |
| | `staff.view` / `.manage` | Invite, edit, deactivate |
| | `roles.manage` | Create and edit ROLES themselves |
| | `api_credentials.view` / `.manage` | |
| | `preferences.manage` | |

`rate.update` and `credit.custom_rate` stay distinct on purpose: letting a
supervisor sweeten one sale is a different trust level from moving the store's
published rate.

### 2.2 Storage

- `merchant_roles` — `id`, `merchant_id`, `name`, `slug`, `permissions` jsonb,
  `is_owner` bool, `is_system` bool, timestamps. Seeded per merchant with
  Owner / Manager / Staff carrying today's exact powers, so the migration is
  behaviour-preserving. Merchants add their own beyond that.
- `merchant_users.merchant_role_id` replaces the `role` string. The migration
  maps each existing string to that merchant's seeded row, then drops the
  column and its CHECK constraint.

Rejected: permissions as a jsonb column straight on `merchant_users`. The owner
asked for reusable roles, and per-user permission bags mean editing eleven
cashiers one at a time.

### 2.3 The owner role is unbounded, not a big list

`is_owner` roles return true for EVERY permission check, including permissions
that do not exist yet. If the owner role were stored as an enumerated list, the
next deploy that adds a permission would lock every owner out of the new
feature until someone edited their role by hand. It is also un-deletable and its
permission set is un-editable — the last-owner guard is worthless if the owner
role can be stripped of `staff.manage`.

### 2.4 One enforcement path

`EnsureMerchantRole` becomes `EnsureMerchantPermission`
(`middleware('merchant.can:settlements.create')`). The in-controller checks
become `$user->can('credit.custom_rate')` against the same catalogue. Refusals
keep a machine-readable code — `permission_required` plus the slug — so the
panel can name what is missing rather than saying "forbidden".

Composition matters: a permission gate must not bypass `EnsureMerchantApproved`.
Permission answers *who*, approval answers *whether the store may trade at all*,
and both still have to pass.

### 2.5 The panel

`/me` must return the resolved permission SET, never the role name. A set has
no order, so every `hasRoleAtLeast(a, b)` comparison has to be rewritten rather
than patched — `menu.ts`'s `minRole`, `firstSettingsPathFor`, `role-gate.tsx`
and every per-page `canManage`.

New screen: Settings › Roles — list, create, edit permissions by group with
checkboxes, assign to staff, delete (guarded: a role in use cannot be deleted;
the owner role cannot be touched).

---

## 3. Rules that must survive the refactor

1. **No lockout.** A merchant always has at least one active user holding an
   `is_owner` role. The advisory-locked last-owner guard moves across intact.
2. **Nobody escalates themselves.** A user with `roles.manage` must not be able
   to grant their own role a permission it lacks, or assign themselves the
   owner role. Otherwise `roles.manage` silently equals owner.
3. **Hiding a control is not a control.** Every place the panel hides a button
   must have a server-side permission check behind it.
4. **Vendor tokens are a separate axis.** Sanctum abilities on `/v1` are not
   staff permissions; confirm in the survey that nothing crosses over.
5. **Behaviour-preserving migration.** Existing owner/manager/staff users keep
   exactly the powers they have today, proven by keeping the current
   authorization tests passing against the seeded preset roles.

---

## 4. Settled after the survey (2026-08-16) — do not re-open

- **D1. Clean cut, no dual-write.** Production holds 0 merchants and 0
  merchant_users, so there is nothing to migrate carefully around. The
  behaviour-preserving seeding still gets written properly, because the test
  suite and `DemoSeeder` create owner rows and a recovered pre-wipe dump would
  need it.
- **D2. The 403 becomes `permission_required`** with the missing slug in a
  `permission` field. `owner_required` / `manager_required` are removed, not
  kept — including from `SettlementErrorCodeSchema`, a CLOSED zod enum at
  `packages/api-client/src/merchant.ts:403-410` that bakes `manager_required`
  in. That enum and the API must change in one commit or settlement error
  handling silently falls through.
- **D3. `/me` carries a RESOLVED flat permission array**, and the owner's
  wildcard is expanded against the catalogue before it goes on the wire. Ship
  the sentinel and the panel's `permissions.includes('bank_account.update')`
  returns false for the owner — locking out the one account the wildcard exists
  to protect. The panel invalidates its `me` query after a role edit; the
  server is authoritative from the next request regardless.
- **D4. The last-owner guard keys on the immutable `is_owner` ROLE flag**, not
  on a permission. Keying it on `staff.manage` would silently let a store demote
  its only owner as long as some custom role also held that permission —
  changing the very semantics the guard's comment defends.
- **D5. You may only delegate what you hold.** A user with `roles.manage`
  cannot grant a role any permission they lack, cannot create or assign an
  owner-flagged role, and cannot edit their own role. Without this,
  `roles.manage` silently equals owner.
- **D6. `GET /merchant/bank-account` is new.** The owner asked for "Bank
  Account — View" and no such route exists; the value only reaches the panel
  today embedded in settlement payment instructions.
- **D7. Roles carry `name` and `name_dv`.** Existing role labels are i18n keys,
  which custom names cannot be. Presets seed both.
- **D8. Catalogue is published BOTH ways**, copying the `VendorAbility`
  precedent exactly: a PHP enum, a `MERCHANT_PERMISSIONS` TS const with an
  `isMerchantPermission` narrowing for compile-time safety on `can('…')`, and a
  `GET /merchant/permissions` endpoint returning slug + label + group so the
  roles screen renders groups without hardcoding them.
- **D9. Owner role is frozen** — renameable, permissions un-editable,
  un-deletable. Manager and Staff seed as ordinary editable roles; a role in use
  cannot be deleted. Cap at 20 roles per merchant.
- **D10. Admin panel needs no change.** Verified: no admin controller touches
  `MerchantUser`, and `apps/admin` has no merchant-role reference outside the
  vendored template.
- **D11. `docs/openapi.yaml` needs no edit.** It carries only the `/v1` vendor
  contract. Panel permissions use `group.action` (DOT); vendor abilities keep
  `group:action` (COLON) — a cheap, readable separation that must be preserved.

### Traps the survey found, which the build must respect

1. **Never carry staff permissions on Sanctum abilities.** `MerchantUser` uses
   `HasApiTokens`, and a session-authenticated user gets a `TransientToken`
   whose `can()` returns true for EVERY ability. `EnsureVendorCredential` is
   what keeps the two axes apart today.
2. **Middleware order is load-bearing.** The role gate runs BEFORE
   `EnsureMerchantApproved` everywhere, so a 403 short-circuits the 409. Move
   the permission gate after it and an unauthorised account starts learning the
   store's commercial standing — and every matrix row asserting 403 flips.
3. **Seven surfaces are open today and become permissions** — `credits.create`,
   `customers.lookup`, `transactions.view`, `rate.view`, `promotions.view`,
   `product_categories.view`, `settlements.view`/`preview`, `wallet.view`. Every
   one is a NARROWING: the seeded Staff role must grant them all on day one or
   every till in the field loses its main screen.
4. **Two schemas describe `/me`** — `MerchantAuthUserSchema`
   (`packages/api-client/src/onboarding.ts:130-142`) and `MerchantMeSchema`
   (`apps/merchant/lib/api.ts:27-43`), and the panel imports the latter. Zod
   strips unknown keys, so adding `permissions` to one leaves the panel with
   `undefined` and no type error, and every gate silently denies. Collapse them.
5. **Tenant-scope the role id.** `merchant_role_id` is a shared sequential
   integer; `exists:merchant_roles,id` alone lets a merchant attach staff to
   another merchant's role. No such bug class exists today because roles are
   literal strings.
6. **Keep the typo-throws property.** `EnsureMerchantRole` throws on a gate
   naming an unknown tier, so a typo is a 500 and never an open door. The
   replacement must throw on a slug outside the catalogue.
7. **Keep the factory state names.** 72 test files build a `MerchantUser`; 58
   use `->owner()/->manager()/->staff()`. Repoint those three states at seeded
   roles and ~54 files need no edit at all.
8. **`RateController:71` and `PromotionController:214` duplicate their route
   gates** with a plain 403 and no code. Convert them consciously — a
   `hasRoleAtLeast` left behind after `ROLES` is deleted is either a fatal or a
   silent always-false that locks everyone out.
