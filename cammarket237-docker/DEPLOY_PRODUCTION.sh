#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# CamMarket237 - PRODUCTION DEPLOY SCRIPT
# Run on production server:
#   bash DEPLOY_PRODUCTION.sh
# ═══════════════════════════════════════════════════════════════

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo ""
echo "========================================"
echo -e "${GREEN}  CamMarket237 - Production Deploy${NC}"
echo "========================================"
echo ""

WEB=/var/www/cammarket237
DB_PASS='CamMarket2024'
DB_USER='cammarket_user'
DB_NAME='cammarket237_db'

# ── STEP 1: Backup current files ──────────────────────────
echo -e "${YELLOW}Step 1: Backing up current files...${NC}"
cp $WEB/index.html   $WEB/index.html.bak_$(date +%Y%m%d_%H%M) 2>/dev/null
cp $WEB/api.php      $WEB/api.php.bak_$(date +%Y%m%d_%H%M)    2>/dev/null
echo -e "${GREEN}  Backup done${NC}"

# ── STEP 2: Copy new app files ────────────────────────────
echo -e "${YELLOW}Step 2: Deploying new files...${NC}"
# NOTE: Upload files via FileZilla first, then run this script
# Expected files in /tmp/deploy/:
#   index.html, api.php, verify_photos.php

if [ -f "/tmp/deploy/index.html" ]; then
    cp /tmp/deploy/index.html        $WEB/index.html
    cp /tmp/deploy/api.php           $WEB/api.php
    cp /tmp/deploy/admin.php         $WEB/admin.php
    cp /tmp/deploy/sw.js             $WEB/sw.js
    [ -f "/tmp/deploy/unified_admin.php" ]             && cp /tmp/deploy/unified_admin.php             $WEB/unified_admin.php
    [ -f "/tmp/deploy/verify_photos.php" ]             && cp /tmp/deploy/verify_photos.php             $WEB/verify_photos.php
    [ -f "/tmp/deploy/cammarket_login_classic.html" ]  && cp /tmp/deploy/cammarket_login_classic.html  $WEB/cammarket_login_classic.html
    echo -e "${GREEN}  Files deployed from /tmp/deploy/${NC}"
else
    echo -e "${YELLOW}  No files in /tmp/deploy/ - skipping file copy${NC}"
    echo -e "${YELLOW}  Upload files via FileZilla first${NC}"
fi

# ── STEP 2b: Create upload folders for ads ────────────────
echo -e "${YELLOW}Step 2b: Creating ad upload folders...${NC}"
mkdir -p $WEB/uploads/video_ads
mkdir -p $WEB/uploads/event_posters
chmod 755 $WEB/uploads/video_ads $WEB/uploads/event_posters
chown www-data:www-data $WEB/uploads/video_ads $WEB/uploads/event_posters 2>/dev/null || true
echo -e "${GREEN}  Ad upload folders ready${NC}"

# ── STEP 3: Fix DB host for production ────────────────────
echo -e "${YELLOW}Step 3: Fixing DB host for production...${NC}"
sed -i "s/host=db;/host=localhost;/" $WEB/api.php
sed -i "s/YOUR_DB_PASSWORD/CamMarket2024/g" $WEB/api.php
echo -e "${GREEN}  DB host set to localhost${NC}"

# ── STEP 4: Create logs directory ─────────────────────────
echo -e "${YELLOW}Step 4: Creating logs directory...${NC}"
mkdir -p $WEB/logs
chmod 755 $WEB/logs
chown www-data:www-data $WEB/logs 2>/dev/null || true
echo -e "${GREEN}  Logs directory ready${NC}"

# ── STEP 5: Run database migration ────────────────────────
echo -e "${YELLOW}Step 5: Running database migration...${NC}"
PGPASSWORD="$DB_PASS" psql -U $DB_USER -h localhost -d $DB_NAME << 'SQLEOF'
-- Add condition column to listings
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS condition VARCHAR(20) DEFAULT 'used';

-- Add promo points columns to users
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS promo_points INTEGER DEFAULT 0;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS referral_points INTEGER DEFAULT 0;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS referral_count INTEGER DEFAULT 0;

-- Give existing users 10 promo points
UPDATE cammarket237.users SET promo_points=10 WHERE promo_points=0 AND role IN ('buyer','seller');

-- Generate referral codes for users missing them
UPDATE cammarket237.users
    SET referral_code=UPPER(SUBSTRING(MD5(phone||id::text),1,8))
    WHERE (referral_code IS NULL OR referral_code='') AND role IN ('buyer','seller');

