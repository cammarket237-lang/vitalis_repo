-- CamMarket237 Referral Rewards Migration
-- Run on Docker: docker exec cammarket237_db psql -U cammarket_user -d cammarket237_db -f /tmp/migrate_referral_rewards.sql
-- Run on prod:   PGPASSWORD=CamMarket2024 psql -U cammarket_user -h 127.0.0.1 -d cammarket237_db -f migrate_referral_rewards.sql

-- 1. Wallet balance on users
ALTER TABLE cammarket237.users
    ADD COLUMN IF NOT EXISTS wallet_balance INTEGER DEFAULT 0;

-- 2. Referral rewards table
CREATE TABLE IF NOT EXISTS cammarket237.referral_rewards (
    id           SERIAL PRIMARY KEY,
    referrer_id  INTEGER NOT NULL REFERENCES cammarket237.users(id) ON DELETE CASCADE,
    referee_id   INTEGER NOT NULL REFERENCES cammarket237.users(id) ON DELETE CASCADE,
    referee_role VARCHAR(20) NOT NULL,
    reward_fcfa  INTEGER NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT NOW(),
    confirmed_at TIMESTAMP,
    UNIQUE(referee_id)
);

-- 3. Index for fast lookups
CREATE INDEX IF NOT EXISTS idx_referral_rewards_referrer ON cammarket237.referral_rewards(referrer_id);
CREATE INDEX IF NOT EXISTS idx_referral_rewards_status   ON cammarket237.referral_rewards(status);

-- 4. referred_by column on users (links to referrer's user id)
ALTER TABLE cammarket237.users
    ADD COLUMN IF NOT EXISTS referred_by INTEGER DEFAULT NULL;

-- Confirm
SELECT 'wallet_balance column: ' || data_type
FROM information_schema.columns
WHERE table_schema='cammarket237' AND table_name='users' AND column_name='wallet_balance';

SELECT 'referral_rewards table rows: ' || COUNT(*)
FROM cammarket237.referral_rewards;
