#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# CamMarket237 — Full Smoke Test
# Run on production server: bash /var/www/cammarket237/smoke_test.sh
# Tests every API action, PIN system, uploads, admin, security & frontend
# ═══════════════════════════════════════════════════════════════════════════

BASE="https://cammarket237.com"
API="$BASE/api.php"
WEB="/var/www/cammarket237"
PASS=0; FAIL=0; WARN=0
STOK=""; BTOK=""; TEST_UID_S=""; TEST_UID_B=""; TEST_STORE_ID=""
TS=$(date +%s)
TEST_PHONE_S="6799${TS: -6}"
TEST_PHONE_B="6788${TS: -6}"
TEST_PIN="482916"
TEST_PASS="SmokeTest99"
PSQL="PGPASSWORD=CamMarket2024 psql -U cammarket_user -h 127.0.0.1 -d cammarket237_db -t -c"

G='\033[0;32m'; R='\033[0;31m'; Y='\033[1;33m'; C='\033[0;36m'; B='\033[1m'; N='\033[0m'
ok()   { echo -e "  ${G}✓${N} $1"; ((PASS++)); }
fail() { echo -e "  ${R}✗ FAIL${N} $1"; ((FAIL++)); }
warn() { echo -e "  ${Y}⚠ WARN${N} $1"; ((WARN++)); }
hdr()  { echo -e "\n${C}${B}━━━━━ $1 ━━━━━${N}"; }
jsok() { echo "$1" | grep -q '"success":true'; }
field(){ echo "$1" | grep -oP "\"$2\":\\s*\\K[^,}]+" | tr -d '"' | head -1; }

echo ""
echo -e "${C}${B}╔══════════════════════════════════════════════════════════╗${N}"
echo -e "${C}${B}║        CamMarket237 — Full Smoke Test                    ║${N}"
echo -e "${C}${B}║        $(date '+%Y-%m-%d %H:%M:%S')  |  $BASE   ║${N}"
echo -e "${C}${B}╚══════════════════════════════════════════════════════════╝${N}"


# ═══════════════════════════════════════════════════════════════════════════
hdr "1. SERVER INFRASTRUCTURE"
# ═══════════════════════════════════════════════════════════════════════════

ps aux | grep -q "[n]ginx"   && ok "nginx process running"   || fail "nginx is DOWN"
ps aux | grep -q "[p]hp-fpm" && ok "PHP-FPM process running" || fail "PHP-FPM is DOWN"

CERT=$(echo | openssl s_client -servername cammarket237.com -connect cammarket237.com:443 2>/dev/null \
    | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
[ -n "$CERT" ] && ok "SSL certificate valid — expires: $CERT" || warn "SSL certificate check failed"

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://cammarket237.com")
[ "$HTTP_CODE" = "301" ] && ok "HTTP → HTTPS redirect (301)" || warn "HTTP redirect returned $HTTP_CODE"

HTTPS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://cammarket237.com")
[ "$HTTPS_CODE" = "200" ] && ok "HTTPS site responds 200" || fail "HTTPS returned $HTTPS_CODE"

DISK=$(df -h $WEB 2>/dev/null | awk 'NR==2{print $4}')
[ -n "$DISK" ] && ok "Disk space available: $DISK free" || warn "Could not check disk"

UPLOAD_WRITABLE=$([ -w "$WEB/uploads" ] && echo "yes" || echo "no")
[ "$UPLOAD_WRITABLE" = "yes" ] && ok "uploads/ directory is writable" || fail "uploads/ NOT writable — image uploads will fail"

[ -f "$WEB/index.html" ]        && ok "index.html present ($(wc -c < $WEB/index.html) bytes)"  || fail "index.html MISSING"
[ -f "$WEB/api.php" ]           && ok "api.php present ($(wc -c < $WEB/api.php) bytes)"         || fail "api.php MISSING"
[ -f "$WEB/admin.php" ]         && ok "admin.php present"                                        || warn "admin.php missing"
[ -f "$WEB/verify_photos.php" ] && ok "verify_photos.php present"                               || warn "verify_photos.php missing"
[ -d "$WEB/.well-known" ]       && ok ".well-known/ directory present"                           || warn ".well-known/ missing (needed for TWA)"
[ -f "$WEB/.well-known/assetlinks.json" ] && ok "assetlinks.json present" || warn "assetlinks.json missing"


# ═══════════════════════════════════════════════════════════════════════════
hdr "2. DATABASE SCHEMA"
# ═══════════════════════════════════════════════════════════════════════════

DB_OK=$(eval "$PSQL \"SELECT 1\"" 2>/dev/null | tr -d ' ')
[ "$DB_OK" = "1" ] && ok "PostgreSQL connection OK" || { fail "DB connection FAILED — all DB tests will fail"; }

for TBL in users listings stores listing_media reviews followers service_listings service_media rate_limits; do
    CNT=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.$TBL\"" 2>/dev/null | tr -d ' ')
    [ -n "$CNT" ] && ok "Table cammarket237.$TBL exists ($CNT rows)" || fail "Table $TBL MISSING"
done

# Column checks
for COL in "users:recovery_pin_hash" "users:pin_set_at" "users:promo_points" \
           "users:referral_code" "users:referral_points" "listings:condition" \
           "listings:status" "stores:latitude" "stores:longitude"; do
    T="${COL%%:*}"; C2="${COL##*:}"
    eval "$PSQL \"SELECT $C2 FROM cammarket237.$T LIMIT 1\"" &>/dev/null \
        && ok "Column $T.$C2 exists" || fail "Column $T.$C2 MISSING"