-- Create service_listings table
CREATE TABLE IF NOT EXISTS cammarket237.service_listings (
    id SERIAL PRIMARY KEY, user_id INTEGER, store_id INTEGER,
    service_type VARCHAR(50) NOT NULL, title VARCHAR(200) NOT NULL,
    description TEXT, price NUMERIC(12,2), price_unit VARCHAR(20) DEFAULT 'negotiable',
    availability VARCHAR(200), amenities TEXT, town VARCHAR(100), region VARCHAR(100),
    latitude DECIMAL(10,8), longitude DECIMAL(11,8),
    status VARCHAR(20) DEFAULT 'active', views INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
);

-- Create service_media table
CREATE TABLE IF NOT EXISTS cammarket237.service_media (
    id SERIAL PRIMARY KEY, service_id INTEGER, media_url TEXT NOT NULL,
    media_role VARCHAR(20) DEFAULT 'main', created_at TIMESTAMP DEFAULT NOW()
);

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_service_listings_type ON cammarket237.service_listings(service_type);
CREATE INDEX IF NOT EXISTS idx_service_listings_status ON cammarket237.service_listings(status);
CREATE INDEX IF NOT EXISTS idx_service_media_service ON cammarket237.service_media(service_id);

-- Add review store_id if missing
ALTER TABLE cammarket237.reviews ADD COLUMN IF NOT EXISTS store_id INTEGER;
CREATE INDEX IF NOT EXISTS idx_reviews_store ON cammarket237.reviews(store_id);

-- Add verification queue columns
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS flag_keywords TEXT;
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS flag_category VARCHAR(50);
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS severity VARCHAR(20) DEFAULT 'low';
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS content_title VARCHAR(200);
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS store_id INTEGER;
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS content_type VARCHAR(50);
ALTER TABLE cammarket237.verification_queue ADD COLUMN IF NOT EXISTS flag_reason TEXT;

-- Advertise Hub: listing_id on ad_campaigns
ALTER TABLE cammarket237.ad_campaigns ADD COLUMN IF NOT EXISTS listing_id INT NULL;

-- Boost: store-level ads + relaxed ad_type (so boost_listing/boost_store/video/event can be saved)
ALTER TABLE cammarket237.ad_campaigns ADD COLUMN IF NOT EXISTS store_id BIGINT NULL;
ALTER TABLE cammarket237.ad_campaigns DROP CONSTRAINT IF EXISTS ad_campaigns_type_check;
ALTER TABLE cammarket237.ad_campaigns ADD CONSTRAINT ad_campaigns_type_check
  CHECK (ad_type IN ('featured_listing','sponsored_notification','banner_display','boost_listing','boost_store','video_ad','event_ad'));

-- Listings: extended columns for all new features
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS listing_type VARCHAR(30) DEFAULT 'sale';
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS price_type VARCHAR(20) DEFAULT 'fixed';
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS original_price NUMERIC(12,2);
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS price_drop_active BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS price_drop_expires TIMESTAMPTZ;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS stock_status VARCHAR(20) DEFAULT 'in_stock';
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS quantity_available INTEGER DEFAULT 1;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS bulk_available BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS bulk_discount_note TEXT;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS metadata JSONB;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS views INTEGER DEFAULT 0;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS subtitle VARCHAR(200);
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS about_long TEXT;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS host_bio TEXT;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS host_languages TEXT[];
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS year_built INTEGER;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_airport_pickup BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_airport_dropoff BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_local_transport BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_breakfast BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_meals BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_restaurant_onsite BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_laundry BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_housekeeping BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_tour_guide BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_event_space BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_wifi BOOLEAN DEFAULT false;
ALTER TABLE cammarket237.listings ADD COLUMN IF NOT EXISTS offers_generator BOOLEAN DEFAULT false;

-- Users: PIN recovery, wallet, referrals
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS recovery_pin_hash VARCHAR(255);
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS pin_set_at TIMESTAMPTZ;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS referred_by INTEGER;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS wallet_balance NUMERIC(12,2) DEFAULT 0;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS alert_settings TEXT;
ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS last_seen TIMESTAMPTZ;

