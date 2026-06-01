-- CamMarket237 — fix SQLSTATE[42P10] on referral signups
-- "there is no unique or exclusion constraint matching the ON CONFLICT specification"
--
-- Confirmed cause (prod \d, 2026-06-01):
--   cammarket237.referrals        already has UNIQUE (referrer_id, referee_id) -> fine
--   cammarket237.referral_rewards has ONLY a PK on id, no unique on referee_id -> the
--   registration's `INSERT ... ON CONFLICT (referee_id) DO NOTHING` (api.php lines 487 & 347)
--   has no matching constraint and errors out, rolling back the whole signup.
--
-- Fix: add a UNIQUE index on referral_rewards(referee_id). Idempotent, safe to re-run.
--
-- Run on prod:   PGPASSWORD=CamMarket2024 psql -U cammarket_user -h 127.0.0.1 -d cammarket237_db -f fix_referral_onconflict.sql
-- Run on Docker: docker exec -i cammarket237_db psql -U cammarket_user -d cammarket237_db < fixes/fix_referral_onconflict.sql

BEGIN;

-- collapse any pre-existing duplicate referee_id rows (keep the lowest id) so the
-- unique index can be created; these duplicates are erroneous data.
DELETE FROM cammarket237.referral_rewards a
USING cammarket237.referral_rewards b
WHERE a.ctid < b.ctid
  AND a.referee_id = b.referee_id;

CREATE UNIQUE INDEX IF NOT EXISTS uq_referral_rewards_referee
  ON cammarket237.referral_rewards (referee_id);

COMMIT;

-- Verify (should list the new unique index):
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'cammarket237'
  AND tablename  = 'referral_rewards'
  AND indexdef ILIKE '%UNIQUE%';