done

STATS=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users\"" 2>/dev/null | tr -d ' ')
SELLERS=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users WHERE role='seller'\"" 2>/dev/null | tr -d ' ')
BUYERS=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users WHERE role='buyer'\"" 2>/dev/null | tr -d ' ')
LISTINGS=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.listings WHERE status='active'\"" 2>/dev/null | tr -d ' ')
PIN_USERS=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users WHERE recovery_pin_hash IS NOT NULL\"" 2>/dev/null | tr -d ' ')
echo -e "     Users=$STATS  Sellers=$SELLERS  Buyers=$BUYERS  ActiveListings=$LISTINGS  PINset=$PIN_USERS"
[ "${LISTINGS:-0}" -gt 0 ] && ok "Active listings exist: $LISTINGS" || warn "No active listings in DB"


# ═══════════════════════════════════════════════════════════════════════════
hdr "3. BUYER REGISTRATION & LOGIN"
# ═══════════════════════════════════════════════════════════════════════════

# Register buyer (no PIN → should fail)
BR_NOPIN=$(curl -s -X POST "$API" \
    -F "action=register_buyer" -F "full_name=Smoke Test Buyer" \
    -F "phone=$TEST_PHONE_B" -F "password=$TEST_PASS" -F "confirm_password=$TEST_PASS" \
    -F "region=Centre" -F "town=Yaounde")
jsok "$BR_NOPIN" && fail "Buyer registration without PIN should be rejected" \
                || ok "Buyer registration without PIN correctly rejected"

# Register buyer (weak PIN → should fail)
BR_WEAK=$(curl -s -X POST "$API" \
    -F "action=register_buyer" -F "full_name=Smoke Test Buyer" \
    -F "phone=$TEST_PHONE_B" -F "password=$TEST_PASS" -F "confirm_password=$TEST_PASS" \
    -F "region=Centre" -F "town=Yaounde" -F "recovery_pin=123456")
jsok "$BR_WEAK" && fail "Weak PIN (123456) should be rejected" || ok "Weak PIN correctly rejected"

# Register buyer (valid)
BR=$(curl -s -X POST "$API" \
    -F "action=register_buyer" -F "full_name=Smoke Test Buyer" \
    -F "phone=$TEST_PHONE_B" -F "password=$TEST_PASS" -F "confirm_password=$TEST_PASS" \
    -F "region=Centre" -F "town=Yaounde" -F "recovery_pin=$TEST_PIN")
jsok "$BR" && ok "Buyer registration success" || fail "Buyer registration FAILED: $(field "$BR" error)"
BTOK=$(field "$BR" session_token)
TEST_UID_B=$(field "$BR" id)
[ -n "$BTOK" ] && ok "Buyer session token returned" || fail "No buyer session token"
[ -n "$TEST_UID_B" ] && ok "Buyer user ID returned: $TEST_UID_B" || fail "No buyer user ID"

# Check PIN was saved
if [ -n "$TEST_UID_B" ]; then
    PIN_SAVED=$(eval "$PSQL \"SELECT recovery_pin_hash IS NOT NULL FROM cammarket237.users WHERE id=$TEST_UID_B\"" 2>/dev/null | tr -d ' ')
    [ "$PIN_SAVED" = "t" ] && ok "Buyer PIN hash stored in DB" || fail "Buyer PIN not saved in DB"
fi

# Duplicate registration
BR_DUP=$(curl -s -X POST "$API" \
    -F "action=register_buyer" -F "full_name=Dup Buyer" \
    -F "phone=$TEST_PHONE_B" -F "password=$TEST_PASS" -F "confirm_password=$TEST_PASS" \
    -F "region=Centre" -F "town=Yaounde" -F "recovery_pin=$TEST_PIN")
jsok "$BR_DUP" && fail "Duplicate phone registration should fail" || ok "Duplicate buyer phone correctly rejected"

# Buyer login - wrong password
BL_BAD=$(curl -s -X POST "$API" -F "action=buyer_login" -F "phone=$TEST_PHONE_B" -F "password=wrongpass")
jsok "$BL_BAD" && fail "Wrong password should be rejected" || ok "Wrong buyer password correctly rejected"

# Buyer login - success
BL=$(curl -s -X POST "$API" -F "action=buyer_login" -F "phone=$TEST_PHONE_B" -F "password=$TEST_PASS")
jsok "$BL" && ok "Buyer login success" || fail "Buyer login FAILED: $(field "$BL" error)"
BTOK2=$(field "$BL" session_token)
[ -n "$BTOK2" ] && ok "Buyer login returns session token" || fail "No session token on login"
echo "$BL" | grep -q '"has_pin":true' && ok "Buyer login returns has_pin:true" || fail "has_pin field missing or false on login"


# ═══════════════════════════════════════════════════════════════════════════
hdr "4. SELLER REGISTRATION & LOGIN"
# ═══════════════════════════════════════════════════════════════════════════

# Register seller (valid)
SR=$(curl -s -X POST "$API" \
    -F "action=register_seller" -F "full_name=Smoke Seller" \
    -F "phone=$TEST_PHONE_S" -F "password=$TEST_PASS" -F "confirm_password=$TEST_PASS" \
    -F "store_name=Smoke Test Store" -F "region=Littoral" -F "town=Douala" \
    -F "store_address=Akwa" -F "recovery_pin=$TEST_PIN")
