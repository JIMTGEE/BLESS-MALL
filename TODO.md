# Bless Mall Project TODO

## Scope and Working Rules

- [ ] Keep the site deployable on StormerHost shared hosting with PHP and MySQL; do not assume shell access, Composer services, or a framework that is not already installed.
- [ ] Work portal by portal for the roles in use: buyer, admin, seller, and deliverer. Finish and verify each portal before moving to the next.
- [ ] Before schema work, obtain a complete, current schema export (`SHOW CREATE TABLE` for every table, indexes, foreign keys, and role seed rows). `tables.txt` is a useful column snapshot, not a migration-ready schema dump; some enum definitions are truncated.
- [ ] Back up the production database before applying schema changes. Prepare exactly one consolidated SQL migration for the confirmed schema changes, for the owner to apply after the code work is complete. Do not run migrations automatically from page requests.
- [ ] After each implementation phase, run its focused checks, review the diff, and commit that completed phase to `main`. Keep unrelated/pre-existing working-tree changes out of those commits.
- [ ] Finish with a deployment checklist. The owner will upload the completed project through StormerHost File Manager and test on the hosted site.

## Initial Audit

Static inspection of the current workspace only. All PHP files pass `php -l`, and the editor reports no diagnostics. No production database, payment gateway, SMS service, or browser workflow was exercised, so live behavior and the exact production schema still need verification.

### Confirmed Problems

- [ ] **Critical: rotate exposed credentials when replacements are ready.** Existing database, Paystack, and SMS values are intentionally retained as defaults per the owner's request and remain exposed in source/history. Replace them with rotated values in private StormerHost configuration before production upload; never send replacement secrets through chat.
- [x] Add environment/private-file overrides for database, Paystack, and SMS settings, and document the StormerHost configuration location in `README.md`; existing values remain available until replacements are provided.
- [x] **Critical: production error handling.** PHP errors are no longer displayed by default, and `.htaccess` disables directory listings and blocks web access to error logs and common backup/dump files. Confirm StormerHost honors these Apache rules after upload.
- [x] Revalidate active products/tiers, current tier prices, approved/active vendor, quantity limits, and available stock against the database at order creation; changed prices require cart review, mixed-vendor carts are rejected, and stock reservation is conditional within the transaction. In-memory database cases pass.
- [x] **Critical: Paystack settlement.** AJAX and callback now share one settlement path; it checks order ownership, reference, successful status, GHS currency, and exact amount, then idempotently records the payment and status history in a transaction. Payment-data validation cases pass.
- [ ] **Critical: payment lifecycle.** Define the provider expiry/refund policy before releasing stock for abandoned payments; do not release while a Paystack transaction may still complete. Complete MySQL/gateway retry, duplicate-callback, and reconciliation tests.
- [x] Correct seller login routing to the seller-scoped product page and require both the deliverer role name and a deliverer profile for delivery access.
- [ ] **Critical: role isolation.** Finish per-page/admin action authorization and a dedicated seller dashboard; verify role IDs and vendor ownership using the live database.
- [x] **High: address write handling.** Replaced the undefined `sanitize()` calls, added CSRF-protected POST actions for save/delete/default, enforced address ownership, and made default changes transactional. Database-backed workflow testing remains in Phase 2 verification.
- [x] **High: cart/checkout delivery totals.** Centralized zone, express, GHS 300 free-delivery, and pickup calculations so cart and checkout quotes/order totals share one pricing helper. Focused fee cases pass; database-backed checkout testing remains outstanding.
- [x] **High: mixed-vendor orders are misrepresented.** Checkout now rejects carts containing more than one vendor instead of attributing all items to the first product's vendor. Separate vendor orders remain a future product decision.
- [ ] **High: delivery data paths are disconnected.** Admin delivery management reads `deliveries`; the deliverer dashboard reads `order_courier_assignments`; checkout does not create either record. Choose a canonical delivery lifecycle and ensure paid/COD orders enter it, admin assignment reaches the deliverer portal, and status changes update order tracking consistently.
- [ ] **High: CSRF coverage is inconsistent.** Checkout payment, buyer address, all seven cart mutations, admin settings, and admin delivery updates now validate CSRF. Review remaining account/admin writes.
- [x] **High: password reset implementation.** Added request/reset pages, one-hour emailed links, token invalidation, and remember-token revocation.
- [x] **High: runtime schema mutation.** Removed request-time DDL from authentication, product administration, and newsletter handling. Newsletter signup stays unavailable until its table is added by the final consolidated migration.
- [ ] **Medium: coupon/promotion model is unclear.** Cart treats a promotion's display `name` as a coupon code, while the schema has promotions and product-promotion links but no separate coupon-code model. Define eligible products, code uniqueness, expiry, usage limits, stacking, and discount caps; make cart and checkout revalidate promotion eligibility on the server.
- [ ] **Medium: schema constraints appear inconsistent.** The snapshot shows `wishlist.user_id` and `wishlist.product_id` each individually unique, which would limit a user to one wishlist item and a product to one user. It also shows `product_variants.product_id` unique and variant name/value individually unique, which conflicts with multiple variants per product and repeated values across products. Verify live indexes/data, then correct only through the consolidated migration.
- [x] **Medium: payment-method mapping.** Payment records now use the order's validated payment method instead of storing arbitrary Paystack channel names in the database enum.
- [x] Prevent guest checkout from attaching to an existing account by email/phone; require guest phone and regenerate the session ID.
- [x] Restrict order tracking to the authenticated owner or require guest order number plus account email; tracking references require owner login.
- [ ] **Medium: buyer security/data handling.** Order tracking now requires owner identity. Review account endpoints for output escaping and CSRF; finish registration/login/reset workflow tests.
- [ ] **Medium: one database access convention.** The application mixes mysqli and PDO, duplicates helpers, and repeats business rules. Standardize access gradually around existing hosting constraints and prepared statements; avoid a broad rewrite before behavioral fixes are covered.
- [ ] **Medium: leftover/unclear schema objects.** Verify whether `categories_backup` is still needed and whether indexes, unique constraints, delete behavior, and status enums match current code before planning migration operations. Preserve production data; do not drop tables based on this snapshot alone.

