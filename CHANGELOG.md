# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]
### Added
- Timeline admin view with AJAX-powered table status management and real-time scheduling logic.
- Check-in and check-out time support across admin and frontend booking flows.
- Timeline-specific assets (CSS/JS) with localization and translation updates.

### Changed
- Booking validation to enforce 1–6 hour durations and prevent overlapping reservations with cleanup buffers.
- Availability checks to return structured conflict data and suggestions for alternative slots.
 - Availability conflict responses now use WordPress error semantics so consumers can reliably detect failures.

### Fixed
- Missing default timeline data for existing bookings via database migration backfill.