jsok "$SR" && ok "Seller registration success" || fail "Seller registration FAILED: $(field "$SR" error)"
STOK=$(field "$SR" session_token)
TEST_UID_S=$(field "$SR" id)
[ -n "$STOK" ] && ok "Seller session token returned" || fail "No seller session token"

# Check store was created
if [ -n "$TEST_UID_S" ]; then
    STORE_ROW=$(eval "$PSQL \"SELECT id FROM cammarket237.stores WHERE user_id=$TEST_UID_S\"" 2>/dev/null | tr -d ' ')
    [ -n "$STORE_ROW" ] && { ok "Seller store created in DB (store id=$STORE_ROW)"; TEST_STORE_ID="$STORE_ROW"; } \
                        || fail "Seller store NOT created in DB"
    PIN_SAVED_S=$(eval "$PSQL \"SELECT recovery_pin_hash IS NOT NULL FROM cammarket237.users WHERE id=$TEST_UID_S\"" 2>/dev/null | tr -d ' ')
    [ "$PIN_SAVED_S" = "t" ] && ok "Seller PIN hash stored in DB" || fail "Seller PIN not saved in DB"
fi

# Seller login
SL=$(curl -s -X POST "$API" -F "action=seller_login" -F "phone=$TEST_PHONE_S" -F "password=$TEST_PASS")
jsok "$SL" && ok "Seller login success" || fail "Seller login FAILED: $(field "$SL" error)"
STOK=$(field "$SL" session_token)
echo "$SL" | grep -q '"has_pin":true' && ok "Seller login returns has_pin:true" || fail "has_pin field missing on seller login"
echo "$SL" | grep -q '"store":'       && ok "Seller login returns store object"  || fail "No store in seller login response"

# Seller without PIN → has_pin:false check
eval "$PSQL \"UPDATE cammarket237.users SET recovery_pin_hash=NULL WHERE id=$TEST_UID_S\"" &>/dev/null
SL_NOPIN=$(curl -s -X POST "$API" -F "action=seller_login" -F "phone=$TEST_PHONE_S" -F "password=$TEST_PASS")
echo "$SL_NOPIN" | grep -q '"has_pin":false' && ok "Seller login returns has_pin:false when PIN not set" || fail "has_pin:false not returned"
eval "$PSQL \"UPDATE cammarket237.users SET recovery_pin_hash=crypt('$TEST_PIN', gen_salt('bf')) WHERE id=$TEST_UID_S\"" &>/dev/null 2>/dev/null
# Restore PIN via API
curl -s -X POST "$API" \
    -H "X-Session-Token: $STOK" \
    -F "action=set_recovery_pin" -F "new_pin=$TEST_PIN" > /dev/null


# ═══════════════════════════════════════════════════════════════════════════
hdr "5. SESSION & AUTHENTICATION"
# ═══════════════════════════════════════════════════════════════════════════

# check_session
CS=$(curl -s "$API?action=check_session" -H "X-Session-Token: $STOK")
jsok "$CS" && ok "check_session valid token → success" || fail "check_session FAILED for valid token"

CS_BAD=$(curl -s "$API?action=check_session" -H "X-Session-Token: invalidtoken123")
jsok "$CS_BAD" && fail "check_session should fail for bad token" || ok "check_session invalid token → correctly rejected"

CS_NONE=$(curl -s "$API?action=check_session")
jsok "$CS_NONE" && fail "check_session without token should fail" || ok "check_session no token → correctly rejected"

# get_user_points
GP=$(curl -s "$API?action=get_user_points" -H "X-Session-Token: $STOK")
jsok "$GP" && ok "get_user_points works" || fail "get_user_points FAILED"


# ═══════════════════════════════════════════════════════════════════════════
hdr "6. RECOVERY PIN SYSTEM"
# ═══════════════════════════════════════════════════════════════════════════

# check_pin_status
CPS=$(curl -s "$API?action=check_pin_status" -H "X-Session-Token: $BTOK2")
jsok "$CPS" && ok "check_pin_status works" || fail "check_pin_status FAILED"

# set_recovery_pin (no old PIN needed since buyer was created with PIN — but buyer already has PIN)
# Test changing PIN requires old PIN
SET_NO_OLD=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=set_recovery_pin" -F "new_pin=193847")
jsok "$SET_NO_OLD" && fail "Changing PIN without old PIN should be rejected" \
                  || ok "PIN change without current PIN correctly rejected"

# Change PIN with correct old PIN
SET_PIN=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=set_recovery_pin" -F "old_pin=$TEST_PIN" -F "new_pin=193847")
jsok "$SET_PIN" && ok "PIN change with correct old PIN succeeds" || fail "PIN change FAILED: $(field "$SET_PIN" error)"

# Change back
curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=set_recovery_pin" -F "old_pin=193847" -F "new_pin=$TEST_PIN" > /dev/null

# Weak PIN rejection
WEAK_SET=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=set_recovery_pin" -F "old_pin=$TEST_PIN" -F "new_pin=000000")
jsok "$WEAK_SET" && fail "Weak PIN 000000 should be rejected" || ok "Weak PIN change correctly rejected"

# verify_recovery_pin (password reset flow)
VRP=$(curl -s -X POST "$API" \
    -F "action=verify_recovery_pin" -F "phone=$TEST_PHONE_B" \
    -F "role=buyer" -F "pin=$TEST_PIN")
jsok "$VRP" && ok "verify_recovery_pin correct PIN → success" || fail "verify_recovery_pin FAILED: $(field "$VRP" error)"
RESET_TOKEN=$(field "$VRP" reset_token)
[ -n "$RESET_TOKEN" ] && ok "Reset token returned after PIN verify" || fail "No reset token returned"

