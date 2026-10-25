-- Second security review (2026-10-25).

-- Reports from the bounce mailbox are unauthenticated email: they only act
-- (suppress, mark the message) when they carry a valid signed tag for a
-- message we sent. Anything else, and authenticated provider reports about
-- an address we have no sent message for, is recorded for an admin to review.
-- review: '' = applied automatically | pending | applied (by an admin) | dismissed
ALTER TABLE email_events
  ADD COLUMN review VARCHAR(10) NOT NULL DEFAULT '' AFTER ext_id,
  ADD KEY ix_email_events_review (review, created_at);
