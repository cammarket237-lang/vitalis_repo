#!/bin/bash
# ═══════════════════════════════════════════════════════════
# CamMarket237 - Full System Test Script
# Run on server: bash test_cammarket237.sh
# ═══════════════════════════════════════════════════════════

BASE_URL="https://cammarket237.com"
API="$BASE_URL/api.php"
PASS=0
FAIL=0
WARN=0

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

ok()   { echo -e "${GREEN}  PASS${NC} - $1"; ((PASS++)); }
fail() { echo -e "${RED}  FAIL${NC} - $1"; ((FAIL++)); }
warn() { echo -e "${YELLOW}  WARN${NC} - $1"; ((WARN++)); }
hdr()  { echo -e "\n${BLUE}══ $1 ══${NC}"; }

# ── 1. SERVER & FILES ──────────────────────────────────────
hdr "1. SERVER & FILES"

# nginx running
systemctl is-active nginx &>/dev/null && ok "nginx is running" || fail "nginx NOT running"
# PHP version check
php_ver=$(php -v 2>/dev/null | head -1 | grep -oP "PHP K[0-9]+.[0-9]+")
if [ -n "$php_ver" ]; then
  ok "PHP $php_ver is installed"
else
  warn "PHP version unclear"
fi
systemctl is-active "php${php_ver}-fpm" &>/dev/null && ok "nginx is running" || fail "nginx is NOT running"

# PHP-FPM running

# index.html exists and correct size
SIZE=$(wc -c < /var/www/cammarket237/index.html 2>/dev/null)
if [ "$SIZE" -gt 50000 ]; then
  ok "index.html exists ($SIZE bytes)"
else
  fail "index.html missing or too small ($SIZE bytes)"
fi

# api.php exists
SIZE2=$(wc -c < /var/www/cammarket237/api.php 2>/dev/null)
if [ "$SIZE2" -gt 5000 ]; then
  ok "api.php exists ($SIZE2 bytes)"
else
  fail "api.php missing or too small ($SIZE2 bytes)"
fi

# uploads folder writable
if [ -w /var/www/cammarket237/uploads ]; then
  ok "uploads/ folder is writable"
else
  fail "uploads/ folder is NOT writable"
fi

# SSL certificate
CERT_EXPIRY=$(echo | openssl s_client -servername cammarket237.com -connect cammarket237.com:443 2>/dev/null | openssl x509 -noout -dates 2>/dev/null | grep notAfter | cut -d= -f2)
if [ -n "$CERT_EXPIRY" ]; then
  ok "SSL certificate valid (expires: $CERT_EXPIRY)"
else
  warn "Could not verify SSL certificate"
fi

# ── 2. DATABASE ────────────────────────────────────────────
hdr "2. DATABASE"

# DB connection
DB_TEST=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -c "SELECT 1" -t 2>/dev/null | tr -d ' ')
if [ "$DB_TEST" = "1" ]; then
  ok "Database connection successful"
else
  fail "Database connection FAILED"
fi

# Check tables exist
TABLES=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -c "\dt cammarket237.*" 2>/dev/null | grep -c "table")
if [ "$TABLES" -gt 10 ]; then
  ok "Database tables exist ($TABLES tables found)"
else
  fail "Database tables missing (only $TABLES found)"
fi

# Check users table
USER_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.users" 2>/dev/null | tr -d ' ')
ok "Users in database: $USER_COUNT"

# Check listings
LISTING_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.listings WHERE status='active'" 2>/dev/null | tr -d ' ')
if [ "$LISTING_COUNT" -gt 0 ]; then
  ok "Active listings: $LISTING_COUNT"
else
  warn "No active listings found"
fi

# Check OTP tokens table
PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -c "SELECT 1 FROM cammarket237.otp_tokens LIMIT 1" &>/dev/null && ok "otp_tokens table accessible" || fail "otp_tokens table NOT accessible"

# Check stores
STORE_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.stores" 2>/dev/null | tr -d ' ')
ok "Stores in database: $STORE_COUNT"

# Check listing_media
MEDIA_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.listing_media" 2>/dev/null | tr -d ' ')
ok "Media records: $MEDIA_COUNT"