## Single Goal: Finish and Prepare Bless Mall for StormerHost

Complete these steps in order. Items requiring production credentials, a full phpMyAdmin schema export, or provider access stay blocked until those inputs are available; do not guess at production schema or payment expiry behavior.

### 1. Close Shared Foundation and Buyer Flow

- [ ] Rotate exposed database/payment/SMS credentials and configure private production values; remove any development/test secrets from deployable source.
- [x] Disable public error display and protect logs/dumps with web-server rules; verify those rules on StormerHost after upload.
- [ ] Validate upload paths and production HTTPS cookie behavior, and document/check StormerHost PHP extensions (PDO MySQL, mysqli, cURL, fileinfo, and mail/SMS as applicable).
- [ ] Capture the complete production schema and role IDs; inventory existing rows and constraints before finalizing the migration draft.
- [ ] Finish shared role guards, CSRF coverage, output escaping, validation, and consistent error handling; test direct URL access and forged POSTs for every role.
- [x] Add CSRF validation to all seven cart mutation endpoints and their AJAX payloads.
- [x] Restrict system settings to admins; restore CSRF checks for each settings save and cache-clear action.
- [x] Restrict global delivery management to admins and protect status, bulk, and edit submissions with CSRF.
- [x] Add CSRF to seller product/image mutations and seller order status actions; keep order updates vendor-scoped and manual SMS admin-only.
- [x] Implement password reset token lifecycle and email pages.
- [ ] Configure/test StormerHost email delivery; test login, logout, remember-me, deactivated accounts, and every role redirect.
- [x] Route sellers to their scoped product portal and require deliverer role identity plus profile for delivery access.
- [x] Remove runtime schema DDL from authentication, product administration, and newsletter requests. Newsletter signup now fails safely until its missing table is added by the final consolidated migration.

### 2. Finish Buyer Verification