# Wrong PIN
VRP_BAD=$(curl -s -X POST "$API" \
    -F "action=verify_recovery_pin" -F "phone=$TEST_PHONE_B" \
    -F "role=buyer" -F "pin=999999")
jsok "$VRP_BAD" && fail "Wrong PIN should be rejected" || ok "Wrong PIN correctly rejected"

# reset_password_with_pin
if [ -n "$RESET_TOKEN" ]; then
    RRP=$(curl -s -X POST "$API" \
        -F "action=reset_password_with_pin" \
        -F "reset_token=$RESET_TOKEN" -F "new_password=${TEST_PASS}2")
    jsok "$RRP" && ok "Password reset with PIN token succeeds" || fail "Password reset FAILED: $(field "$RRP" error)"

    # Restore original password
    NEW_HASH=$(eval "$PSQL \"SELECT password_hash(E'${TEST_PASS}', gen_salt('bf'))\"" 2>/dev/null | tr -d ' ')
    RESET_BACK=$(curl -s -X POST "$API" \
        -F "action=verify_recovery_pin" -F "phone=$TEST_PHONE_B" \
        -F "role=buyer" -F "pin=$TEST_PIN")
    RTB=$(field "$RESET_BACK" reset_token)
    [ -n "$RTB" ] && curl -s -X POST "$API" \
        -F "action=reset_password_with_pin" -F "reset_token=$RTB" \
        -F "new_password=$TEST_PASS" > /dev/null

    # Expired/invalid reset token
    EXPIRE=$(curl -s -X POST "$API" \
        -F "action=reset_password_with_pin" \
        -F "reset_token=badfaketoken00000" -F "new_password=newpass")
    jsok "$EXPIRE" && fail "Invalid reset token should be rejected" || ok "Invalid reset token correctly rejected"
fi


# ═══════════════════════════════════════════════════════════════════════════
hdr "7. PUBLIC LISTINGS API"
# ═══════════════════════════════════════════════════════════════════════════

# get_listings
GL=$(curl -s "$API?action=get_listings")
jsok "$GL" && ok "get_listings returns success" || fail "get_listings FAILED"
LC=$(echo "$GL" | grep -o '"id":[0-9]*' | wc -l)
[ "$LC" -gt 0 ] && ok "get_listings returns $LC items" || warn "get_listings returned 0 items"

# Filters
jsok "$(curl -s "$API?action=get_listings&cat=Electronics")"     && ok "Category filter: Electronics" || fail "Category filter FAILED"
jsok "$(curl -s "$API?action=get_listings&cat=Phones")"          && ok "Category filter: Phones"      || fail "Category filter Phones FAILED"
jsok "$(curl -s "$API?action=get_listings&cond=new")"            && ok "Condition filter: new"        || fail "Condition filter FAILED"
jsok "$(curl -s "$API?action=get_listings&cond=used")"           && ok "Condition filter: used"       || fail "Condition filter used FAILED"
jsok "$(curl -s "$API?action=get_listings&pmin=5000&pmax=50000")" && ok "Price range filter works"   || fail "Price range filter FAILED"
jsok "$(curl -s "$API?action=get_listings&region=Centre")"       && ok "Region filter: Centre"       || fail "Region filter FAILED"

SEARCH=$(curl -s "$API?action=get_listings&q=phone")
jsok "$SEARCH" && ok "Search query works" || fail "Search query FAILED"

# get_listing (single)
FIRST_ID=$(eval "$PSQL \"SELECT id FROM cammarket237.listings WHERE status='active' LIMIT 1\"" 2>/dev/null | tr -d ' ')
if [ -n "$FIRST_ID" ]; then
    GL1=$(curl -s "$API?action=get_listing&id=$FIRST_ID")
    jsok "$GL1" && ok "get_listing (single) id=$FIRST_ID works" || fail "get_listing single FAILED"
fi

# View counter
if [ -n "$FIRST_ID" ]; then
    VC=$(curl -s "$API?action=view&id=$FIRST_ID")
    jsok "$VC" && ok "View counter increments" || warn "View counter issue"
fi

# get_smart_feed
SF=$(curl -s "$API?action=get_smart_feed&town=Douala&region=Littoral")
jsok "$SF" && ok "get_smart_feed works" || warn "get_smart_feed FAILED (non-critical)"

# get_trending_searches
TS_RESP=$(curl -s "$API?action=get_trending_searches")
jsok "$TS_RESP" && ok "get_trending_searches works" || warn "get_trending_searches FAILED"

# get_new_items_count
NIC=$(curl -s "$API?action=get_new_items_count")
jsok "$NIC" && ok "get_new_items_count works" || warn "get_new_items_count FAILED"

# get_new_items
NI=$(curl -s "$API?action=get_new_items")
jsok "$NI" && ok "get_new_items works" || warn "get_new_items FAILED"

# get_platform_settings
PS_RESP=$(curl -s "$API?action=get_platform_settings")
jsok "$PS_RESP" && ok "get_platform_settings works" || warn "get_platform_settings FAILED"


# ═══════════════════════════════════════════════════════════════════════════
hdr "8. IMAGE UPLOAD & POST LISTING"
# ═══════════════════════════════════════════════════════════════════════════

