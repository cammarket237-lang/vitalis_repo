<?php
// ═══════════════════════════════════════════════════════════
// Unified Admin Console — CamMarket237 🇨🇲 + NairaMarket234 🇳🇬
// Access: /unified_admin.php  Password: CamAdmin2024
// ═══════════════════════════════════════════════════════════
error_reporting(0); ini_set('display_errors', 0);
session_start();

define('ADMIN_PASS', 'CamAdmin2024');
$inDocker = file_exists('/.dockerenv');

define('CM_DSN',  'pgsql:host=' . ($inDocker ? 'db' : 'localhost') . ';dbname=cammarket237_db');
define('CM_USER', 'cammarket_user');
define('CM_PASS', 'CamMarket2024');

// Nigeria DB is a separate compose — host.docker.internal from Cameroon container.
// Host port defaults to 5433 (prod) but can be overridden via NG_DB_PORT env (localhost uses 5434
// because 5433 is taken by school237_db there).
define('NG_DSN',  'pgsql:host=' . ($inDocker ? 'host.docker.internal' : 'localhost') . ';port=' . (getenv('NG_DB_PORT') ?: '5433') . ';dbname=naijamarket_db');
define('NG_USER', 'naijamarket_user');
define('NG_PASS', 'NaijaMarket2024!');