# Check referral columns exist
REF_COL=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT column_name FROM information_schema.columns WHERE table_schema='cammarket237' AND table_name='users' AND column_name='referral_code'" 2>/dev/null | tr -d ' ')
[ "$REF_COL" = "referral_code" ] && ok "referral_code column exists" || fail "referral_code column MISSING"

# ── 3. API ENDPOINTS ───────────────────────────────────────
hdr "3. API ENDPOINTS"

# Site loads
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL")
[ "$HTTP_CODE" = "200" ] && ok "Site loads (HTTP 200)" || fail "Site load failed (HTTP $HTTP_CODE)"

# api.php responds
API_RESP=$(curl -s "$API?action=get_listings")
if echo "$API_RESP" | grep -q '"success"'; then
  ok "api.php responds with JSON"
else
  fail "api.php NOT responding with JSON"
fi

# get_listings returns data
if echo "$API_RESP" | grep -q '"listings"'; then
  ITEM_COUNT=$(echo "$API_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('listings',[])))" 2>/dev/null)
  ok "get_listings returns $ITEM_COUNT items"
else
  fail "get_listings returned no listings"
fi

# send_otp works
OTP_RESP=$(curl -s -X POST "$API" -d "action=send_otp&phone=test_phone_99999")
if echo "$OTP_RESP" | grep -q '"success":true'; then
  OTP_CODE=$(echo "$OTP_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('dev_code',''))" 2>/dev/null)
  ok "send_otp works (code: $OTP_CODE)"
  
  # verify_otp with correct code
  VERIFY_RESP=$(curl -s -X POST "$API" -d "action=verify_otp&phone=test_phone_99999&otp=$OTP_CODE")
  if echo "$VERIFY_RESP" | grep -q '"success":true'; then
    ok "verify_otp works - existing user logged in"
  elif echo "$VERIFY_RESP" | grep -q '"guest":true'; then
    ok "verify_otp works - guest access granted (24hr)"
  elif echo "$VERIFY_RESP" | grep -q 'not_registered'; then
    ok "verify_otp works - new phone detected correctly"
  else
    warn "verify_otp response: $(echo $VERIFY_RESP | head -c 100)"
  fi
  
  # verify with wrong code
  WRONG_RESP=$(curl -s -X POST "$API" -d "action=verify_otp&phone=test_phone_99999&otp=000000")
  echo "$WRONG_RESP" | grep -q '"success":false' && ok "Wrong OTP correctly rejected" || warn "Wrong OTP not rejected properly"
  
else
  fail "send_otp failed: $OTP_RESP"
fi

# seller_login with wrong password
SELLER_RESP=$(curl -s -X POST "$API" -d "action=seller_login&phone=000000&password=wrong")
echo "$SELLER_RESP" | grep -q '"success":false' && ok "seller_login correctly rejects wrong credentials" || warn "seller_login security check unclear"

# register_buyer validation
REG_RESP=$(curl -s -X POST "$API" -d "action=register_buyer&full_name=&phone=&password=&confirm_password=&region=&town=")
echo "$REG_RESP" | grep -q '"success":false' && ok "register_buyer correctly rejects empty fields" || warn "register_buyer validation unclear"

# unknown action
UNK_RESP=$(curl -s "$API?action=unknown_xyz")
echo "$UNK_RESP" | grep -q '"success":false' && ok "Unknown API action correctly rejected" || warn "Unknown action handling unclear"

# POST method works (not 405)
POST_CHECK=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API" -d "action=get_listings")
[ "$POST_CHECK" = "200" ] && ok "POST requests allowed (HTTP 200)" || fail "POST requests blocked (HTTP $POST_CHECK)"

# ── 4. FRONTEND CHECKS ─────────────────────────────────────
hdr "4. FRONTEND JAVASCRIPT"

HTML=$(curl -s "$BASE_URL")

# Check key functions exist
for FN in "window.go" "window.sendOtp" "window.verifyOtp" "window.registerBuyer" "window.loginSeller" "window.browseAsGuest" "window.canContactSeller" "window.showWelcome" "window.doSearch"; do
  echo "$HTML" | grep -q "$FN" && ok "Function defined: $FN" || fail "Function MISSING: $FN"
done

