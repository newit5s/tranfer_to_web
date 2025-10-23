# Notification & Reminder Strategy (No SMS)

This note outlines a staged approach for delivering reminders around VIP and blacklist bookings without relying on Calendar APIs or SMS/mobile applications.

## 1. Event Classification & Data Hooks
- Extend booking data with flags such as `vip`, `blacklist`, and optional `notes`/`special_requirements`.
- Generate internal events on key actions: booking created/updated, customer check-in, status changes, and overdue tasks (e.g., table not confirmed after X minutes).
- Log metadata (timestamp, creator, assigned staff) to support auditing and escalation logic later.

## 2. Email Workflow for Admin & Management
- **Trigger rules**
  - Send an email when a VIP or blacklist booking is created or modified.
  - Send an escalation email if a blacklist guest checks in or if a VIP checklist remains incomplete beyond a configured SLA.
- **Email content**
  - Subject template: `[Booking Alert] <VIP|Blacklist> - Customer Name - Time`.
  - Body includes booking details, customer flags, checklist summary, and direct links to the management dashboard.
- **Delivery infrastructure**
  - Use the existing backend (e.g., WordPress/WooCommerce mailer or a transactional service like SendGrid/Mailgun) to queue and send messages.
  - Implement rate limiting and deduplication to avoid spamming recipients when bookings are edited repeatedly.
- **Recipient management**
  - Maintain a configurable list of admin and location manager emails per venue.
  - Support cc/bcc rules for regional supervisors or compliance teams when blacklist events fire.

## 3. Configuration Surface in Settings
- Add a dedicated section under the plugin **Settings** page so administrators can manage notification behavior without editing code.
- Expose toggles for each trigger (VIP created, blacklist check-in, overdue checklist, digest emails) along with lead times and escalation SLAs.
- Provide forms to maintain recipient groups per location, including cc/bcc lists, with validation to prevent malformed addresses.
- Store preferences in existing WordPress options (serialized array) or a new custom table if per-location overrides are required, and load them when dispatching notifications.
- Include contextual help text that clarifies how email alerts interact with in-app popups, reducing confusion for managers configuring the system.

## 4. In-app Alerts for Location Managers (Web UI)
- Display real-time toast/popup notifications within the management dashboard when flagged bookings are added or guests check in.
- Highlight affected tables in the floor view with badges/icons (e.g., gold for VIP, red for blacklist) and tooltips containing customer notes.
- Provide an acknowledgment button to mark the alert as seen; store acknowledgment timestamps for follow-up reporting.
- Add a lightweight "Tasks" sidebar summarizing outstanding actions (e.g., "Confirm VIP welcome drink"), refreshed via polling or web sockets.

## 5. Tracking & Reporting
- Record every notification event (type, recipient, status, timestamps) in a dedicated table for compliance.
- Create daily/weekly digest emails summarizing VIP/blacklist visits, missed acknowledgments, and SLA breaches.
- Add dashboard filters to review historical alerts, enabling managers to audit responses and adjust staffing if necessary.

## 6. Implementation Milestones
1. Update the data model and booking creation flow to capture VIP/blacklist flags and notes.
2. Build the backend notification service with configurable email triggers and recipient lists.
3. Build the Settings UI and persistence for notification toggles and recipient groups so operations teams can self-manage alerts.
4. Integrate UI alerts for location managers, including badges and acknowledgment tracking.
5. Add reporting views and digest emails once the core alerting loop is stable.
6. Iterate on escalation rules (additional email recipients, tighter SLAs) based on feedback and logged metrics.

This staged plan prioritizes email and in-app web experiences so the team can deliver immediate value without waiting for SMS channels or dedicated mobile applications.