-- Deals system
CREATE TABLE IF NOT EXISTS cammarket237.listing_deals (
    id SERIAL PRIMARY KEY,
    listing_id INTEGER NOT NULL,
    store_id INTEGER NOT NULL,
    seller_id INTEGER NOT NULL,
    deal_type VARCHAR(20) DEFAULT 'custom',
    discount_percent NUMERIC(5,2) NOT NULL,
    original_price NUMERIC(12,2) NOT NULL,
    deal_price NUMERIC(12,2) NOT NULL,
    ends_at TIMESTAMPTZ NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_listing_deals_listing ON cammarket237.listing_deals(listing_id);
CREATE INDEX IF NOT EXISTS idx_listing_deals_seller  ON cammarket237.listing_deals(seller_id);
CREATE INDEX IF NOT EXISTS idx_listing_deals_active  ON cammarket237.listing_deals(is_active, ends_at);

-- Referral rewards
CREATE TABLE IF NOT EXISTS cammarket237.referral_rewards (
    id SERIAL PRIMARY KEY,
    referrer_id INTEGER NOT NULL,
    referee_id  INTEGER NOT NULL UNIQUE,
    referee_role VARCHAR(20) DEFAULT 'buyer',
    reward_fcfa  INTEGER NOT NULL DEFAULT 200,
    status VARCHAR(20) DEFAULT 'pending',
    confirmed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Push subscriptions
CREATE TABLE IF NOT EXISTS cammarket237.push_subscriptions (
    id SERIAL PRIMARY KEY,
    user_id  INTEGER,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh   TEXT,
    auth     TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Buyer events for smart feed
CREATE TABLE IF NOT EXISTS cammarket237.buyer_events (
    id SERIAL PRIMARY KEY,
    buyer_id   INTEGER,
    session_id VARCHAR(64),
    event_type VARCHAR(30),
    listing_id INTEGER,
    store_id   INTEGER,
    category   VARCHAR(100),
    region     VARCHAR(100),
    town       VARCHAR(100),
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_buyer_events_buyer    ON cammarket237.buyer_events(buyer_id);
CREATE INDEX IF NOT EXISTS idx_buyer_events_category ON cammarket237.buyer_events(category);

-- Price drop notifications
CREATE TABLE IF NOT EXISTS cammarket237.price_drop_notifications (
    id SERIAL PRIMARY KEY,
    user_id    INTEGER,
    listing_id INTEGER,
    notified_at TIMESTAMPTZ DEFAULT NOW()
);

-- Waitlist (notify-me when back in stock)
CREATE TABLE IF NOT EXISTS cammarket237.waitlist (
    id SERIAL PRIMARY KEY,
    user_id    INTEGER,
    listing_id INTEGER,
    phone      VARCHAR(30),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(listing_id, phone)
);

-- Grant permissions
GRANT ALL ON ALL TABLES IN SCHEMA cammarket237 TO cammarket_user;
GRANT ALL ON ALL SEQUENCES IN SCHEMA cammarket237 TO cammarket_user;

SELECT 'Migration OK' AS status;
SQLEOF
echo -e "${GREEN}  Migration complete${NC}"

# ── STEP 6: Set file permissions ──────────────────────────
echo -e "${YELLOW}Step 6: Setting permissions...${NC}"
chmod 644 $WEB/index.html $WEB/api.php $WEB/admin.php $WEB/sw.js $WEB/verify_photos.php $WEB/cammarket_login_classic.html 2>/dev/null
chmod 755 $WEB/uploads
echo -e "${GREEN}  Permissions set${NC}"

# ── STEP 7: Reload nginx ──────────────────────────────────
echo -e "${YELLOW}Step 7: Reloading nginx...${NC}"
nginx -t && systemctl reload nginx
echo -e "${GREEN}  Nginx reloaded${NC}"

# ── STEP 8: Run tests ─────────────────────────────────────
echo -e "${YELLOW}Step 8: Running tests...${NC}"
sleep 2

# Test API
API_RESP=$(curl -s "https://cammarket237.com/api.php?action=get_listings")
COUNT=$(echo "$API_RESP" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('listings',[])))" 2>/dev/null)
[ -n "$COUNT" ] && echo -e "${GREEN}  API OK - $COUNT listings${NC}" || echo -e "${RED}  API FAILED${NC}"

# Test OTP
OTP_RESP=$(curl -s -X POST "https://cammarket237.com/api.php" -d "action=send_otp&phone=test123")
echo "$OTP_RESP" | grep -q '"success":true' \
    && echo -e "${GREEN}  OTP OK${NC}" \
    || echo -e "${RED}  OTP FAILED${NC}"

# File sizes
echo -e "${GREEN}  index.html: $(wc -c < $WEB/index.html) bytes${NC}"
echo -e "${GREEN}  api.php:    $(wc -c < $WEB/api.php) bytes${NC}"

# ── DONE ──────────────────────────────────────────────────
echo ""
echo "========================================"
echo -e "${GREEN}  Production Deploy Complete!${NC}"
echo "========================================"
echo ""
echo -e "  Site live at: ${GREEN}https://cammarket237.com${NC}"
echo ""
echo -e "  Run full test: ${YELLOW}bash $WEB/test_cammarket237.sh${NC}"
echo ""