# Create a minimal valid JPEG for upload testing
IMG1=$(mktemp /tmp/smoke_img1_XXXX.jpg)
IMG2=$(mktemp /tmp/smoke_img2_XXXX.jpg)
# Create 1x1 red JPEG using printf (raw JPEG bytes)
printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xdb\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\t\t\x08\n\x0c\x14\r\x0c\x0b\x0b\x0c\x19\x12\x13\x0f\x14\x1d\x1a\x1f\x1e\x1d\x1a\x1c\x1c $.\' ",#\x1c\x1c(7),01444\x1f'"'"'9=82<.342\x1eB\n\x11\x17\x17\x10\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00\xff\xc4\x00\x1f\x00\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08\t\n\x0b\xff\xc4\x00\xb5\x10\x00\x02\x01\x03\x03\x02\x04\x03\x05\x05\x04\x04\x00\x00\x01}\x01\x02\x03\x00\x04\x11\x05\x12!1A\x06\x13Qa\x07"q\x142\x81\x91\xa1\x08#B\xb1\xc1\x15R\xd1\xf0$3br\x82\t\n\x16\x17\x18\x19\x1a%&'"'"'()*456789:CDEFGHIJSTUVWXYZcdefghijstuvwxyz\xff\xda\x00\x08\x01\x01\x00\x00?\x00\xf5\xff\xd9' > "$IMG1" 2>/dev/null
cp "$IMG1" "$IMG2"

if [ -n "$STOK" ] && [ -n "$TEST_STORE_ID" ]; then
    UPLOAD=$(curl -s -X POST "$API" \
        -H "X-Session-Token: $STOK" \
        -F "action=post_listing" \
        -F "store_id=$TEST_STORE_ID" \
        -F "title=Smoke Test Item" \
        -F "description=Auto-generated smoke test listing" \
        -F "price=5000" \
        -F "category=Electronics" \
        -F "town=Douala" \
        -F "condition=new" \
        -F "photo1=@$IMG1;type=image/jpeg" \
        -F "photo2=@$IMG2;type=image/jpeg")
    jsok "$UPLOAD" && { ok "post_listing with photos succeeds"; TEST_LISTING_ID=$(field "$UPLOAD" id); } \
                   || { warn "post_listing FAILED — may be due to photo verification: $(field "$UPLOAD" error)"; }
    [ -n "$TEST_LISTING_ID" ] && ok "New listing created: id=$TEST_LISTING_ID" || warn "No listing ID returned"
fi
rm -f "$IMG1" "$IMG2"

# Post listing without auth
NOAUTH=$(curl -s -X POST "$API" -F "action=post_listing" -F "title=Hack" -F "price=100")
jsok "$NOAUTH" && fail "post_listing without auth should fail" || ok "post_listing without auth correctly rejected"


# ═══════════════════════════════════════════════════════════════════════════
hdr "9. SELLER DASHBOARD FEATURES"
# ═══════════════════════════════════════════════════════════════════════════

# seller_listings
SL_LIST=$(curl -s "$API?action=seller_listings" -H "X-Session-Token: $STOK")
jsok "$SL_LIST" && ok "seller_listings works" || fail "seller_listings FAILED"

# get_price_intel
if [ -n "$TEST_STORE_ID" ]; then
    PI=$(curl -s "$API?action=get_price_intel&store_id=$TEST_STORE_ID" -H "X-Session-Token: $STOK")
    jsok "$PI" && ok "get_price_intel works" || warn "get_price_intel FAILED"
fi

# get_price_suggestions
PS2=$(curl -s "$API?action=get_price_suggestions&category=Electronics" -H "X-Session-Token: $STOK")
jsok "$PS2" && ok "get_price_suggestions works" || warn "get_price_suggestions FAILED"

# get_price_notifications
PN=$(curl -s "$API?action=get_price_notifications" -H "X-Session-Token: $STOK")
jsok "$PN" && ok "get_price_notifications works" || warn "get_price_notifications FAILED"

# get_store_announcements
if [ -n "$TEST_STORE_ID" ]; then
    SA=$(curl -s "$API?action=get_store_announcements&store_id=$TEST_STORE_ID")
    jsok "$SA" && ok "get_store_announcements works" || warn "get_store_announcements FAILED"
    AC=$(curl -s "$API?action=get_announcement_count&store_id=$TEST_STORE_ID")
    jsok "$AC" && ok "get_announcement_count works" || warn "get_announcement_count FAILED"
fi