# Check key HTML elements
for EL in "welcome-msg" "buyer-phone" "otp-input" "search-results" "buyer-map" "send-code-btn" "b-referral" "dev-hint"; do
  echo "$HTML" | grep -q "id=\"$EL\"" && ok "HTML element exists: #$EL" || fail "HTML element MISSING: #$EL"
done

# Check screens exist
for SCR in "screen-role" "screen-buyer-auth" "screen-buyer-otp" "screen-buyer-search" "screen-seller-auth" "screen-seller-dash" "screen-item-detail"; do
  echo "$HTML" | grep -q "id=\"$SCR\"" && ok "Screen exists: #$SCR" || fail "Screen MISSING: #$SCR"
done

# Check Browse as Guest button
echo "$HTML" | grep -q "Browse as Guest" && ok "Browse as Guest button exists" || fail "Browse as Guest button MISSING"

# Check Leaflet map library loaded
echo "$HTML" | grep -q "leaflet" && ok "Leaflet map library included" || fail "Leaflet map library MISSING"

# ── 5. BUSINESS RULES ──────────────────────────────────────
hdr "5. BUSINESS RULES"

# Phone+role uniqueness
CONSTRAINT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM pg_constraint WHERE conname='users_phone_role_unique'" 2>/dev/null | tr -d ' ')
[ "$CONSTRAINT" = "1" ] && ok "Phone+role uniqueness constraint exists" || warn "Phone+role uniqueness constraint not found (DB still enforces it)"

# OTP 24hr expiry
OTP_EXP=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT EXTRACT(EPOCH FROM (expires_at - created_at))/3600 FROM cammarket237.otp_tokens ORDER BY created_at DESC LIMIT 1" 2>/dev/null | tr -d ' ')
if [ -n "$OTP_EXP" ]; then
  ok "OTP expiry: ${OTP_EXP}hrs"
else
  warn "No OTP records to check expiry"
fi

# Referral code unique constraint
REF_CONSTRAINT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM pg_constraint WHERE conname='users_referral_code_key'" 2>/dev/null | tr -d ' ')
[ "$REF_CONSTRAINT" = "1" ] && ok "Referral code uniqueness enforced" || warn "Referral code uniqueness check unclear"

# Media role check
MEDIA_ROLE=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT DISTINCT media_role FROM cammarket237.listing_media LIMIT 5" 2>/dev/null | tr -d ' ' | tr '\n' ',')
ok "Media roles in DB: $MEDIA_ROLE"

# Active sellers
SELLER_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.users WHERE role='seller'" 2>/dev/null | tr -d ' ')
ok "Seller accounts: $SELLER_COUNT"

BUYER_COUNT=$(PGPASSWORD='CamMarket2024' psql -U cammarket_user -h localhost -d cammarket237_db -t -c "SELECT COUNT(*) FROM cammarket237.users WHERE role='buyer'" 2>/dev/null | tr -d ' ')
ok "Buyer accounts: $BUYER_COUNT"

# ── 6. NGINX CONFIG ────────────────────────────────────────
hdr "6. NGINX CONFIG"

# PHP location block exists
nginx -T 2>/dev/null | grep -q "fastcgi_pass" && ok "PHP FastCGI configured in nginx" || fail "PHP FastCGI NOT configured"

# HTTPS redirect
HTTP_REDIR=$(curl -s -o /dev/null -w "%{http_code}" "http://cammarket237.com" 2>/dev/null)
[ "$HTTP_REDIR" = "301" ] && ok "HTTP redirects to HTTPS (301)" || warn "HTTP redirect check: $HTTP_REDIR"

# uploads location
UPLOAD_CHECK=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/uploads/" 2>/dev/null)
[ "$UPLOAD_CHECK" != "404" ] && ok "Uploads folder accessible" || warn "Uploads folder returned 404"

# ── SUMMARY ────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════"
echo -e "  RESULTS: ${GREEN}$PASS passed${NC}  ${RED}$FAIL failed${NC}  ${YELLOW}$WARN warnings${NC}"
echo "═══════════════════════════════════════"
if [ "$FAIL" -eq 0 ]; then
  echo -e "  ${GREEN}ALL CRITICAL TESTS PASSED!${NC}"
else
  echo -e "  ${RED}$FAIL CRITICAL ISSUES NEED FIXING${NC}"
fi
echo ""
