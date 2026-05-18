#!/bin/bash
BASE="https://cammarket237.com/api.php"
PASS=0; FAIL=0
green='\033[0;32m'; red='\033[0;31m'; nc='\033[0m'
ok()   { echo -e "${green}✅ PASS${nc}: $1"; ((PASS++)); }
fail() { echo -e "${red}❌ FAIL${nc}: $1"; ((FAIL++)); echo "     → ${2:0:80}"; }
is_ok() { echo "$1" | grep -q '"success":true'; }
has()   { echo "$1" | grep -q "$2"; }

echo "======================================================"
echo " CamMarket237 Final Smoke Test — $(date)"
echo "======================================================"

echo -e "\n📁 STATIC FILES"
for f in "manifest.json" "sw.js" "icon-72.png" "icon-192.png" "icon-512.png" ".well-known/assetlinks.json" "privacy.php"; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://cammarket237.com/$f")
  [ "$code" = "200" ] && ok "$f" || fail "$f" "HTTP $code"
done

echo -e "\n📦 PUBLIC APIs"
R=$(curl -s "$BASE?action=get_listings"); is_ok "$R" && ok "get_listings ($(echo $R | python3 -c 'import sys,json;print(len(json.load(sys.stdin).get("listings",[])))' 2>/dev/null) items)" || fail "get_listings" "$R"
for cat in Electronics Cars Fashion Kids Food Furniture Health Services Transportation Agriculture; do
  R=$(curl -s "$BASE?action=get_listings&cat=$cat")
  is_ok "$R" && has "$R" '"id":' && ok "get_listings?cat=$cat" || fail "get_listings?cat=$cat" "$R"
done
R=$(curl -s "$BASE?action=get_smart_feed&town=Douala"); is_ok "$R" && ok "get_smart_feed" || fail "get_smart_feed" "$R"
R=$(curl -s "$BASE?action=get_live_streams"); is_ok "$R" && ok "get_live_streams" || fail "get_live_streams" "$R"
R=$(curl -s "$BASE?action=get_platform_settings"); is_ok "$R" && ok "get_platform_settings" || fail "get_platform_settings" "$R"
R=$(curl -s "$BASE?action=get_new_items_count"); is_ok "$R" && ok "get_new_items_count ($(echo $R | python3 -c 'import sys,json;print(json.load(sys.stdin).get("count",0))' 2>/dev/null) items)" || fail "get_new_items_count" "$R"
R=$(curl -s "$BASE?action=get_new_items"); is_ok "$R" && ok "get_new_items" || fail "get_new_items" "$R"
R=$(curl -s "$BASE?action=get_deals"); is_ok "$R" && ok "get_deals ($(echo $R | python3 -c 'import sys,json;print(len(json.load(sys.stdin).get("deals",[])))' 2>/dev/null) deals)" || fail "get_deals" "$R"

