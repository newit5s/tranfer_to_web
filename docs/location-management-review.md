# Location-Based Booking Management Review

## Verification Checklist

### 1. Super admin ownership of plugin settings and accounts
- `RB_Admin::__construct()` wires the admin menu, CRUD actions, and notices only after checking the WordPress capability stack. Every submenu registered in `add_admin_menu()` requires `manage_options`, so only WordPress administrators ("super admins" in the requirement) can reach booking dashboards or settings. 【F:admin/class-admin.php†L11-L122】
- CRUD handlers for portal accounts (`save_portal_account()` and `delete_portal_account()`) immediately verify `manage_options` and redirect through the settings UI. They validate usernames, enforce unique credentials, require at least one assigned location, and persist changes via `RB_Portal_Account_Manager`, matching the specification that super admins provision manager accounts. 【F:admin/class-admin.php†L58-L157】【F:includes/class-portal-account.php†L31-L214】
- Dedicated activation routines create the `rb_portal_accounts` and `rb_portal_account_locations` tables so portal credentials live outside the WordPress users table, keeping account lifecycle under the plugin’s control. 【F:includes/class-database.php†L28-L205】

### 2. Location managers scoped to assigned locations
- Front-end logins call `RB_Portal_Account_Manager::authenticate()`, which blocks inactive accounts and returns the list of assigned location IDs stored in the mapping table. Sessions are held in secure cookies plus transients, independent from WordPress logins as required. 【F:includes/class-portal-account.php†L186-L410】
- The manager portal (`render_manager_portal()`) fetches the active session account, intersects the requested location with allowed IDs via `resolve_location_from_allowed()`, and persists the active branch in the portal account record. Managers therefore see only branches that super admins assigned. 【F:public/class-frontend.php†L40-L903】
- Booking mutations triggered from the portal go through `handle_manager_update_booking()`, which verifies the nonce, checks that the booking’s location is inside the manager’s allowed list, and ensures it matches the session’s active location before calling booking actions. This prevents cross-location access while reusing the same booking update helpers as the admin. 【F:public/class-frontend.php†L1040-L1171】【F:includes/class-booking.php†L1-L212】

### 3. Functional parity with super admin booking actions
- Manager-triggered actions (`confirm`, `cancel`, `complete`) delegate to the `RB_Booking` class used in the administrator backend, so table assignment, CRM syncing, and status transitions behave the same for both roles. Follow-up hooks like `rb_booking_confirmed` still fire, keeping downstream integrations consistent. 【F:public/class-frontend.php†L1120-L1167】【F:includes/class-booking.php†L70-L212】

## Redundancy Review
- The location guard clauses double-check both the assigned list and the active branch, but each serves a purpose: the former prevents tampering with unassigned branches, while the latter blocks stale UI submissions if the manager switches locations. No extraneous paths or dead code were identified around portal auth or booking management.
- AJAX endpoints are registered for both logged-in and anonymous contexts because portal managers do not receive WordPress user accounts; the early permission checks keep unauthenticated requests from succeeding. No duplicate handlers exist elsewhere in the codebase.

## Conclusion
The current implementation satisfies the stated requirements. Super admins alone manage plugin settings and portal accounts, and location managers gain a dedicated portal that limits all booking operations to the branches assigned by administrators without introducing redundant logic.