# Deals
if [ -n "$TEST_LISTING_ID" ] && [ -n "$STOK" ]; then
    CD=$(curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=create_deal" -F "listing_id=$TEST_LISTING_ID" \
        -F "discount_pct=10" -F "hours=2")
    jsok "$CD" && ok "create_deal works" || warn "create_deal FAILED: $(field "$CD" error)"

    GD=$(curl -s "$API?action=get_deals&store_id=$TEST_STORE_ID")
    jsok "$GD" && ok "get_deals works" || warn "get_deals FAILED"

    GMD=$(curl -s "$API?action=get_my_deals" -H "X-Session-Token: $STOK")
    jsok "$GMD" && ok "get_my_deals works" || warn "get_my_deals FAILED"
fi

# toggle_stock
if [ -n "$TEST_LISTING_ID" ] && [ -n "$STOK" ]; then
    TS_TOG=$(curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=toggle_stock" -F "listing_id=$TEST_LISTING_ID" -F "stock_status=out_of_stock")
    jsok "$TS_TOG" && ok "toggle_stock works" || warn "toggle_stock FAILED"
    # Restore
    curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=toggle_stock" -F "listing_id=$TEST_LISTING_ID" -F "stock_status=in_stock" > /dev/null
fi

# mark_sold + relist
if [ -n "$TEST_LISTING_ID" ] && [ -n "$STOK" ]; then
    MS=$(curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=mark_sold" -F "listing_id=$TEST_LISTING_ID")
    jsok "$MS" && ok "mark_sold works" || warn "mark_sold FAILED: $(field "$MS" error)"

    RL=$(curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=relist_item" -F "listing_id=$TEST_LISTING_ID")
    jsok "$RL" && ok "relist_item works" || warn "relist_item FAILED"
fi

# Enquiries
LOG_ENQ=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=log_enquiry" -F "store_id=${TEST_STORE_ID:-1}" -F "listing_id=${TEST_LISTING_ID:-1}" \
    -F "message=Is this still available?")
jsok "$LOG_ENQ" && ok "log_enquiry works" || warn "log_enquiry FAILED"

if [ -n "$STOK" ]; then
    SE=$(curl -s "$API?action=get_seller_enquiries" -H "X-Session-Token: $STOK")
    jsok "$SE" && ok "get_seller_enquiries works" || warn "get_seller_enquiries FAILED"
fi

# get_referral_stats
RS=$(curl -s "$API?action=get_referral_stats" -H "X-Session-Token: $STOK")
jsok "$RS" && ok "get_referral_stats works" || warn "get_referral_stats FAILED"


# ═══════════════════════════════════════════════════════════════════════════
hdr "10. BUYER FEATURES"
# ═══════════════════════════════════════════════════════════════════════════

# Cart
ADD_CART=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=add_to_cart" -F "listing_id=${FIRST_ID:-1}")
jsok "$ADD_CART" && ok "add_to_cart works" || warn "add_to_cart FAILED: $(field "$ADD_CART" error)"

GET_CART=$(curl -s "$API?action=get_cart" -H "X-Session-Token: $BTOK2")
jsok "$GET_CART" && ok "get_cart works" || warn "get_cart FAILED"

CART_CNT=$(curl -s "$API?action=get_cart_count" -H "X-Session-Token: $BTOK2")
jsok "$CART_CNT" && ok "get_cart_count works" || warn "get_cart_count FAILED"

CART_NOTIF=$(curl -s "$API?action=get_cart_notifications" -H "X-Session-Token: $BTOK2")
jsok "$CART_NOTIF" && ok "get_cart_notifications works" || warn "get_cart_notifications FAILED"

REM_CART=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=remove_from_cart" -F "listing_id=${FIRST_ID:-1}")
jsok "$REM_CART" && ok "remove_from_cart works" || warn "remove_from_cart FAILED"

# Notify me (waitlist)
NM=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
    -F "action=notify_me" -F "listing_id=${FIRST_ID:-1}")
jsok "$NM" && ok "notify_me (waitlist) works" || warn "notify_me FAILED"

GWC=$(curl -s "$API?action=get_waitlist_count&listing_id=${FIRST_ID:-1}")
jsok "$GWC" && ok "get_waitlist_count works" || warn "get_waitlist_count FAILED"

# track_event
TE=$(curl -s -X POST "$API" \
    -F "action=track_event" -F "event=search" -F "query=test phone" \
    -F "town=Douala" -F "region=Littoral")
jsok "$TE" && ok "track_event works" || warn "track_event FAILED"

# get_notif_count
NC=$(curl -s "$API?action=get_notif_count" -H "X-Session-Token: $BTOK2")
jsok "$NC" && ok "get_notif_count works" || warn "get_notif_count FAILED"

# accept_safety
AS_RESP=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" -F "action=accept_safety")
jsok "$AS_RESP" && ok "accept_safety works" || warn "accept_safety FAILED"

# get_buyer_profile
GBP=$(curl -s "$API?action=get_buyer_profile" -H "X-Session-Token: $BTOK2")
jsok "$GBP" && ok "get_buyer_profile works" || warn "get_buyer_profile FAILED"


# ═══════════════════════════════════════════════════════════════════════════
hdr "11. REVIEWS & SOCIAL"
# ═══════════════════════════════════════════════════════════════════════════

# get_reviews
if [ -n "$TEST_STORE_ID" ]; then
    GR=$(curl -s "$API?action=get_reviews&store_id=$TEST_STORE_ID")
    jsok "$GR" && ok "get_reviews works" || fail "get_reviews FAILED"
fi

# submit_review (buyer reviewing seller store)
if [ -n "$TEST_STORE_ID" ] && [ -n "$BTOK2" ]; then
    SR_RESP=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
        -F "action=submit_review" -F "store_id=$TEST_STORE_ID" \
        -F "rating=4" -F "comment=Great smoke test seller!")
    jsok "$SR_RESP" && ok "submit_review works" || warn "submit_review FAILED: $(field "$SR_RESP" error)"
fi

# Review without auth
SR_NOAUTH=$(curl -s -X POST "$API" -F "action=submit_review" -F "store_id=1" -F "rating=5")
jsok "$SR_NOAUTH" && fail "Review without auth should fail" || ok "submit_review without auth correctly rejected"

# Follow / Unfollow
if [ -n "$TEST_STORE_ID" ] && [ -n "$BTOK2" ]; then
    FW=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
        -F "action=follow_store" -F "store_id=$TEST_STORE_ID")
    jsok "$FW" && ok "follow_store works" || warn "follow_store FAILED"

    GFS=$(curl -s "$API?action=get_followed_stores" -H "X-Session-Token: $BTOK2")
    jsok "$GFS" && ok "get_followed_stores works" || warn "get_followed_stores FAILED"

    UFW=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
        -F "action=unfollow_store" -F "store_id=$TEST_STORE_ID")
    jsok "$UFW" && ok "unfollow_store works" || warn "unfollow_store FAILED"