echo -e "\n🔐 AUTHENTICATION"
R=$(curl -s -X POST "$BASE" -d 'action=buyer_login&phone=674218700&password=123456'); is_ok "$R" && ok "buyer_login Albert (674218700)" || fail "buyer_login Albert" "$R"
BT=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['user']['session_token'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE" -d 'action=seller_login&phone=2408388119&password=Test1234'); is_ok "$R" && ok "seller_login Vitalis (2408388119)" || fail "seller_login Vitalis" "$R"
ST=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['user']['session_token'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE" -d 'action=seller_login&phone=674218700&password=123456'); is_ok "$R" && ok "seller_login Albert (674218700)" || fail "seller_login Albert" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=seller_login&phone=237674218700&password=123456'); is_ok "$R" && ok "seller_login 237674218700" || fail "seller_login 237674218700" "$R"
R=$(curl -s -X POST "$BASE" -d "action=seller_login&phone=%2B237674218700&password=123456"); is_ok "$R" && ok "seller_login +237674218700" || fail "seller_login +237674218700" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=seller_login&phone=12408388119&password=Test1234'); is_ok "$R" && ok "seller_login 12408388119 (11-digit US)" || fail "seller_login 12408388119" "$R"
R=$(curl -s -X POST "$BASE" -d "action=seller_login&phone=%2B12408388119&password=Test1234"); is_ok "$R" && ok "seller_login +12408388119" || fail "seller_login +12408388119" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=buyer_login&phone=674211428&password=Shekina16'); is_ok "$R" && ok "buyer_login Manka (674211428)" || fail "buyer_login Manka" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=buyer_login&phone=000000000&password=wrong'); has "$R" '"success":false' && ok "buyer_login invalid (rejected)" || fail "buyer_login invalid" "$R"
echo "   → Buyer token:  ${BT:0:16}..."
echo "   → Seller token: ${ST:0:16}..."

echo -e "\n🛒 BUYER FEATURES"
R=$(curl -s "$BASE?action=get_notif_count" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_notif_count" || fail "get_notif_count" "$R"
R=$(curl -s "$BASE?action=get_cart" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_cart" || fail "get_cart" "$R"
R=$(curl -s "$BASE?action=get_followed_stores" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_followed_stores" || fail "get_followed_stores" "$R"
R=$(curl -s "$BASE?action=get_my_meetings" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_my_meetings (buyer)" || fail "get_my_meetings buyer" "$R"
R=$(curl -s "$BASE?action=get_referral_stats" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_referral_stats (buyer)" || fail "get_referral_stats buyer" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $BT" -d 'action=accept_safety&listing_id=7'); is_ok "$R" && ok "accept_safety" || fail "accept_safety" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $BT" -d 'action=log_enquiry&listing_id=7&buyer_name=Test&buyer_phone=237699000001'); is_ok "$R" && ok "log_enquiry" || fail "log_enquiry" "$R"

echo -e "\n🏪 SELLER FEATURES"
# Re-login to get fresh seller token (previous logins invalidated it)
ST=$(curl -s -X POST "$BASE" -d 'action=seller_login&phone=2408388119&password=Test1234' | python3 -c "import sys,json;print(json.load(sys.stdin)['user']['session_token'])" 2>/dev/null)
echo "   → Fresh seller token: ${ST:0:16}..."
# Re-login for fresh seller token
ST=$(curl -s -X POST "$BASE" -d "action=seller_login&phone=2408388119&password=Test1234" | python3 -c "import sys,json;print(json.load(sys.stdin)[\"user\"][\"session_token\"])" 2>/dev/null)
R=$(curl -s "$BASE?action=get_stream_balance" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_stream_balance ($(echo $R | python3 -c 'import sys,json;b=json.load(sys.stdin).get("balance",{});print(b.get("minutes_available","?"))' 2>/dev/null) mins)" || fail "get_stream_balance" "$R"
R=$(curl -s "$BASE?action=get_seller_enquiries" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_seller_enquiries" || fail "get_seller_enquiries" "$R"
R=$(curl -s "$BASE?action=get_my_meetings" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_my_meetings (seller)" || fail "get_my_meetings seller" "$R"
R=$(curl -s "$BASE?action=get_referral_stats" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_referral_stats (seller)" || fail "get_referral_stats seller" "$R"
echo "   → Code: $(echo $R | python3 -c 'import sys,json;print(json.load(sys.stdin).get("referral_code",""))' 2>/dev/null)"
R=$(curl -s "$BASE?action=get_my_deals" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_my_deals" || fail "get_my_deals" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=update_store_location&lat=4.0511&lng=9.7679'); is_ok "$R" && ok "update_store_location" || fail "update_store_location" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=toggle_stock&listing_id=206&stock_status=out_of_stock'); is_ok "$R" && ok "toggle_stock → out_of_stock" || fail "toggle_stock out_of_stock" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=toggle_stock&listing_id=206&stock_status=coming_soon'); is_ok "$R" && ok "toggle_stock → coming_soon" || fail "toggle_stock coming_soon" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=toggle_stock&listing_id=206&stock_status=in_stock'); is_ok "$R" && ok "toggle_stock → in_stock (reset)" || fail "toggle_stock reset" "$R"

echo -e "\n🔥 DEALS"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=create_deal&listing_id=206&discount_percent=25&duration=24h&deal_type=flash'); is_ok "$R" && ok "create_deal (25% flash)" || fail "create_deal" "$R"
R=$(curl -s "$BASE?action=get_deals"); is_ok "$R" && ok "get_deals ($(echo $R | python3 -c 'import sys,json;print(len(json.load(sys.stdin).get("deals",[])))' 2>/dev/null) deals)" || fail "get_deals" "$R"
R=$(curl -s "$BASE?action=get_my_deals" -H "X-Session-Token: $ST"); is_ok "$R" && ok "get_my_deals (after create)" || fail "get_my_deals after create" "$R"
R=$(curl -s -X POST "$BASE" -H "X-Session-Token: $ST" -d 'action=end_deal&listing_id=206'); is_ok "$R" && ok "end_deal" || fail "end_deal" "$R"

echo -e "\n🔑 FORGOT PASSWORD"
R=$(curl -s -X POST "$BASE" -d 'action=forgot_password_otp&phone=674218700&role=buyer')
is_ok "$R" && has "$R" '"otp"' && ok "forgot_password_otp (buyer OTP:$(echo $R | python3 -c 'import sys,json;o=json.load(sys.stdin).get("otp","");print(o[:3]+"***")' 2>/dev/null))" || fail "forgot_password_otp buyer" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=forgot_password_otp&phone=2408388119&role=seller')
is_ok "$R" && has "$R" '"otp"' && ok "forgot_password_otp (seller)" || fail "forgot_password_otp seller" "$R"

echo -e "\n🏬 STORE"
R=$(curl -s "$BASE?action=get_listings&store_id=28&limit=3"); is_ok "$R" && ok "get_listings Vitalis store" || fail "get_listings store 28" "$R"
R=$(curl -s "$BASE?action=get_listings&store_id=1&limit=3"); is_ok "$R" && ok "get_listings Paul Electronics" || fail "get_listings store 1" "$R"
R=$(curl -s "$BASE?action=get_store_announcements" -H "X-Session-Token: $BT"); is_ok "$R" && ok "get_store_announcements" || fail "get_store_announcements" "$R"

echo -e "\n👮 ADMIN"
R=$(curl -s -X POST "$BASE" -d 'action=admin_get_meetings&admin_pass=CamAdmin2024!'); is_ok "$R" && ok "admin_get_meetings" || fail "admin_get_meetings" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=admin_toggle_streaming&admin_pass=CamAdmin2024!&enabled=true'); is_ok "$R" && ok "admin_toggle_streaming" || fail "admin_toggle_streaming" "$R"
R=$(curl -s -X POST "$BASE" -d 'action=admin_add_minutes&admin_pass=CamAdmin2024!&seller_id=100&minutes=1&amount_fcfa=0'); is_ok "$R" && ok "admin_add_minutes" || fail "admin_add_minutes" "$R"

echo ""
echo "======================================================"
echo " RESULTS — $(date)"
echo "======================================================"
echo -e " ${green}✅ PASSED : $PASS${nc}"
echo -e " ${red}❌ FAILED : $FAIL${nc}"
echo " 📊 TOTAL  : $((PASS+FAIL)) tests"
[ $FAIL -eq 0 ] && echo -e "\n ${green}🎉 ALL TESTS PASSED! 🇨🇲🚀${nc}" || echo -e "\n ${red}📊 $((PASS*100/(PASS+FAIL)))% passing — $FAIL need attention${nc}"
echo "======================================================"