// ── Auth ──────────────────────────────────────────────────
if (isset($_POST['admin_pass'])) {
    if ($_POST['admin_pass'] === ADMIN_PASS) $_SESSION['unified_admin'] = true;
    else $err = 'Wrong password';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: unified_admin.php'); exit; }
if (!isset($_SESSION['unified_admin'])) { showLogin($err ?? ''); exit; }

// ── DB connections ────────────────────────────────────────
function dbCm() {
    static $pdo;
    if ($pdo === false) return null;
    if (!$pdo) {
        try { $pdo = new PDO(CM_DSN, CM_USER, CM_PASS, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch(Exception $e) { $pdo = false; return null; }
    }
    return $pdo;
}
function dbNg() {
    static $pdo;
    if ($pdo === false) return null;
    if (!$pdo) {
        try { $pdo = new PDO(NG_DSN, NG_USER, NG_PASS, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch(Exception $e) { $pdo = false; return null; }
    }
    return $pdo;
}
function xq($db, $sql, $p=[]) {
    if (!$db) return [];
    try { $s=$db->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch(Exception $e) { return []; }
}
function xq1($db, $sql, $p=[]) {
    if (!$db) return null;
    try { $s=$db->prepare($sql); $s->execute($p); return $s->fetch(); } catch(Exception $e) { return null; }
}
function xrun($db, $sql, $p=[]) {
    if (!$db) return 0;
    try { $s=$db->prepare($sql); $s->execute($p); return $s->rowCount(); } catch(Exception $e) { return 0; }
}

// ── Actions ───────────────────────────────────────────────
$msg = '';
$plt = $_GET['platform'] ?? 'both';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act   = $_POST['action'];
    $id    = intval($_POST['id'] ?? 0);
    $pForm = $_POST['platform'] ?? 'cm';
    $sch   = ($pForm === 'ng') ? 'naijamarket' : 'cammarket237';
    $db    = ($pForm === 'ng') ? dbNg() : dbCm();
    $flag  = ($pForm === 'ng') ? '🇳🇬' : '🇨🇲';

    if ($act === 'suspend_user'    && $id) xrun($db, "UPDATE $sch.users SET session_token=NULL, session_expires_at=NOW() WHERE id=?", [$id]);
    if ($act === 'delete_user'     && $id) xrun($db, "DELETE FROM $sch.users WHERE id=? AND role != 'admin'", [$id]);
    if ($act === 'delete_listing'  && $id) xrun($db, "DELETE FROM $sch.listings WHERE id=?", [$id]);
    if ($act === 'approve_listing' && $id) xrun($db, "UPDATE $sch.listings SET moderation_status='approved',status='active' WHERE id=?", [$id]);
    if ($act === 'reject_listing'  && $id) xrun($db, "UPDATE $sch.listings SET moderation_status='rejected',status='inactive' WHERE id=?", [$id]);
    if ($act === 'clear_flag'      && $id) xrun($db, "DELETE FROM $sch.verification_queue WHERE id=?", [$id]);
    if ($act === 'suspend_store'   && $id) xrun($db, "UPDATE $sch.stores SET suspended=true WHERE id=?", [$id]);
    if ($act === 'unsuspend_store' && $id) xrun($db, "UPDATE $sch.stores SET suspended=false WHERE id=?", [$id]);
    if ($act === 'approve_ad'      && $id) xrun($db, "UPDATE $sch.ad_campaigns SET status='approved',reviewed_at=NOW() WHERE id=?", [$id]);
    if ($act === 'activate_ad'     && $id) xrun($db, "UPDATE $sch.ad_campaigns SET status='running' WHERE id=?", [$id]);
    if ($act === 'reject_ad'       && $id) xrun($db, "UPDATE $sch.ad_campaigns SET status='rejected',reviewed_at=NOW() WHERE id=?", [$id]);
    if ($act === 'complete_ad'     && $id) xrun($db, "UPDATE $sch.ad_campaigns SET status='completed' WHERE id=?", [$id]);
    if ($act === 'toggle_streaming' && $pForm === 'cm') {
        $val = ($_POST['value'] ?? '') === 'true' ? 'true' : 'false';
        xrun(dbCm(), "INSERT INTO cammarket237.platform_settings (key,value) VALUES ('live_streaming_enabled',?)
            ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value", [$val]);
        $msg = 'Live streaming ' . ($val === 'true' ? 'ENABLED' : 'DISABLED');
    }
    if ($act === 'add_stream_minutes' && $id && $pForm === 'cm') {
        $mins = floatval($_POST['minutes'] ?? 0);
        $amt  = intval($_POST['amount'] ?? 0);
        if ($mins > 0) {
            $bal    = xq1(dbCm(), "SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$id]);
            $bonus  = (!$bal || !$bal['first_purchase_bonus_given']) ? 20 : 0;
            $total  = $mins + $bonus;
            if ($bal) xrun(dbCm(), "UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+?,first_purchase_bonus_given=true,updated_at=NOW() WHERE seller_id=?", [$total, $id]);
            else      xrun(dbCm(), "INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given) VALUES (?,?,true)", [$id, $total]);
            xrun(dbCm(), "INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'purchase',?,?,?)", [$id, $mins, $amt, 'Admin top-up']);
            if ($bonus > 0) xrun(dbCm(), "INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'bonus',?,0,'First purchase bonus')", [$id, $bonus]);
            $msg = $mins . ' mins added' . ($bonus > 0 ? " + {$bonus} FREE bonus!" : '') . ' to CM seller #' . $id;
        }
    }
    if (!$msg) $msg = "$flag Action '$act' on #$id";
}

// ── Stats ─────────────────────────────────────────────────
$cmOk = dbCm() !== null;
$ngOk = dbNg() !== null;

function statN($db, $sql, $p=[]) { $r = xq1($db, $sql, $p); return $r ? (int)($r['n'] ?? 0) : 0; }

$cmS = [
    'users'    => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.users"),
    'sellers'  => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.users WHERE role='seller'"),
    'buyers'   => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.users WHERE role='buyer'"),
    'listings' => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.listings WHERE status='active'"),
    'stores'   => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.stores"),
    'flags'    => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.verification_queue"),
    'ads'      => statN(dbCm(), "SELECT COUNT(*) n FROM cammarket237.ad_campaigns WHERE status='submitted'"),
];
$ngS = [
    'users'    => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.users"),
    'sellers'  => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.users WHERE role='seller'"),
    'buyers'   => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.users WHERE role='buyer'"),
    'listings' => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.listings WHERE status='active'"),
    'stores'   => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.stores"),
    'flags'    => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.verification_queue"),
    'ads'      => statN(dbNg(), "SELECT COUNT(*) n FROM naijamarket.ad_campaigns WHERE status='submitted'"),
];

$tab = $_GET['tab'] ?? 'dashboard';

function actBtn($action, $id, $label, $platform, $color='#e74c3c') {
    echo "<form method='post' style='display:inline' onsubmit=\"return confirm('Sure?')\">
        <input type='hidden' name='action' value='" . htmlspecialchars($action) . "'/>
        <input type='hidden' name='id' value='$id'/>
        <input type='hidden' name='platform' value='$platform'/>
        <button style='background:$color;color:white;border:none;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer'>$label</button>
    </form> ";
}

function renderAdCard($c, $plt, $curr) {
    $scColor = ['submitted'=>'#fff3cd','approved'=>'#cce5ff','active'=>'#d4edda','rejected'=>'#f8d7da','completed'=>'#e2e3e5'][$c['status']] ?? '#eee';
    $scText  = ['submitted'=>'⏳ Pending Review','approved'=>'✅ Approved','active'=>'🟢 LIVE','rejected'=>'❌ Rejected','completed'=>'✔ Done'][$c['status']] ?? $c['status'];
    $typeIcon= ['video_ad'=>'🎬','sponsored_notification'=>'🔔','event_ad'=>'🎉','boost_listing'=>'⭐'][$c['ad_type']] ?? '📣';
    $typeLabel=['video_ad'=>'Video Ad','sponsored_notification'=>'Sponsored Notification','event_ad'=>'Event Ad','boost_listing'=>'Boost Listing'][$c['ad_type']] ?? $c['ad_type'];
    $isVideo  = $c['ad_type'] === 'video_ad';
    $isEvent  = $c['ad_type'] === 'event_ad';
    $mediaUrl = $c['push_image_url'] ?: $c['push_link_path'] ?: '';
    $price    = $c['price'] ?? $c['pkg_price'] ?? 0;
    $currSym  = $curr === '₦' ? '₦' : '';
    $currSfx  = $curr !== '₦' ? ' '.$curr : '';
    $hasMedia = !empty($mediaUrl);

    // Build preview button label
    if ($isVideo)       $prevLabel = $hasMedia ? '▶ Watch Video' : '⚠️ No video uploaded';
    elseif ($isEvent)   $prevLabel = $hasMedia ? '🖼 View Poster' : '🎉 Event (no poster)';
    elseif ($hasMedia)  $prevLabel = '🖼 View Image';
    else                $prevLabel = '🔔 View Notification';

    $mediaJson  = htmlspecialchars(json_encode($mediaUrl), ENT_QUOTES);
    $titleJson  = htmlspecialchars(json_encode($c['push_title'] ?? ''), ENT_QUOTES);
    $bodyJson   = htmlspecialchars(json_encode($c['push_body'] ?? ''), ENT_QUOTES);
    $ctaJson    = htmlspecialchars(json_encode($c['push_cta_label'] ?? ''), ENT_QUOTES);
    $typeJson   = htmlspecialchars(json_encode($c['ad_type']), ENT_QUOTES);
    $idEsc      = (int)$c['id'];
    $pltEsc     = htmlspecialchars($plt);

    echo "<div class='ad-card'>";
    // ── Header ──
    echo "<div class='ad-card-header'>";
    echo "<span style='font-weight:800;font-size:13px;color:#0f1923'>#{$idEsc}</span>";
    echo "<span style='background:#0f1923;color:white;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px'>{$typeIcon} {$typeLabel}</span>";
    echo "<span style='background:{$scColor};font-size:11px;font-weight:700;padding:3px 9px;border-radius:10px'>{$scText}</span>";
    echo "<span style='margin-left:auto;font-size:11px;color:#888'>" . date('d M Y H:i', strtotime($c['created_at'])) . "</span>";
    echo "</div>";
    // ── Body: info + actions ──
    echo "<div class='ad-card-body'>";
    // Info column
    echo "<div class='ad-card-info'>";
    echo "<div style='display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px'>";
    echo "<div><div style='font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:2px'>Advertiser</div>";
    echo "<div style='font-weight:700;font-size:13px'>" . htmlspecialchars($c['business_name'] ?: $c['full_name'] ?: '—') . "</div>";
    echo "<div style='font-size:12px;color:#555'>" . htmlspecialchars($c['contact_phone'] ?: $c['user_phone'] ?: '') . "</div></div>";
    echo "<div><div style='font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;margin-bottom:2px'>Package</div>";
    echo "<div style='font-size:13px;font-weight:600'>" . htmlspecialchars($c['pkg_name'] ?? '—') . "</div>";
    echo "<div style='font-size:12px;color:#e67e22;font-weight:700'>{$currSym}" . number_format($price) . "{$currSfx}" . ($c['max_reach'] ? ' · ' . number_format($c['max_reach']) . ' reach' : '') . "</div></div>";
    echo "</div>";
    if ($c['push_title'])
        echo "<div style='font-size:13px;font-weight:700;margin-bottom:4px'>" . htmlspecialchars($c['push_title']) . "</div>";
    if ($c['push_body'])
        echo "<div style='font-size:12px;color:#555;line-height:1.5;margin-bottom:8px'>" . nl2br(htmlspecialchars(substr($c['push_body'],0,120))) . (strlen($c['push_body'])>120?'…':'') . "</div>";
    echo "</div>";
    // Actions column
    echo "<div class='ad-card-actions'>";
    // Preview button
    echo "<button class='preview-btn' onclick=\"openAdPreview({$mediaJson},{$typeJson},{$titleJson},{$bodyJson},{$ctaJson},{$idEsc},'{$pltEsc}')\" " . (!$hasMedia && !in_array($c['ad_type'],['sponsored_notification']) ? "style='background:#888'" : '') . ">{$prevLabel}</button>";
    echo "<div style='margin-top:12px;display:flex;flex-direction:column;gap:6px'>";
    if ($c['status']==='submitted') {
        actBtn('approve_ad',$c['id'],'✅ Approve',$plt,'#1a7a3c');
        actBtn('reject_ad',$c['id'],'❌ Reject',$plt,'#e74c3c');
    } elseif ($c['status']==='approved') {
        actBtn('activate_ad',$c['id'],'🟢 Go Live',$plt,'#e67e22');
        actBtn('reject_ad',$c['id'],'❌ Reject',$plt,'#e74c3c');
    } elseif ($c['status']==='active') {
        actBtn('complete_ad',$c['id'],'✔ Mark Done',$plt,'#7f8c8d');
    }
    echo "</div>";
    echo "</div>";
    echo "</div>"; // ad-card-body
    echo "</div>"; // ad-card
}

function pltLink($tab, $platform, $plt) {
    $active = $plt === $platform;
    $colors = ['cm'=>['#2ecc71','#27ae60'], 'both'=>['#3498db','#2980b9'], 'ng'=>['#27ae60','#1e8449']];
    $labels = ['cm'=>'🇨🇲 Cameroon', 'both'=>'🌍 Both', 'ng'=>'🇳🇬 Nigeria'];
    $bg = $active ? $colors[$platform][0] : '#2c3e50';
    $border = $active ? '2px solid ' . $colors[$platform][1] : '2px solid transparent';
    echo "<a href='?tab=$tab&platform=$platform' style='padding:6px 14px;border-radius:20px;text-decoration:none;font-size:12px;font-weight:700;color:white;background:$bg;border:$border'>{$labels[$platform]}</a>";
}

showPage($tab, $cmS, $ngS, $cmOk, $ngOk, $msg, $plt);

// ═══════════════════════════════════════════════════════════
function showPage($tab, $cmS, $ngS, $cmOk, $ngOk, $msg, $plt) { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Unified Admin — CamMarket237 &amp; NairaMarket234</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f0f2f5;color:#1a1a2e}
.header{background:#0f1923;color:white;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;border-bottom:4px solid #fcd116}
.header h1{font-size:18px;font-weight:800}
.nav{background:#1a2535;display:flex;gap:2px;padding:0 20px;overflow-x:auto;align-items:center}
.nav a.tab-link{color:#aaa;padding:12px 16px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;border-bottom:3px solid transparent}
.nav a.tab-link.active{color:white;border-bottom-color:#fcd116}
.nav a.tab-link:hover{color:white}
.plt-bar{display:flex;gap:8px;padding:10px 24px;background:#1e2d3d;align-items:center}
.plt-bar span{font-size:12px;color:#aaa;margin-right:4px}
.content{padding:20px;max-width:1400px;margin:0 auto}
.msg{background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:10px 16px;margin-bottom:16px;color:#155724;font-weight:600}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:18px}
.stat{background:white;border-radius:12px;padding:14px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.stat .n{font-size:28px;font-weight:900}
.stat .l{font-size:11px;color:#888;margin-top:4px}
.stat.cm .n{color:#1a7a3c}
.stat.ng .n{color:#008751}
table{width:100%;border-collapse:collapse;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px}
th{background:#0f1923;color:white;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase}
td{padding:9px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.b-seller{background:#fff3cd;color:#856404}
.b-buyer{background:#d1ecf1;color:#0c5460}
.b-active{background:#d4edda;color:#155724}
.b-pending{background:#fff3cd;color:#856404}
.b-rejected{background:#f8d7da;color:#721c24}
.b-flag{background:#f8d7da;color:#721c24}
.b-cm{background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7}
.b-ng{background:#e8f5e9;color:#1b5e20;border:1px solid #81c784}
.section-title{font-size:16px;font-weight:800;color:#0f1923;margin-bottom:12px;margin-top:4px;display:flex;align-items:center;gap:8px}
.card{background:white;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:14px}
.offline{background:#fde8e8;border:1px solid #f5c6cb;border-radius:10px;padding:20px;text-align:center;color:#721c24;font-size:14px;margin-bottom:14px}
.platform-header{display:flex;align-items:center;gap:8px;padding:8px 0;margin-bottom:8px;border-bottom:2px solid #f0f0f0;font-weight:700;font-size:14px}
/* Ad preview modal */
#adPreviewModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;padding:20px}
#adPreviewModal.open{display:flex}
#adPreviewInner{background:white;border-radius:16px;max-width:700px;width:100%;max-height:90vh;overflow-y:auto;position:relative}
#adPreviewClose{position:absolute;top:12px;right:14px;font-size:22px;cursor:pointer;color:#888;line-height:1;background:none;border:none;z-index:2}
#adPreviewClose:hover{color:#000}
#adPreviewBody{padding:24px}
.preview-btn{background:#0f1923;color:white;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.preview-btn:hover{background:#1a2535}
.ad-card{background:white;border:1px solid #e0e0e0;border-radius:12px;margin-bottom:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.ad-card-header{background:#f8f9fa;border-bottom:1px solid #eee;padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ad-card-body{display:grid;grid-template-columns:1fr auto;gap:0}
.ad-card-info{padding:14px 16px}
.ad-card-actions{padding:14px 16px;border-left:1px solid #eee;display:flex;flex-direction:column;justify-content:space-between;min-width:140px}
</style>
</head>
<body>
<div class="header">
  <h1>&#x1F6D2; Unified Admin &mdash; <span style="color:#fcd116">CamMarket237</span> &amp; <span style="color:#008751">NairaMarket234</span></h1>
  <div style="display:flex;align-items:center;gap:16px">
    <span style="font-size:12px"><?= $cmOk ? '<span style="color:#2ecc71">🟢 CM</span>' : '<span style="color:#e74c3c">🔴 CM</span>' ?></span>
    <span style="font-size:12px"><?= $ngOk ? '<span style="color:#2ecc71">🟢 NG</span>' : '<span style="color:#e74c3c">🔴 NG</span>' ?></span>
    <a href="?logout=1" style="color:#e74c3c;font-size:13px;text-decoration:none">Sign Out</a>
  </div>
</div>

<nav class="nav">
  <?php
  $tabs = [
    'dashboard' => '📊 Dashboard',
    'users'     => '👥 Users',
    'listings'  => '📦 Listings',
    'stores'    => '🏪 Stores',
    'flags'     => '⚠️ Flags',
    'streaming' => '📹 Streaming',
    'ads'       => '📣 Ads',
  ];
  foreach($tabs as $key => $label):
    $flagAlert = '';
    if ($key === 'flags' && ($cmS['flags'] + $ngS['flags']) > 0)
      $flagAlert = " <span style='background:#e74c3c;color:white;border-radius:10px;padding:1px 6px;font-size:10px'>" . ($cmS['flags']+$ngS['flags']) . "</span>";
    if ($key === 'ads' && ($cmS['ads'] + $ngS['ads']) > 0)
      $flagAlert = " <span style='background:#e67e22;color:white;border-radius:10px;padding:1px 6px;font-size:10px'>" . ($cmS['ads']+$ngS['ads']) . "</span>";
  ?>
  <a href="?tab=<?= $key ?>&platform=<?= $plt ?>" class="tab-link <?= $tab===$key?'active':'' ?>"><?= $label . $flagAlert ?></a>
  <?php endforeach ?>
</nav>

<div class="plt-bar">
  <span>Show:</span>
  <?php pltLink($tab, 'cm', $plt); pltLink($tab, 'both', $plt); pltLink($tab, 'ng', $plt); ?>
</div>

<div class="content">
<?php if ($msg): ?><div class="msg">✅ <?= htmlspecialchars($msg) ?></div><?php endif ?>

<?php if ($tab === 'dashboard'): ?>

<?php if ($plt === 'both'): ?>
  <!-- ── Side-by-side layout ── -->
  <!-- Combined totals bar -->
  <div class="stats" style="margin-bottom:14px">
    <div class="stat" style="border-top:3px solid #3498db"><div class="n" style="color:#3498db;font-size:22px"><?= $cmS['users']+$ngS['users'] ?></div><div class="l">Total Users</div></div>
    <div class="stat" style="border-top:3px solid #3498db"><div class="n" style="color:#3498db;font-size:22px"><?= $cmS['sellers']+$ngS['sellers'] ?></div><div class="l">Total Sellers</div></div>
    <div class="stat" style="border-top:3px solid #3498db"><div class="n" style="color:#3498db;font-size:22px"><?= $cmS['buyers']+$ngS['buyers'] ?></div><div class="l">Total Buyers</div></div>
    <div class="stat" style="border-top:3px solid #3498db"><div class="n" style="color:#3498db;font-size:22px"><?= $cmS['listings']+$ngS['listings'] ?></div><div class="l">Active Listings</div></div>
    <div class="stat" style="border-top:3px solid #3498db"><div class="n" style="color:#3498db;font-size:22px"><?= $cmS['stores']+$ngS['stores'] ?></div><div class="l">Stores</div></div>
    <div class="stat" style="border-top:3px solid <?= ($cmS['flags']+$ngS['flags'])>0?'#e74c3c':'#3498db' ?>"><div class="n" style="color:<?= ($cmS['flags']+$ngS['flags'])>0?'#e74c3c':'#3498db' ?>;font-size:22px"><?= $cmS['flags']+$ngS['flags'] ?></div><div class="l">Flags</div></div>
  </div>

  <!-- Two dashboards side by side -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <!-- LEFT: Cameroon -->
    <div>
      <div class="section-title" style="border-left:4px solid #1a7a3c;padding-left:10px">🇨🇲 CamMarket237</div>
      <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">
        <div class="stat cm"><div class="n"><?= $cmS['users'] ?></div><div class="l">Users</div></div>
        <div class="stat cm"><div class="n"><?= $cmS['sellers'] ?></div><div class="l">Sellers</div></div>
        <div class="stat cm"><div class="n"><?= $cmS['buyers'] ?></div><div class="l">Buyers</div></div>
        <div class="stat cm"><div class="n"><?= $cmS['listings'] ?></div><div class="l">Listings</div></div>
        <div class="stat cm"><div class="n"><?= $cmS['stores'] ?></div><div class="l">Stores</div></div>
        <div class="stat cm"><div class="n" style="color:<?= $cmS['flags']>0?'#e74c3c':'#1a7a3c' ?>"><?= $cmS['flags'] ?></div><div class="l">Flags</div></div>
      </div>
      <div class="card" style="margin-bottom:10px">
        <div style="font-weight:700;margin-bottom:8px;font-size:13px">Recent Registrations</div>
        <?php foreach(xq(dbCm(), "SELECT full_name,role,created_at FROM cammarket237.users ORDER BY created_at DESC LIMIT 6") as $u): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:12px">
            <span><?= htmlspecialchars($u['full_name']) ?></span>
            <span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span>
          </div>
        <?php endforeach ?>
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:8px;font-size:13px">Recent Listings</div>
        <?php foreach(xq(dbCm(), "SELECT l.title,l.moderation_status FROM cammarket237.listings l ORDER BY l.created_at DESC LIMIT 6") as $l): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:12px">
            <span><?= htmlspecialchars(substr($l['title'],0,22)) ?></span>
            <span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span>
          </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>

    <!-- RIGHT: Nigeria -->
    <div>
      <div class="section-title" style="border-left:4px solid #008751;padding-left:10px">🇳🇬 NairaMarket234</div>
      <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline<br><small>Start the <code>cammarket-nigeria</code> stack (port 5433)</small></div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">
        <div class="stat ng"><div class="n"><?= $ngS['users'] ?></div><div class="l">Users</div></div>
        <div class="stat ng"><div class="n"><?= $ngS['sellers'] ?></div><div class="l">Sellers</div></div>
        <div class="stat ng"><div class="n"><?= $ngS['buyers'] ?></div><div class="l">Buyers</div></div>
        <div class="stat ng"><div class="n"><?= $ngS['listings'] ?></div><div class="l">Listings</div></div>
        <div class="stat ng"><div class="n"><?= $ngS['stores'] ?></div><div class="l">Stores</div></div>
        <div class="stat ng"><div class="n" style="color:<?= $ngS['flags']>0?'#e74c3c':'#008751' ?>"><?= $ngS['flags'] ?></div><div class="l">Flags</div></div>
      </div>
      <div class="card" style="margin-bottom:10px">
        <div style="font-weight:700;margin-bottom:8px;font-size:13px">Recent Registrations</div>
        <?php foreach(xq(dbNg(), "SELECT full_name,role,created_at FROM naijamarket.users ORDER BY created_at DESC LIMIT 6") as $u): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:12px">
            <span><?= htmlspecialchars($u['full_name']) ?></span>
            <span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span>
          </div>
        <?php endforeach ?>
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:8px;font-size:13px">Recent Listings</div>
        <?php foreach(xq(dbNg(), "SELECT l.title,l.moderation_status FROM naijamarket.listings l ORDER BY l.created_at DESC LIMIT 6") as $l): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:12px">
            <span><?= htmlspecialchars(substr($l['title'],0,22)) ?></span>
            <span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span>
          </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>

  </div><!-- end side-by-side -->

<?php else: ?>
  <!-- ── Single platform layout ── -->
  <?php if ($plt === 'cm'): ?>
  <div class="section-title">🇨🇲 Cameroon — CamMarket237</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php else: ?>
  <div class="stats">
    <div class="stat cm"><div class="n"><?= $cmS['users'] ?></div><div class="l">Users</div></div>
    <div class="stat cm"><div class="n"><?= $cmS['sellers'] ?></div><div class="l">Sellers</div></div>
    <div class="stat cm"><div class="n"><?= $cmS['buyers'] ?></div><div class="l">Buyers</div></div>
    <div class="stat cm"><div class="n"><?= $cmS['listings'] ?></div><div class="l">Active Listings</div></div>
    <div class="stat cm"><div class="n"><?= $cmS['stores'] ?></div><div class="l">Stores</div></div>
    <div class="stat cm"><div class="n" style="color:<?= $cmS['flags']>0?'#e74c3c':'#1a7a3c' ?>"><?= $cmS['flags'] ?></div><div class="l">Flags</div></div>
  </div>
  <div class="two-col">
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Recent Registrations</div>
      <?php foreach(xq(dbCm(), "SELECT full_name,role,created_at FROM cammarket237.users ORDER BY created_at DESC LIMIT 8") as $u): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars($u['full_name']) ?></span>
          <span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Recent Listings</div>
      <?php foreach(xq(dbCm(), "SELECT l.title,l.moderation_status FROM cammarket237.listings l ORDER BY l.created_at DESC LIMIT 8") as $l): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars(substr($l['title'],0,26)) ?></span>
          <span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt === 'ng'): ?>
  <div class="section-title">🇳🇬 Nigeria — NairaMarket234</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline — make sure <code>cammarket-nigeria</code> Docker stack is running on port 5433</div>
  <?php else: ?>
  <div class="stats">
    <div class="stat ng"><div class="n"><?= $ngS['users'] ?></div><div class="l">Users</div></div>
    <div class="stat ng"><div class="n"><?= $ngS['sellers'] ?></div><div class="l">Sellers</div></div>
    <div class="stat ng"><div class="n"><?= $ngS['buyers'] ?></div><div class="l">Buyers</div></div>
    <div class="stat ng"><div class="n"><?= $ngS['listings'] ?></div><div class="l">Active Listings</div></div>
    <div class="stat ng"><div class="n"><?= $ngS['stores'] ?></div><div class="l">Stores</div></div>
    <div class="stat ng"><div class="n" style="color:<?= $ngS['flags']>0?'#e74c3c':'#008751' ?>"><?= $ngS['flags'] ?></div><div class="l">Flags</div></div>
  </div>
  <div class="two-col">
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Recent Registrations</div>
      <?php foreach(xq(dbNg(), "SELECT full_name,role,created_at FROM naijamarket.users ORDER BY created_at DESC LIMIT 8") as $u): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars($u['full_name']) ?></span>
          <span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Recent Listings</div>
      <?php foreach(xq(dbNg(), "SELECT l.title,l.moderation_status FROM naijamarket.listings l ORDER BY l.created_at DESC LIMIT 8") as $l): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars(substr($l['title'],0,26)) ?></span>
          <span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <?php endif ?>
<?php endif ?>

<?php elseif ($tab === 'users'): ?>
  <?php if ($plt !== 'ng'): ?>
  <div class="platform-header">🇨🇲 Cameroon Users (<?= $cmS['users'] ?>)</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Name</th><th>Phone</th><th>Role</th><th>Region</th><th>Store</th><th>Joined</th><th>Actions</th></tr>
    <?php foreach(xq(dbCm(), "SELECT u.*,s.store_name FROM cammarket237.users u LEFT JOIN cammarket237.stores s ON s.user_id=u.id ORDER BY u.created_at DESC") as $u): ?>
    <tr>
      <td><?= $u['id'] ?></td>
      <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
      <td style="font-size:12px"><?= htmlspecialchars($u['phone']) ?></td>
      <td><span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
      <td style="font-size:12px"><?= htmlspecialchars($u['region'] ?? '') ?></td>
      <td style="font-size:12px"><?= $u['store_name'] ? htmlspecialchars($u['store_name']) : '—' ?></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($u['created_at'])) ?></td>
      <td><?php actBtn('suspend_user',$u['id'],'Suspend','cm','#e67e22'); actBtn('delete_user',$u['id'],'Delete','cm','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt !== 'cm'): ?>
  <div class="platform-header" style="margin-top:12px">🇳🇬 Nigeria Users (<?= $ngS['users'] ?>)</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Name</th><th>Phone</th><th>Role</th><th>State</th><th>Store</th><th>Joined</th><th>Actions</th></tr>
    <?php foreach(xq(dbNg(), "SELECT u.*,s.store_name FROM naijamarket.users u LEFT JOIN naijamarket.stores s ON s.user_id=u.id ORDER BY u.created_at DESC") as $u): ?>
    <tr>
      <td><?= $u['id'] ?></td>
      <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
      <td style="font-size:12px"><?= htmlspecialchars($u['phone']) ?></td>
      <td><span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
      <td style="font-size:12px"><?= htmlspecialchars($u['state'] ?? $u['region'] ?? '') ?></td>
      <td style="font-size:12px"><?= $u['store_name'] ? htmlspecialchars($u['store_name']) : '—' ?></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($u['created_at'])) ?></td>
      <td><?php actBtn('suspend_user',$u['id'],'Suspend','ng','#e67e22'); actBtn('delete_user',$u['id'],'Delete','ng','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($tab === 'listings'): ?>
  <?php if ($plt !== 'ng'): ?>
  <div class="platform-header">🇨🇲 Cameroon Listings (<?= $cmS['listings'] ?> active)</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Photo</th><th>Title</th><th>Price</th><th>Store</th><th>Status</th><th>Date</th><th>Actions</th></tr>
    <?php foreach(xq(dbCm(), "SELECT l.*,s.store_name,lm.media_url AS photo FROM cammarket237.listings l LEFT JOIN cammarket237.stores s ON s.id=l.store_id LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image' ORDER BY l.created_at DESC LIMIT 100") as $l): ?>
    <tr>
      <td><?= $l['id'] ?></td>
      <td><?php if($l['photo']): ?><img src="<?= htmlspecialchars($l['photo']) ?>" style="width:38px;height:38px;object-fit:cover;border-radius:5px"/><?php else: ?>—<?php endif ?></td>
      <td><strong><?= htmlspecialchars(substr($l['title'],0,28)) ?></strong><br><span style="color:#888;font-size:11px"><?= htmlspecialchars($l['category'] ?? '') ?></span></td>
      <td style="font-size:12px"><?= number_format($l['price']) ?> FCFA</td>
      <td style="font-size:12px"><?= htmlspecialchars($l['store_name'] ?? '—') ?></td>
      <td><span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
      <td><?php actBtn('approve_listing',$l['id'],'✅','cm','#1a7a3c'); actBtn('reject_listing',$l['id'],'❌','cm','#e67e22'); actBtn('delete_listing',$l['id'],'🗑','cm','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt !== 'cm'): ?>
  <div class="platform-header" style="margin-top:12px">🇳🇬 Nigeria Listings (<?= $ngS['listings'] ?> active)</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Photo</th><th>Title</th><th>Price</th><th>Store</th><th>Status</th><th>Date</th><th>Actions</th></tr>
    <?php foreach(xq(dbNg(), "SELECT l.*,s.store_name,lm.media_url AS photo FROM naijamarket.listings l LEFT JOIN naijamarket.stores s ON s.id=l.store_id LEFT JOIN naijamarket.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image' ORDER BY l.created_at DESC LIMIT 100") as $l): ?>
    <tr>
      <td><?= $l['id'] ?></td>
      <td><?php if($l['photo']): ?><img src="<?= htmlspecialchars($l['photo']) ?>" style="width:38px;height:38px;object-fit:cover;border-radius:5px"/><?php else: ?>—<?php endif ?></td>
      <td><strong><?= htmlspecialchars(substr($l['title'],0,28)) ?></strong><br><span style="color:#888;font-size:11px"><?= htmlspecialchars($l['category'] ?? '') ?></span></td>
      <td style="font-size:12px">₦<?= number_format($l['price']) ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($l['store_name'] ?? '—') ?></td>
      <td><span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
      <td><?php actBtn('approve_listing',$l['id'],'✅','ng','#008751'); actBtn('reject_listing',$l['id'],'❌','ng','#e67e22'); actBtn('delete_listing',$l['id'],'🗑','ng','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($tab === 'stores'): ?>
  <?php if ($plt !== 'ng'): ?>
  <div class="platform-header">🇨🇲 Cameroon Stores (<?= $cmS['stores'] ?>)</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Store</th><th>Owner</th><th>Phone</th><th>Region</th><th>Listings</th><th>Trust</th><th>Status</th><th>Actions</th></tr>
    <?php foreach(xq(dbCm(), "SELECT s.*,u.full_name,u.phone,(SELECT COUNT(*) FROM cammarket237.listings l WHERE l.store_id=s.id AND l.status='active') AS lcount FROM cammarket237.stores s JOIN cammarket237.users u ON u.id=s.user_id ORDER BY s.created_at DESC") as $s): ?>
    <tr>
      <td><?= $s['id'] ?></td>
      <td><strong><?= htmlspecialchars($s['store_name']) ?></strong></td>
      <td><?= htmlspecialchars($s['full_name']) ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($s['phone']) ?></td>
      <td><?= htmlspecialchars($s['region'] ?? '') ?></td>
      <td><?= $s['lcount'] ?></td>
      <td><?= $s['trust_score'] ?? 50 ?>/100</td>
      <td><span class="badge" style="background:<?= $s['suspended']?'#f8d7da':'#d4edda' ?>;color:<?= $s['suspended']?'#721c24':'#155724' ?>"><?= $s['suspended']?'SUSPENDED':'Active' ?></span></td>
      <td><?php if($s['suspended']) actBtn('unsuspend_store',$s['id'],'Unsuspend','cm','#1a7a3c'); else actBtn('suspend_store',$s['id'],'Suspend','cm','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt !== 'cm'): ?>
  <div class="platform-header" style="margin-top:12px">🇳🇬 Nigeria Stores (<?= $ngS['stores'] ?>)</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Store</th><th>Owner</th><th>Phone</th><th>State</th><th>Listings</th><th>Trust</th><th>Status</th><th>Actions</th></tr>
    <?php foreach(xq(dbNg(), "SELECT s.*,u.full_name,u.phone,(SELECT COUNT(*) FROM naijamarket.listings l WHERE l.store_id=s.id AND l.status='active') AS lcount FROM naijamarket.stores s JOIN naijamarket.users u ON u.id=s.user_id ORDER BY s.created_at DESC") as $s): ?>
    <tr>
      <td><?= $s['id'] ?></td>
      <td><strong><?= htmlspecialchars($s['store_name']) ?></strong></td>
      <td><?= htmlspecialchars($s['full_name']) ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($s['phone']) ?></td>
      <td><?= htmlspecialchars($s['state'] ?? $s['region'] ?? '') ?></td>
      <td><?= $s['lcount'] ?></td>
      <td><?= $s['trust_score'] ?? 50 ?>/100</td>
      <td><span class="badge" style="background:<?= $s['suspended']?'#f8d7da':'#d4edda' ?>;color:<?= $s['suspended']?'#721c24':'#155724' ?>"><?= $s['suspended']?'SUSPENDED':'Active' ?></span></td>
      <td><?php if($s['suspended']) actBtn('unsuspend_store',$s['id'],'Unsuspend','ng','#008751'); else actBtn('suspend_store',$s['id'],'Suspend','ng','#e74c3c'); ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($tab === 'flags'): ?>
  <?php if ($plt !== 'ng'): ?>
  <div class="platform-header">🇨🇲 Cameroon Flags (<?= $cmS['flags'] ?>)</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php elseif($cmS['flags'] === 0): ?>
    <div class="card" style="text-align:center;padding:30px;color:#888">✅ No CM flags</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Type</th><th>Content</th><th>Store</th><th>Reason</th><th>Severity</th><th>Flagged</th><th>Actions</th></tr>
    <?php foreach(xq(dbCm(), "SELECT vq.*,l.title AS listing_title,s.store_name FROM cammarket237.verification_queue vq LEFT JOIN cammarket237.listings l ON l.id=vq.content_id LEFT JOIN cammarket237.stores s ON s.id=vq.store_id ORDER BY vq.created_at DESC") as $f): ?>
    <tr>
      <td><?= $f['id'] ?></td>
      <td><span class="badge b-flag"><?= htmlspecialchars($f['content_type'] ?? 'listing') ?></span></td>
      <td><?= htmlspecialchars(substr($f['content_title'] ?? $f['listing_title'] ?? 'Unknown', 0, 28)) ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($f['store_name'] ?? '—') ?></td>
      <td style="font-size:11px;color:#e74c3c"><?= htmlspecialchars(substr($f['flag_reason'] ?? $f['flag_keywords'] ?? '', 0, 36)) ?></td>
      <td><span class="badge" style="background:<?= $f['severity']==='high'?'#f8d7da':($f['severity']==='medium'?'#fff3cd':'#d4edda') ?>;color:#333"><?= strtoupper($f['severity'] ?? 'low') ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y H:i', strtotime($f['created_at'])) ?></td>
      <td>
        <?php if(!empty($f['content_id'])): actBtn('approve_listing',$f['content_id'],'✅','cm','#1a7a3c'); actBtn('delete_listing',$f['content_id'],'🗑','cm','#e74c3c'); endif ?>
        <?php actBtn('clear_flag',$f['id'],'Clear','cm','#7f8c8d') ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt !== 'cm'): ?>
  <div class="platform-header" style="margin-top:12px">🇳🇬 Nigeria Flags (<?= $ngS['flags'] ?>)</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline</div>
  <?php elseif($ngS['flags'] === 0): ?>
    <div class="card" style="text-align:center;padding:30px;color:#888">✅ No NG flags</div>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Type</th><th>Content</th><th>Store</th><th>Reason</th><th>Severity</th><th>Flagged</th><th>Actions</th></tr>
    <?php foreach(xq(dbNg(), "SELECT vq.*,l.title AS listing_title,s.store_name FROM naijamarket.verification_queue vq LEFT JOIN naijamarket.listings l ON l.id=vq.content_id LEFT JOIN naijamarket.stores s ON s.id=vq.store_id ORDER BY vq.created_at DESC") as $f): ?>
    <tr>
      <td><?= $f['id'] ?></td>
      <td><span class="badge b-flag"><?= htmlspecialchars($f['content_type'] ?? 'listing') ?></span></td>
      <td><?= htmlspecialchars(substr($f['content_title'] ?? $f['listing_title'] ?? 'Unknown', 0, 28)) ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($f['store_name'] ?? '—') ?></td>
      <td style="font-size:11px;color:#e74c3c"><?= htmlspecialchars(substr($f['flag_reason'] ?? $f['flag_keywords'] ?? '', 0, 36)) ?></td>
      <td><span class="badge" style="background:<?= $f['severity']==='high'?'#f8d7da':($f['severity']==='medium'?'#fff3cd':'#d4edda') ?>;color:#333"><?= strtoupper($f['severity'] ?? 'low') ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y H:i', strtotime($f['created_at'])) ?></td>
      <td>
        <?php if(!empty($f['content_id'])): actBtn('approve_listing',$f['content_id'],'✅','ng','#008751'); actBtn('delete_listing',$f['content_id'],'🗑','ng','#e74c3c'); endif ?>
        <?php actBtn('clear_flag',$f['id'],'Clear','ng','#7f8c8d') ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($tab === 'streaming'): ?>
  <?php
  $streamEnabled = xq1(dbCm(), "SELECT value FROM cammarket237.platform_settings WHERE key='live_streaming_enabled'");
  $isEnabled = $streamEnabled && $streamEnabled['value'] === 'true';
  $balances   = xq(dbCm(), "SELECT sb.*,u.full_name,u.phone,st.store_name FROM cammarket237.stream_balance sb JOIN cammarket237.users u ON u.id=sb.seller_id LEFT JOIN cammarket237.stores st ON st.user_id=sb.seller_id ORDER BY sb.minutes_available DESC");
  $liveStreams = xq(dbCm(), "SELECT ls.*,s.store_name FROM cammarket237.live_streams ls LEFT JOIN cammarket237.stores s ON s.user_id=ls.seller_id ORDER BY ls.created_at DESC LIMIT 20");
  ?>
  <div class="section-title">🇨🇲 Cameroon — Live Streaming Control</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php else: ?>
  <div class="card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
    <div>
      <div style="font-weight:800;font-size:16px">Live Streaming: <span style="color:<?= $isEnabled?'#1a7a3c':'#e74c3c' ?>"><?= $isEnabled?'ENABLED':'DISABLED' ?></span></div>
      <div style="font-size:13px;color:#888;margin-top:4px">Toggle platform-wide</div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="toggle_streaming"/>
      <input type="hidden" name="platform" value="cm"/>
      <input type="hidden" name="value" value="<?= $isEnabled?'false':'true' ?>"/>
      <button type="submit" style="padding:12px 24px;border:none;border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;background:<?= $isEnabled?'#e74c3c':'#1a7a3c' ?>;color:white"><?= $isEnabled?'🔴 Disable':'🟢 Enable' ?></button>
    </form>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div style="font-weight:700;font-size:14px;margin-bottom:10px">➕ Add Minutes to CM Seller</div>
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
      <input type="hidden" name="action" value="add_stream_minutes"/>
      <input type="hidden" name="platform" value="cm"/>
      <div><label style="font-size:12px;color:#888">Seller ID</label><input type="number" name="id" placeholder="ID" style="width:100%;padding:9px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <div><label style="font-size:12px;color:#888">Minutes</label><input type="number" name="minutes" placeholder="e.g. 60" style="width:100%;padding:9px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <div><label style="font-size:12px;color:#888">Amount (FCFA)</label><input type="number" name="amount" placeholder="e.g. 600" style="width:100%;padding:9px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <button type="submit" style="padding:9px 14px;background:#1a7a3c;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;white-space:nowrap">Add +20 🎁</button>
    </form>
  </div>

  <div class="section-title">Seller Balances</div>
  <table>
    <tr><th>ID</th><th>Seller</th><th>Store</th><th>Phone</th><th>Balance</th><th>Used</th><th>Bonus</th></tr>
    <?php foreach($balances as $b): ?>
    <tr>
      <td><?= $b['seller_id'] ?></td>
      <td><?= htmlspecialchars($b['full_name']) ?></td>
      <td><?= htmlspecialchars($b['store_name']??'—') ?></td>
      <td style="font-size:12px"><?= htmlspecialchars($b['phone']) ?></td>
      <td style="font-weight:700;color:<?= $b['minutes_available']>10?'#1a7a3c':'#e74c3c' ?>"><?= round($b['minutes_available'],1) ?> mins</td>
      <td><?= round($b['minutes_used_total'],1) ?> mins</td>
      <td><?= $b['first_purchase_bonus_given']?'✅':'❌' ?></td>
    </tr>
    <?php endforeach; if(empty($balances)): ?>
    <tr><td colspan="7" style="text-align:center;padding:18px;color:#aaa">No balances yet</td></tr>
    <?php endif ?>
  </table>

  <div class="section-title" style="margin-top:18px">Stream History</div>
  <table>
    <tr><th>#</th><th>Store</th><th>Title</th><th>Status</th><th>Mins Used</th><th>Peak Viewers</th><th>Date</th></tr>
    <?php foreach($liveStreams as $ls): ?>
    <tr>
      <td><?= $ls['id'] ?></td>
      <td><?= htmlspecialchars($ls['store_name']??'—') ?></td>
      <td><?= htmlspecialchars(substr($ls['title'],0,26)) ?></td>
      <td><span class="badge b-<?= $ls['status']==='live'?'active':'rejected' ?>"><?= strtoupper($ls['status']) ?></span></td>
      <td><?= round($ls['minutes_used'],1) ?> mins</td>
      <td><?= $ls['peak_viewers'] ?></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y H:i', strtotime($ls['created_at'])) ?></td>
    </tr>
    <?php endforeach; if(empty($liveStreams)): ?>
    <tr><td colspan="7" style="text-align:center;padding:18px;color:#aaa">No streams yet</td></tr>
    <?php endif ?>
  </table>
  <?php endif ?>
  <div class="card" style="background:#f8f9fa;color:#888;text-align:center;padding:20px;margin-top:4px">🇳🇬 Nigeria streaming management is handled in the NairaMarket admin panel</div>

<?php elseif ($tab === 'ads'): ?>
  <?php
  $adPkgsCm  = xq(dbCm(), "SELECT * FROM cammarket237.ad_packages ORDER BY display_order");
  $adCampsCm = xq(dbCm(), "SELECT c.*,p.name AS pkg_name,p.price AS pkg_price,p.audience_cap AS max_reach,a.business_name,a.contact_phone,u.full_name,u.phone AS user_phone FROM cammarket237.ad_campaigns c LEFT JOIN cammarket237.ad_packages p ON p.id=c.package_id LEFT JOIN cammarket237.advertiser_accounts a ON a.id=c.advertiser_id LEFT JOIN cammarket237.users u ON u.id=a.user_id ORDER BY c.created_at DESC");
  $adPkgsNg  = xq(dbNg(), "SELECT * FROM naijamarket.ad_packages ORDER BY display_order");
  $adCampsNg = xq(dbNg(), "SELECT c.*,p.name AS pkg_name,p.price AS pkg_price,p.audience_cap AS max_reach,a.business_name,a.contact_phone,u.full_name,u.phone AS user_phone FROM naijamarket.ad_campaigns c LEFT JOIN naijamarket.ad_packages p ON p.id=c.package_id LEFT JOIN naijamarket.advertiser_accounts a ON a.id=c.advertiser_id LEFT JOIN naijamarket.users u ON u.id=a.user_id ORDER BY c.created_at DESC");
  ?>

  <?php if ($plt !== 'ng'): ?>
  <div class="platform-header">🇨🇲 Cameroon Ad Packages</div>
  <?php if (!$cmOk): ?><div class="offline">🔴 Cameroon DB offline</div>
  <?php elseif($adPkgsCm): ?>
  <table style="margin-bottom:20px">
    <tr><th>Package</th><th>Reach</th><th>Duration</th><th>Price (FCFA)</th><th>Status</th></tr>
    <?php foreach($adPkgsCm as $p): ?>
    <tr>
      <td><strong><?= htmlspecialchars($p['name']) ?></strong><br><span style="font-size:11px;color:#888"><?= htmlspecialchars($p['description']??'') ?></span></td>
      <td><?= number_format($p['audience_cap']??$p['notification_count']??0) ?> buyers</td>
      <td><?= $p['duration_days'] ?> days</td>
      <td><?= number_format($p['price']??0) ?> FCFA</td>
      <td><span style="background:<?= $p['active']?'#d4edda':'#f8d7da' ?>;color:<?= $p['active']?'#155724':'#721c24' ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700"><?= $p['active']?'Active':'Inactive' ?></span></td>
    </tr>
    <?php endforeach ?>
  </table>
  <div class="section-title">🇨🇲 Cameroon Campaigns (<?= count($adCampsCm) ?>)</div>
  <?php if(empty($adCampsCm)): ?>
    <div class="card" style="text-align:center;padding:24px;color:#aaa">No CM campaigns yet</div>
  <?php else: foreach($adCampsCm as $c): renderAdCard($c,'cm','FCFA'); endforeach; endif ?>
  <?php endif ?>
  <?php endif ?>

  <?php if ($plt !== 'cm'): ?>
  <div class="platform-header" style="margin-top:14px">🇳🇬 Nigeria Ad Packages</div>
  <?php if (!$ngOk): ?><div class="offline">🔴 Nigeria DB offline</div>
  <?php elseif($adPkgsNg): ?>
  <table style="margin-bottom:20px">
    <tr><th>Package</th><th>Reach</th><th>Duration</th><th>Price (₦)</th><th>Status</th></tr>
    <?php foreach($adPkgsNg as $p): ?>
    <tr>
      <td><strong><?= htmlspecialchars($p['name']) ?></strong><br><span style="font-size:11px;color:#888"><?= htmlspecialchars($p['description']??'') ?></span></td>
      <td><?= number_format($p['audience_cap']??$p['notification_count']??0) ?> buyers</td>
      <td><?= $p['duration_days'] ?> days</td>
      <td>₦<?= number_format($p['price']??0) ?></td>
      <td><span style="background:<?= $p['active']?'#d4edda':'#f8d7da' ?>;color:<?= $p['active']?'#155724':'#721c24' ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700"><?= $p['active']?'Active':'Inactive' ?></span></td>
    </tr>
    <?php endforeach ?>
  </table>
  <div class="section-title">🇳🇬 Nigeria Campaigns (<?= count($adCampsNg) ?>)</div>
  <?php if(empty($adCampsNg)): ?>
    <div class="card" style="text-align:center;padding:24px;color:#aaa">No NG campaigns yet</div>
  <?php else: foreach($adCampsNg as $c): renderAdCard($c,'ng','₦'); endforeach; endif ?>
  <?php else: ?><div class="card" style="text-align:center;padding:24px;color:#aaa">No NG ad packages configured</div>
  <?php endif ?>
  <?php endif ?>

<?php endif ?>
</div>

<!-- ── Ad Preview Modal ── -->
<div id="adPreviewModal" onclick="if(event.target===this)closeAdPreview()">
  <div id="adPreviewInner">
    <button id="adPreviewClose" onclick="closeAdPreview()">&#x2715;</button>
    <div id="adPreviewBody"></div>
  </div>
</div>

<script>
function openAdPreview(mediaUrl, adType, title, body, cta, campaignId, platform) {
  var modal = document.getElementById('adPreviewModal');
  var container = document.getElementById('adPreviewBody');
  var isVideo = adType === 'video_ad';
  var isEvent = adType === 'event_ad';
  var html = '';

  // Type badge + title
  var typeLabel = {video_ad:'🎬 Video Ad', sponsored_notification:'🔔 Sponsored Notification', event_ad:'🎉 Event Ad', boost_listing:'⭐ Boost Listing'}[adType] || adType;
  html += '<div style="padding:18px 20px 0">';
  html += '<div style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;margin-bottom:6px">' + typeLabel + ' — Campaign #' + campaignId + '</div>';
  if (title) html += '<div style="font-size:18px;font-weight:800;color:#0f1923;margin-bottom:6px">' + escHtml(title) + '</div>';
  if (body)  html += '<div style="font-size:13px;color:#555;line-height:1.6;margin-bottom:12px">' + escHtml(body) + '</div>';
  html += '</div>';

  // Media
  if (isVideo && mediaUrl) {
    html += '<div style="background:#000;padding:0">';
    html += '<video src="' + escHtml(mediaUrl) + '" controls autoplay style="width:100%;max-height:400px;display:block;outline:none"></video>';
    html += '</div>';
  } else if ((isEvent || !isVideo) && mediaUrl && !isVideo) {
    html += '<div style="text-align:center;background:#f5f5f5;padding:12px">';
    html += '<img src="' + escHtml(mediaUrl) + '" style="max-width:100%;max-height:380px;border-radius:8px;object-fit:contain">';
    html += '</div>';
  } else if (adType === 'sponsored_notification') {
    // Notification preview mockup
    html += '<div style="padding:0 20px 4px">';
    html += '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:12px;padding:16px">';
    html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><span style="font-size:22px">🔔</span><span style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase">Push Notification</span></div>';
    if (title) html += '<div style="font-size:15px;font-weight:800;color:#0f1923;margin-bottom:5px">' + escHtml(title) + '</div>';
    if (body)  html += '<div style="font-size:13px;color:#555;line-height:1.5">' + escHtml(body) + '</div>';
    if (cta)   html += '<div style="margin-top:10px;display:inline-block;background:#e67e22;color:white;border-radius:8px;padding:6px 16px;font-size:12px;font-weight:700">' + escHtml(cta) + '</div>';
    html += '</div></div>';
  } else if (isVideo && !mediaUrl) {
    html += '<div style="padding:20px;text-align:center;color:#e74c3c;font-size:14px">⚠️ No video file was uploaded with this campaign.</div>';
  }

  if (cta && !isVideo)
    html += '<div style="padding:10px 20px 0"><span style="font-size:12px;color:#2980b9;font-weight:600">CTA button: ' + escHtml(cta) + '</span></div>';

  html += '<div style="padding:16px 20px 20px;border-top:1px solid #eee;margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">';
  html += '<form method="post" style="display:inline" onsubmit="return confirm(\'Approve this ad?\')"><input type="hidden" name="action" value="approve_ad"><input type="hidden" name="id" value="'+campaignId+'"><input type="hidden" name="platform" value="'+platform+'"><button style="background:#1a7a3c;color:white;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer">✅ Approve</button></form>';
  html += '<form method="post" style="display:inline" onsubmit="return confirm(\'Reject this ad?\')"><input type="hidden" name="action" value="reject_ad"><input type="hidden" name="id" value="'+campaignId+'"><input type="hidden" name="platform" value="'+platform+'"><button style="background:#e74c3c;color:white;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer">❌ Reject</button></form>';
  html += '<button onclick="closeAdPreview()" style="background:#f0f0f0;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer">Close</button>';
  html += '</div>';

  container.innerHTML = html;
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeAdPreview() {
  var modal = document.getElementById('adPreviewModal');
  modal.classList.remove('open');
  document.body.style.overflow = '';
  // Stop video playback
  var v = modal.querySelector('video');
  if (v) { v.pause(); v.src = ''; }
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
<?php }

function showLogin($err='') { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Unified Admin Login</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f1923;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:white;border-radius:16px;padding:40px;width:100%;max-width:360px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
h2{font-size:22px;font-weight:900;color:#0f1923;margin-bottom:6px}
.sub{font-size:13px;color:#888;margin-bottom:24px}
input{width:100%;padding:12px;border:1.5px solid #ddd;border-radius:10px;font-size:14px;margin-bottom:14px;box-sizing:border-box}
button{width:100%;padding:13px;background:#1a7a3c;color:white;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer}
.err{background:#fde8e8;border:1px solid #f5c0c0;border-radius:8px;padding:10px;margin-bottom:14px;color:#ce1126;font-size:13px}
</style>
</head>
<body>
<div class="box">
  <div style="text-align:center;font-size:32px;margin-bottom:14px">🇨🇲&nbsp;🌍&nbsp;🇳🇬</div>
  <h2>Unified Admin</h2>
  <div class="sub">CamMarket237 &amp; NairaMarket234</div>
  <?php if($err): ?><div class="err">❌ <?= htmlspecialchars($err) ?></div><?php endif ?>
  <form method="post">
    <input type="password" name="admin_pass" placeholder="Admin password" autofocus/>
    <button type="submit">🔐 Login</button>
  </form>
</div>
</body>
</html>
<?php }