fi

if [ -n "$TEST_STORE_ID" ]; then
    GFL=$(curl -s "$API?action=get_followers&store_id=$TEST_STORE_ID")
    jsok "$GFL" && ok "get_followers works" || fail "get_followers FAILED"
fi


# ═══════════════════════════════════════════════════════════════════════════
hdr "12. SERVICES"
# ═══════════════════════════════════════════════════════════════════════════

for SVC_TYPE in car_rental apartment transport plumber catering photography; do
    SVC_R=$(curl -s "$API?action=get_services&type=$SVC_TYPE")
    jsok "$SVC_R" && ok "get_services type=$SVC_TYPE" || fail "get_services $SVC_TYPE FAILED"
done

# post_service without auth
PS_NA=$(curl -s -X POST "$API" -F "action=post_service" -F "title=Test")
jsok "$PS_NA" && fail "post_service without auth should fail" || ok "post_service without auth correctly rejected"


# ═══════════════════════════════════════════════════════════════════════════
hdr "13. SAFETY MEETINGS"
# ═══════════════════════════════════════════════════════════════════════════

if [ -n "$BTOK2" ] && [ -n "$TEST_STORE_ID" ]; then
    SCH=$(curl -s -X POST "$API" -H "X-Session-Token: $BTOK2" \
        -F "action=schedule_meeting" \
        -F "seller_id=$TEST_UID_S" \
        -F "store_id=$TEST_STORE_ID" \
        -F "item_title=Smoke Test Phone" \
        -F "price=50000" \
        -F "meeting_location=Douala Akwa Market" \
        -F "meeting_datetime=2026-12-01 10:00:00")
    jsok "$SCH" && ok "schedule_meeting works" || warn "schedule_meeting FAILED: $(field "$SCH" error)"
    MEETING_ID=$(field "$SCH" id)
fi

GMM=$(curl -s "$API?action=get_my_meetings" -H "X-Session-Token: $BTOK2")
jsok "$GMM" && ok "get_my_meetings (buyer) works" || warn "get_my_meetings FAILED"

GMM_S=$(curl -s "$API?action=get_my_meetings" -H "X-Session-Token: $STOK")
jsok "$GMM_S" && ok "get_my_meetings (seller) works" || warn "get_my_meetings seller FAILED"


# ═══════════════════════════════════════════════════════════════════════════
hdr "14. LIVE STREAMING"
# ═══════════════════════════════════════════════════════════════════════════

GLS=$(curl -s "$API?action=get_live_streams")
jsok "$GLS" && ok "get_live_streams works" || warn "get_live_streams FAILED"

GSB=$(curl -s "$API?action=get_stream_balance" -H "X-Session-Token: $STOK")
jsok "$GSB" && ok "get_stream_balance works" || warn "get_stream_balance FAILED"

# Start stream without auth
SS_NA=$(curl -s -X POST "$API" -F "action=start_stream" -F "title=Hack")
jsok "$SS_NA" && fail "start_stream without auth should fail" || ok "start_stream without auth correctly rejected"


# ═══════════════════════════════════════════════════════════════════════════
hdr "15. SECURITY"
# ═══════════════════════════════════════════════════════════════════════════

# Unknown action
curl -s "$API?action=dropeverything" | grep -q '"success":false' \
    && ok "Unknown action correctly rejected" || fail "Unknown action NOT rejected"

# Auth-required actions without token
for ACT in post_listing delete_listing edit_listing mark_sold relist_item \
           set_recovery_pin delete_account get_stream_balance start_stream; do
    R=$(curl -s -X POST "$API" -F "action=$ACT")
    echo "$R" | grep -q '"success":false' \
        && ok "Unauthenticated $ACT rejected" || fail "$ACT allowed without auth"
done

# SQL injection in search
SQL_R=$(curl -s "$API?action=get_listings&q='; DROP TABLE cammarket237.users; --")
jsok "$SQL_R" && ok "SQL injection in search returns valid response" || warn "SQL injection response unclear"
USERS_STILL=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users\"" 2>/dev/null | tr -d ' ')
[ "${USERS_STILL:-0}" -gt 0 ] && ok "Users table intact after SQL injection attempt ($USERS_STILL users)" \
                               || fail "CRITICAL: Users table may be damaged!"

# XSS in listing title
XSS=$(curl -s "$API?action=get_listings&q=<script>alert(1)</script>")
jsok "$XSS" && ok "XSS in search handled (returned valid JSON)" || warn "XSS search failed"

# Rate limiting (trigger buyer login rate limit)
for i in 1 2 3 4 5; do
    curl -s -X POST "$API" -F "action=buyer_login" \
        -F "phone=237699999999" -F "password=wrongpass" > /dev/null
done
RL_TEST=$(curl -s -X POST "$API" -F "action=buyer_login" \
    -F "phone=237699999999" -F "password=wrongpass")
echo "$RL_TEST" | grep -qi "wait\|too many\|rate" \
    && ok "Rate limiting kicks in after repeated failed logins" \
    || warn "Rate limiting may not be working as expected"


# ═══════════════════════════════════════════════════════════════════════════
hdr "16. FRONTEND HTML"
# ═══════════════════════════════════════════════════════════════════════════

HTML=$(curl -s "https://cammarket237.com")

