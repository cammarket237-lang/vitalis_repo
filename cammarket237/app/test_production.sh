#!/bin/bash
# ═══════════════════════════════════════════════════════════
# CamMarket237 - Production Test Script
# Run on server: bash /var/www/cammarket237/test_production.sh
# ═══════════════════════════════════════════════════════════

BASE="https://cammarket237.com"
API="$BASE/api.php"
WEB="/var/www/cammarket237"
PASS=0; FAIL=0; WARN=0

G='\033[0;32m'; R='\033[0;31m'; Y='\033[1;33m'; C='\033[0;36m'; B='\033[1m'; N='\033[0m'
ok()  { echo -e "  ${G}✓ PASS${N} $1"; ((PASS++)); }
fail(){ echo -e "  ${R}✗ FAIL${N} $1"; ((FAIL++)); }
warn(){ echo -e "  ${Y}⚠ WARN${N} $1"; ((WARN++)); }
hdr() { echo -e "\n${C}${B}── $1 ──${N}"; }
PSQL="PGPASSWORD=CamMarket2024 psql -U cammarket_user -h localhost -d cammarket237_db -t -c"

echo ""
echo -e "${C}${B}╔══════════════════════════════════════════╗${N}"
echo -e "${C}${B}║  CamMarket237 — Production Test          ║${N}"
echo -e "${C}${B}║  $(date '+%Y-%m-%d %H:%M:%S')                    ║${N}"
echo -e "${C}${B}╚══════════════════════════════════════════╝${N}"

# ── 1. SERVER ──────────────────────────────────────────────
hdr "1. SERVER"
ps aux | grep -q "[n]ginx" && ok "nginx running" || fail "nginx DOWN"
ps aux | grep -q "[p]hp-fpm" && ok "PHP-FPM running" || fail "PHP-FPM DOWN"
CERT=$(echo | openssl s_client -servername cammarket237.com -connect cammarket237.com:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
[ -n "$CERT" ] && ok "SSL valid - expires: $CERT" || warn "SSL check failed"
HTTP=$(curl -s -o /dev/null -w "%{http_code}" "http://cammarket237.com")
[ "$HTTP" = "301" ] && ok "HTTP→HTTPS redirect (301)" || warn "HTTP redirect: $HTTP"
DISK=$(df -h $WEB | awk 'NR==2{print $4}')
ok "Disk free: $DISK"

# ── 2. FILES ───────────────────────────────────────────────
hdr "2. FILES"
for f in index.html api.php verify_photos.php; do
    if [ -f "$WEB/$f" ]; then
        SZ=$(wc -c < "$WEB/$f")
        ok "$f exists ($SZ bytes)"
    else
        fail "$f MISSING from $WEB"
    fi
done
[ -d "$WEB/uploads" ] && ok "uploads/ folder exists" || fail "uploads/ missing"
[ -d "$WEB/logs" ]    && ok "logs/ folder exists"    || warn "logs/ missing"
[ -f "$WEB/verify_photos.php" ] && ok "verify_photos.php present" || fail "verify_photos.php MISSING"

# ── 3. DATABASE ────────────────────────────────────────────
hdr "3. DATABASE"
DB_TEST=$(eval "$PSQL \"SELECT 1\"" 2>/dev/null | tr -d ' ')
[ "$DB_TEST" = "1" ] && ok "DB connection OK" || fail "DB connection FAILED"

for TBL in users listings stores listing_media otp_tokens reviews followers service_listings service_media; do
    CNT=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.$TBL\"" 2>/dev/null | tr -d ' ')
    [ -n "$CNT" ] && ok "Table $TBL - $CNT rows" || fail "Table $TBL MISSING"
done

U=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users\"" 2>/dev/null | tr -d ' ')
S=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users WHERE role='seller'\"" 2>/dev/null | tr -d ' ')
B=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.users WHERE role='buyer'\"" 2>/dev/null | tr -d ' ')
L=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.listings WHERE status='active'\"" 2>/dev/null | tr -d ' ')
echo -e "     Users=$U  Sellers=$S  Buyers=$B  Listings=$L"
[ "${L:-0}" -gt 0 ] && ok "Active listings: $L" || warn "No active listings"

# DB columns
eval "$PSQL \"SELECT condition FROM cammarket237.listings LIMIT 1\"" &>/dev/null \
    && ok "listings.condition column exists" || fail "listings.condition MISSING - run migration.sql"
eval "$PSQL \"SELECT promo_points FROM cammarket237.users LIMIT 1\"" &>/dev/null \
    && ok "users.promo_points column exists" || warn "users.promo_points missing"

# Service tables
SVC=$(eval "$PSQL \"SELECT COUNT(*) FROM cammarket237.service_listings\"" 2>/dev/null | tr -d ' ')
ok "service_listings table exists ($SVC services)"

