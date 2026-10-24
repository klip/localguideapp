-- Who's coming on a trip ("2 adults"), asked when the guest pays the guide
-- (BookingPanel) instead of on the profile. Shown to the guide. The old
-- profile columns (user_profiles.party / duration_hours) are no longer
-- written by the app; left in place. Not re-runnable.
ALTER TABLE `bookings`
  ADD COLUMN `party` VARCHAR(80) NULL DEFAULT NULL AFTER `duration_hours`;