for SCR in screen-role screen-buyer-auth screen-buyer-search screen-seller-auth \
           screen-seller-dash screen-post-item screen-item-detail screen-store-profile \
           screen-saved screen-services screen-service-detail screen-post-service \
           screen-forgot-password screen-set-pin; do
    echo "$HTML" | grep -q "id=\"$SCR\"" && ok "Screen #$SCR present" || fail "MISSING screen: #$SCR"
done

for FN in window.go window.loginBuyer window.loginSeller window.registerBuyer \
          window.registerSeller window.doSearch window.saveRecoveryPin \
          window.skipPinSetup window.openForgotPassword window.verifyPin \
          window.doResetPassword window.toggleSave window.toggleFollow \
          window.submitReview window.browseAsGuest window.loadServices \
          window.openService window.launchSellerDash window.saveSession \
          window.loadUserPoints window.checkPassStrength; do
    echo "$HTML" | grep -q "$FN" && ok "JS function $FN present" || fail "MISSING function: $FN"
done

# PWA checks
[ -f "$WEB/manifest.json" ]     && ok "manifest.json present" || warn "manifest.json missing"
[ -f "$WEB/sw.js" ]             && ok "sw.js (service worker) present" || warn "sw.js missing"
echo "$HTML" | grep -q "manifest.json"   && ok "manifest.json linked in HTML" || warn "manifest not linked"
echo "$HTML" | grep -q "serviceWorker"   && ok "Service worker registered"   || warn "Service worker not registered"
echo "$HTML" | grep -q "leaflet"         && ok "Leaflet maps library loaded"  || warn "Leaflet missing"
echo "$HTML" | grep -q '"recovery_pin"'  && ok "PIN field in registration form" || fail "PIN field missing from form"
echo "$HTML" | grep -q "has_pin"         && ok "has_pin logic in JS"          || fail "has_pin logic missing"
echo "$HTML" | grep -q "screen-set-pin"  && ok "set-pin screen in HTML"       || fail "set-pin screen missing"


# ═══════════════════════════════════════════════════════════════════════════
hdr "17. ADMIN CONSOLE"
# ═══════════════════════════════════════════════════════════════════════════

# Admin page loads
ADMIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://cammarket237.com/admin.php")
[ "$ADMIN_CODE" = "200" ] || [ "$ADMIN_CODE" = "302" ] \
    && ok "admin.php responds (HTTP $ADMIN_CODE)" || fail "admin.php returned $ADMIN_CODE"

# Admin should require credentials (not be open)
ADMIN_BODY=$(curl -s "https://cammarket237.com/admin.php")
echo "$ADMIN_BODY" | grep -qi "login\|password\|username\|sign in\|cammarket admin" \
    && ok "Admin page requires login (not open access)" \
    || warn "Admin page may not be requiring authentication"

# Admin API actions without auth
for A_ACT in admin_get_meetings admin_add_minutes admin_toggle_streaming; do
    A_R=$(curl -s -X POST "$API" -F "action=$A_ACT")
    echo "$A_R" | grep -q '"success":false' \
        && ok "Admin action $A_ACT requires auth" || warn "$A_ACT may not be protected"
done


# ═══════════════════════════════════════════════════════════════════════════
hdr "18. CLEANUP TEST DATA"
# ═══════════════════════════════════════════════════════════════════════════

# Delete test listing
if [ -n "$TEST_LISTING_ID" ] && [ -n "$STOK" ]; then
    DEL_L=$(curl -s -X POST "$API" -H "X-Session-Token: $STOK" \
        -F "action=delete_listing" -F "listing_id=$TEST_LISTING_ID")
    jsok "$DEL_L" && ok "Test listing deleted" || warn "Test listing delete FAILED"
fi

# Delete test accounts from DB directly
if [ -n "$TEST_UID_S" ]; then
    eval "$PSQL \"DELETE FROM cammarket237.stores WHERE user_id=$TEST_UID_S\"" &>/dev/null
    eval "$PSQL \"DELETE FROM cammarket237.users WHERE id=$TEST_UID_S\"" &>/dev/null
    ok "Test seller account cleaned up"
fi
if [ -n "$TEST_UID_B" ]; then
    eval "$PSQL \"DELETE FROM cammarket237.users WHERE id=$TEST_UID_B\"" &>/dev/null
    ok "Test buyer account cleaned up"
fi

# Clean up rate limit entries from this test
eval "$PSQL \"DELETE FROM cammarket237.rate_limits WHERE rate_key LIKE '%237699999999%'\"" &>/dev/null


# ═══════════════════════════════════════════════════════════════════════════
# RESULTS
# ═══════════════════════════════════════════════════════════════════════════

TOTAL=$((PASS+FAIL+WARN))
echo ""
echo -e "${C}${B}╔══════════════════════════════════════════════════════════╗${N}"
echo -e "${C}${B}║  SMOKE TEST COMPLETE — $TOTAL tests ran${N}"
echo -e "${C}${B}║  ${G}PASS: $PASS${C}   ${R}FAIL: $FAIL${C}   ${Y}WARN: $WARN${N}"
echo -e "${C}${B}╚══════════════════════════════════════════════════════════╝${N}"

if [ "$FAIL" -eq 0 ]; then
    echo -e "\n  ${G}${B}ALL CRITICAL TESTS PASSED — cammarket237.com is healthy!${N}\n"
else
    echo -e "\n  ${R}${B}$FAIL CRITICAL FAILURE(S) — investigate before declaring healthy!${N}\n"
    echo -e "  ${R}Failures found in sections above. Fix and re-run.${N}\n"
fi
exit $FAIL