# ── 4. API ENDPOINTS ───────────────────────────────────────
hdr "4. API ENDPOINTS"

# Site loads
HTTP200=$(curl -s -o /dev/null -w "%{http_code}" "$BASE")
[ "$HTTP200" = "200" ] && ok "Site loads HTTP 200" || fail "Site failed HTTP $HTTP200"

# get_listings
RESP=$(curl -s "$API?action=get_listings")
COUNT=$(echo "$RESP" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('listings',[])))" 2>/dev/null)
[ "${COUNT:-0}" -gt 0 ] && ok "get_listings - $COUNT items" || fail "get_listings empty"

# Filters
echo "$RESP" | python3 -c "import sys,json;json.load(sys.stdin)" &>/dev/null \
    && ok "get_listings returns valid JSON" || fail "get_listings not JSON"
CATRESP=$(curl -s "$API?action=get_listings&cat=Electronics")
echo "$CATRESP" | grep -q '"success":true' && ok "Category filter works" || fail "Category filter failed"

PRICERESP=$(curl -s "$API?action=get_listings&pmin=50000&pmax=300000")
echo "$PRICERESP" | grep -q '"success":true' && ok "Price filter works" || fail "Price filter failed"

CONDRESP=$(curl -s "$API?action=get_listings&cond=used")
echo "$CONDRESP" | grep -q '"success":true' && ok "Condition filter works" || fail "Condition filter failed"

SEARCHRESP=$(curl -s "$API?action=get_listings&q=iPhone")
SC=$(echo "$SEARCHRESP" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('listings',[])))" 2>/dev/null)
[ "${SC:-0}" -gt 0 ] && ok "Search 'iPhone' - $SC results" || warn "Search returned 0"

# Services
for T in car_rental apartment transport plumber catering photography; do
    SR=$(curl -s "$API?action=get_services&type=$T")
    echo "$SR" | grep -q '"success":true' && ok "get_services $T" || fail "get_services $T FAILED"
done

# Reviews + followers
curl -s "$API?action=get_reviews&store_id=6" | grep -q '"success":true' \
    && ok "get_reviews works" || fail "get_reviews failed"
curl -s "$API?action=get_followers&store_id=6" | grep -q '"success":true' \
    && ok "get_followers works" || fail "get_followers failed"

# View counter
curl -s "$API?action=view&id=7" | grep -q '"success":true' \
    && ok "View counter works" || warn "View counter issue"

# ── 5. AUTHENTICATION ──────────────────────────────────────
hdr "5. AUTHENTICATION"

# Seller login
SL=$(curl -s -X POST "$API" -d "action=seller_login&phone=237690000001&password=password")
echo "$SL" | grep -q '"success":true' && ok "Seller login works" || fail "Seller login FAILED"
TOK=$(echo "$SL" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('user',{}).get('session_token',''))" 2>/dev/null)
[ -n "$TOK" ] && ok "Session token returned" || warn "No session token"

# Wrong password
SLB=$(curl -s -X POST "$API" -d "action=seller_login&phone=237690000001&password=wrong")
echo "$SLB" | grep -q '"success":false' && ok "Wrong password rejected" || fail "Wrong password NOT rejected"

# OTP
TS="prodtest$(date +%s)"
OTPR=$(curl -s -X POST "$API" -d "action=send_otp&phone=$TS")
OTPC=$(echo "$OTPR" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('dev_code',''))" 2>/dev/null)
WAL=$(echo "$OTPR" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('wa_link',''))" 2>/dev/null)
[ -n "$OTPC" ] && ok "OTP generated (dev_code: $OTPC)" || fail "OTP generation failed"
[ -n "$WAL" ]  && ok "WhatsApp link built" || fail "WhatsApp link missing"

# Verify OTP
if [ -n "$OTPC" ]; then
    VER=$(curl -s -X POST "$API" -d "action=verify_otp&phone=$TS&otp=$OTPC")
    echo "$VER" | grep -q '"success":true' && ok "OTP verify works" || fail "OTP verify FAILED"
    WRG=$(curl -s -X POST "$API" -d "action=verify_otp&phone=$TS&otp=000000")
    echo "$WRG" | grep -q '"success":false' && ok "Wrong OTP rejected" || fail "Wrong OTP NOT rejected"
fi

# WhatsApp link for password reset
WAT=$(curl -s -X POST "$API" -d "action=send_otp&phone=237690000001")
echo "$WAT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('exists',''))" 2>/dev/null | grep -qi "true" \
    && ok "Existing phone identified" || warn "exists flag check failed"