- [x] Fix address create/edit; make delete/default changes POST-only with CSRF, transactional single-default behavior, and ownership checks. Complete database-backed tests before release.
- [ ] Verify registration, login, account profile, wishlist, saved addresses, buyer order list/detail, reorder, and order tracking end to end.
- [x] Consolidate cart/checkout delivery totals for pickup, zones, express, discounts, and the configured free-delivery threshold; focused calculator cases pass, with hosted MySQL checkout testing still open.
- [x] Revalidate product/tier availability, pricing, quantity bounds, vendor approval/status, and stock at checkout; require review when the price changed and reserve stock conditionally. SQLite fixture cases pass; StormerHost MySQL integration is still required.
- [x] Prevent guest checkout from attaching to/logging into an existing account by email/phone; require guest contact phone and regenerate the session ID. A matching account is directed to login.
- [ ] Test cash-on-delivery and Paystack success, failure, timeout, retry, duplicate callback, wrong amount/currency, and abandoned payment behavior.

### 3. Admin Portal

- [ ] Audit admin-only routes/actions for users, roles, vendors, categories, inventory, promotions, reviews, reports, and delivery. Settings/delivery are admin-only; product/order mutations now have CSRF and seller ownership scopes.
- [x] Restrict global delivery management and mutations to admins.
- [ ] Ensure admin order/payment actions cannot mark money paid/refunded without a matching gateway/manual reconciliation record; preserve an auditable status history.
- [ ] Make inventory adjustments and order cancellation/restock transactional and auditable.
- [ ] Harden uploads (MIME/content validation, extension allowlist, size limit, generated filenames, no executable uploads) and remove unsafe dynamic SQL.
- [ ] Route notifications through private configuration, enforce TLS verification, validate provider responses, and record truthful delivery status without exposing API credentials.

### 4. Seller Portal

- [x] Create a seller dashboard and route seller logins there instead of the admin dashboard.
- [ ] Define seller onboarding/approval, vendor ownership, suspension, and what sellers can see/do before and after approval.
- [ ] Verify sellers can manage only their own products, pricing tiers, images, inventory, promotions, and orders; inspect every endpoint for owner checks, not just rendered controls.
- [ ] Add seller order fulfillment/status workflow and ensure admin reports and seller reports are correctly scoped.
- [ ] Test cross-vendor ID tampering and direct access to admin-only pages/actions.

### 5. Deliverer Portal

- [ ] Define deliverer onboarding/profile/role rules and use an explicit deliverer role plus an active deliverer record for authorization.
- [x] Implement deliverer availability updates and assignment-owned order detail/status transitions with CSRF; delivered updates write order status history.
- [ ] Connect checkout/admin delivery records to rider assignments and verify the assignment schema/status enum against the production export.
- [ ] Complete acceptance/rejection, pickup, in-transit, delivered, and failed flow integration with admin/customer delivery records.
- [ ] Keep delivery status, order status, customer tracking, address/pickup data, and notifications consistent.
- [ ] Test unavailable riders, reassignment, cancellations, failed delivery, pickup orders, and duplicate status submissions.

### 6. One Migration, Integration, and Release

- [x] Draft one consolidated migration at `migrations/20261013_complete_portal_schema.sql` for the confirmed missing newsletter table and wishlist/variant uniqueness defects, preserving existing rows and foreign keys.
- [ ] Compare the draft against a complete live schema export, including exact enum values and index names; this workspace has no MySQL/MariaDB client, so SQL execution has not been validated here.
- [ ] Back up production and apply the reviewed migration to a staging copy first; verify rows, foreign keys, indexes, roles, enums, inventory, and order history before production.
- [ ] Apply that migration to a backup/staging copy first; verify row counts, foreign keys, role IDs, enum values, payment/order history, inventory, and delivery workflows before the owner applies it to production.
- [ ] Run focused regression tests for each completed portal, PHP syntax checks, database-backed workflow tests, and mobile/desktop browser checks where available.
- [ ] Verify a clean deployment package excludes credentials, logs, backups, temporary uploads, and local-only files; document file permissions and the exact StormerHost upload/migration order.
- [ ] Complete each phase's checks and commit that phase to `main`; do not include the pre-existing `tables.txt` change unless the owner asks for it.
- [ ] Provide the owner with the single migration file and final StormerHost manual test checklist. Do not claim the hosted site is verified until the owner runs those live tests.