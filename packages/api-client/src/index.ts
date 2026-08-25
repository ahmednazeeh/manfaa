export {
  ApiError,
  apiBaseUrl,
  apiErrorCode,
  apiFetch,
  apiFetchBlob,
  apiFetchDownload,
  // The credential-LESS GET, for endpoints that are unauthenticated by design
  // and whose answer must not depend on who is asking (the public
  // fee-promotion banner on the merchant landing page).
  apiFetchPublic,
  apiFetchText,
  bootstrapCsrf,
  filenameFromContentDisposition,
  type ApiDownload,
  type ApiFetchOptions,
} from './client';
export { formatLaari, parseMvrToLaari } from './money';
// The ONE percent <-> basis-points conversion (PLAN §1 wire format): rates
// are 2-decimal percent STRINGS on every request and response, and basis
// points exist only for arithmetic inside an app. Integer/string math only.
export {
  bpToPercentString,
  formatBpPercent,
  formatPercent,
  isPercentDeltaString,
  isPercentInput,
  isPercentString,
  parsePercentToBp,
  percentDeltaToBp,
  percentToBp,
} from './percent';
// The store description's word ceiling, and the count that decides it — the
// client mirror of the API's App\Rules\MaxWords, so a counter can turn red
// on exactly the text the server would refuse.
export {
  countWords,
  isOverWordCeiling,
  STORE_DESCRIPTION_MAX_WORDS,
} from './words';
export {
  LoginRequestSchema,
  UserSchema,
  type LoginRequest,
  type User,
} from './schemas';
// Shared resource schemas (settlements, wallet, payouts, promotions, claims,
// pagination).
export * from './resources';
// Merchant surface: outstanding, settlements, wallet, credits, promotions,
// and the settings module (profile, bank account, branches, staff, roles and
// the permission catalogue they are built from, preferences, customer
// lookup, self-serve API credentials).
export * from './merchant';
// Admin surface: settlement queue, merchants, reconciliation, payout batches,
// claims queue, promotions listing, platform bank accounts, fee tier
// schedules, platform settings, the GST tax-settings switch (read by any
// admin, written by a superadmin), admin users, the superadmin reports
// (preview + .xlsx export), and the console landing dashboard — one call for
// every panel, with the money and chart sections ABSENT (never zeroed) for
// an admin who is not a superadmin.
export * from './admin';
// Admin hold-review queue (Task #22): the on_hold list with its filters and
// counts, plus the two decisions — release (clock stamped) and reject
// (accrual mirrored).
export * from './holds';
// Customer surface: auth + OTP signup, balance, transactions, payout
// account, claims.
export * from './customer';
// Public discovery: no auth, typed sections.
export * from './discover';
// Platform fee promotions (owner, 2026-08-25): the superadmin settings row
// (both kinds — introductory and platform-wide), the authenticated merchant's
// own banner, and the unauthenticated offer the merchant landing page shows a
// stranger. A promotion lowers the fee MANFAA charges a merchant; it is NOT
// the cashback promotion in ./resources.
export * from './fee-promotions';
// Store onboarding (Task #24): merchant self-signup OTP flow, the resumable
// setup wizard (profile / logo / rate / submit), the admin store approval
// queue, and curated store-category CRUD.
export * from './onboarding';
