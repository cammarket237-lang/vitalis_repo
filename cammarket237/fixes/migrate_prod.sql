-- CamMarket237 Production Migration
-- Run this on the production PostgreSQL database BEFORE deploying new files
-- Safe to run multiple times (uses IF NOT EXISTS)

-- Add recovery PIN columns to users table
ALTER TABLE cammarket237.users
    ADD COLUMN IF NOT EXISTS recovery_pin_hash VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pin_set_at TIMESTAMP DEFAULT NULL;

-- Create rate_limits table if it doesn't exist
CREATE TABLE IF NOT EXISTS cammarket237.rate_limits (
    id               SERIAL PRIMARY KEY,
    rate_key         VARCHAR(200) NOT NULL UNIQUE,
    attempts         INTEGER DEFAULT 1,
    first_attempt_at TIMESTAMP DEFAULT NOW(),
    last_attempt_at  TIMESTAMP DEFAULT NOW(),
    blocked_until    TIMESTAMP
);

-- Confirm
SELECT
    column_name,
    data_type,
    is_nullable
FROM information_schema.columns
WHERE table_schema = 'cammarket237'
  AND table_name   = 'users'
  AND column_name  IN ('recovery_pin_hash', 'pin_set_at')
ORDER BY column_name;
