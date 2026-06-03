-- Ads: store-level boost + relaxed ad_type constraint.
-- Idempotent. Run once against the DB:
--   docker exec -i cammarket237_db psql -U cammarket_user -d cammarket237_db < db/ads_boost_store_migration.sql
-- (Prod runs the same statements via DEPLOY_PRODUCTION.sh Step 5.)
--
-- Why: ad_campaigns' CHECK previously allowed only featured_listing/sponsored_notification/
-- banner_display, so submit_ad's boost_listing insert always failed. This relaxes it to the
-- ad types the app actually uses and adds store_id for "Boost my whole store" campaigns.

ALTER TABLE cammarket237.ad_campaigns ADD COLUMN IF NOT EXISTS store_id BIGINT NULL;

ALTER TABLE cammarket237.ad_campaigns DROP CONSTRAINT IF EXISTS ad_campaigns_type_check;
ALTER TABLE cammarket237.ad_campaigns ADD CONSTRAINT ad_campaigns_type_check
  CHECK (ad_type IN ('featured_listing','sponsored_notification','banner_display',
                     'boost_listing','boost_store','video_ad','event_ad'));