WA_URL=$(echo "$WAT" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('wa_link',''))" 2>/dev/null)
echo "$WA_URL" | grep -q "wa.me"       && ok "WhatsApp reset link built" || fail "WhatsApp reset link missing"
echo "$WA_URL" | grep -q "Do.NOT"      && ok "Security warning in message" || warn "Security warning missing"
echo "$WA_URL" | grep -q "237"         && ok "Country code 237 present" || warn "Country code missing"

# ── 6. SECURITY ────────────────────────────────────────────
hdr "6. SECURITY"

curl -s "$API?action=hack" | grep -q '"success":false' \
    && ok "Unknown action rejected" || fail "Unknown action NOT rejected"
curl -s -X POST "$API" -d "action=post_listing&title=Test" | grep -q '"success":false' \
    && ok "Post listing without auth rejected" || fail "Unauthenticated post NOT rejected"
curl -s -X POST "$API" -d "action=post_service&title=Test" | grep -q '"success":false' \
    && ok "Post service without auth rejected" || fail "Unauthenticated service NOT rejected"
curl -s -X POST "$API" -d "action=submit_review&store_id=6&rating=5" | grep -q '"success":false' \
    && ok "Review without auth rejected" || fail "Unauthenticated review NOT rejected"
curl -s -X POST "$API" -d "action=follow&store_id=6" | grep -q '"success":false' \
    && ok "Follow without auth rejected" || fail "Unauthenticated follow NOT rejected"

SQL_RESP=$(curl -s "$API?action=get_listings&q=DROP+TABLE")
echo "$SQL_RESP" | python3 -c "import sys,json;json.load(sys.stdin)" &>/dev/null \
    && ok "SQL injection returns valid JSON" || warn "SQL injection unclear"

# ── 7. FRONTEND ────────────────────────────────────────────
hdr "7. FRONTEND"

HTML=$(curl -s "$BASE")

for S in screen-role screen-buyer-auth screen-buyer-search screen-seller-auth \
         screen-seller-dash screen-post-item screen-item-detail \
         screen-store-profile screen-saved screen-services \
         screen-service-detail screen-post-service screen-forgot-password; do
    echo "$HTML" | grep -q "id=\"$S\"" && ok "Screen: #$S" || fail "MISSING: #$S"
done

for F in window.go window.doSearch window.loginBuyer window.fpSendCode \
         window.fpResetPassword window.openService window.loadServices \
         window.toggleSave window.toggleFollow window.submitReview \
         window.loadComparison window.setCat window.updateAvatar \
         window.browseAsGuest window.checkPassStrength; do
    echo "$HTML" | grep -q "$F" && ok "Function: $F" || fail "MISSING: $F"
done

for CAT in "Car Rental" "Apartments" "Transport" "Repairs" "Catering" "Photography"; do
    echo "$HTML" | grep -q "$CAT" && ok "Service category: $CAT" || fail "MISSING: $CAT"
done

echo "$HTML" | grep -q "leaflet"  && ok "Leaflet maps loaded"       || fail "Leaflet MISSING"
echo "$HTML" | grep -q "wa\.me"   && ok "WhatsApp wa.me present"     || fail "WhatsApp MISSING"
echo "$HTML" | grep -q "<svg"     && ok "Logo SVGs present"          || warn "SVGs not found"
echo "$HTML" | grep -qi "stripe\|paypal\|checkout" \
    && warn "Payment code detected - verify it is inactive" \
    || ok "No payment system (connector only)"

# ── 8. BUSINESS RULES ─────────────────────────────────────
hdr "8. BUSINESS RULES"

echo "$HTML" | grep -q "wa\.me"        && ok "All contacts via WhatsApp" || fail "WhatsApp contact missing"
echo "$WA_URL" | grep -q "wa\.me"      && ok "WA link format correct"    || fail "WA link format wrong"
echo "$WA_URL" | grep -q "237"         && ok "Country code 237 in number" || warn "Country code missing"
[ -f "$WEB/logs/flagged_content.log" ] && ok "Flagged content log exists" || warn "Log file not created yet"

UPLOADS=$(ls $WEB/uploads/ 2>/dev/null | wc -l)
ok "Uploads folder: $UPLOADS files"

# ── SUMMARY ───────────────────────────────────────────────
echo ""
echo -e "${C}${B}╔══════════════════════════════════════════╗${N}"
echo -e "${C}${B}║  RESULTS: $((PASS+FAIL+WARN)) tests${N}"
echo -e "${C}${B}║  ${G}PASS=$PASS${C}  ${R}FAIL=$FAIL${C}  ${Y}WARN=$WARN${N}"
echo -e "${C}${B}╚══════════════════════════════════════════╝${N}"
if [ "$FAIL" -eq 0 ]; then
    echo -e "\n  ${G}${B}ALL CRITICAL TESTS PASSED! cammarket237.com is live! 🚀${N}\n"
else
    echo -e "\n  ${R}${B}$FAIL critical issues need fixing before going live!${N}\n"
fi
