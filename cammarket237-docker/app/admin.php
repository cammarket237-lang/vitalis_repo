<?php
// ═══════════════════════════════════════════════════════════
// CamMarket237 — Admin Panel
// Access: /admin.php  Password: set ADMIN_PASS below
// ═══════════════════════════════════════════════════════════
error_reporting(0); ini_set('display_errors', 0);
session_start();

define('ADMIN_PASS', 'CamAdmin2024');
define('DB_DSN',  'pgsql:host=' . (file_exists('/.dockerenv') ? 'db' : 'localhost') . ';dbname=cammarket237_db');
define('DB_USER', 'cammarket_user');
define('DB_PASS', 'CamMarket2024');

// ── Auth ──────────────────────────────────────────────────
if (isset($_POST['admin_pass'])) {
    if ($_POST['admin_pass'] === ADMIN_PASS) {
        $_SESSION['admin'] = true;
    } else {
        $err = 'Wrong password';
    }
}
if (isset($_GET['logout'])) {
    session_destroy(); header('Location: admin.php'); exit;
}
if (!isset($_SESSION['admin'])) { showLogin($err ?? ''); exit; }

// ── DB ────────────────────────────────────────────────────
function db() {
    static $pdo;
    if (!$pdo) $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}
function q($sql, $p=[]) {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function q1($sql, $p=[]) {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetch();
}
function run($sql, $p=[]) {
    $s = db()->prepare($sql); $s->execute($p); return $s->rowCount();
}

// ── Actions ───────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    $id  = intval($_POST['id'] ?? 0);

    if ($act === 'suspend_user' && $id) {
        run("UPDATE cammarket237.users SET session_token=NULL, session_expires_at=NOW() WHERE id=?", [$id]);
        $msg = "User #$id suspended (session cleared)";
    }
    if ($act === 'delete_listing' && $id) {
        run("DELETE FROM cammarket237.listings WHERE id=?", [$id]);
        $msg = "Listing #$id deleted";
    }
    if ($act === 'approve_listing' && $id) {
        run("UPDATE cammarket237.listings SET moderation_status='approved', status='active' WHERE id=?", [$id]);
        $msg = "Listing #$id approved";
    }
    if ($act === 'reject_listing' && $id) {
        run("UPDATE cammarket237.listings SET moderation_status='rejected', status='inactive' WHERE id=?", [$id]);
        $msg = "Listing #$id rejected";
    }
    if ($act === 'clear_flag' && $id) {
        run("DELETE FROM cammarket237.verification_queue WHERE id=?", [$id]);
        $msg = "Flag #$id cleared";
    }
    if ($act === 'suspend_store' && $id) {
        run("UPDATE cammarket237.stores SET suspended=true WHERE id=?", [$id]);
        $msg = "Store #$id suspended";
    }
    if ($act === 'unsuspend_store' && $id) {
        run("UPDATE cammarket237.stores SET suspended=false WHERE id=?", [$id]);
        $msg = "Store #$id unsuspended";
    }
    if ($act === 'toggle_streaming') {
    $val = $_POST['value'] === 'true' ? 'true' : 'false';
    run("INSERT INTO cammarket237.platform_settings (key,value) VALUES ('live_streaming_enabled',?)
        ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value", [$val]);
    $msg = 'Live streaming ' . ($val === 'true' ? 'ENABLED' : 'DISABLED');
}
if ($act === 'add_stream_minutes' && $id) {
    $mins = floatval($_POST['minutes'] ?? 0);
    $amt  = intval($_POST['amount'] ?? 0);
    if ($mins > 0) {
        $bal = q1("SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$id]);
        $isFirst = !$bal || !$bal['first_purchase_bonus_given'];
        $bonus = $isFirst ? 20 : 0;
        $total = $mins + $bonus;
        if ($bal) {
            run("UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+?,
                first_purchase_bonus_given=true,updated_at=NOW() WHERE seller_id=?", [$total, $id]);
        } else {
            run("INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given)
                VALUES (?,?,true)", [$id, $total]);
        }
        run("INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note)
            VALUES (?,'purchase',?,?,?)", [$id, $mins, $amt, 'Admin top-up']);
        if ($bonus > 0)
            run("INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note)
                VALUES (?,'bonus',?,0,'First purchase bonus')", [$id, $bonus]);
        $msg = $mins . ' mins added' . ($bonus > 0 ? " + {$bonus} FREE bonus!" : '') . ' to seller #' . $id;
    }
}
if ($act === 'delete_user' && $id) {
        run("DELETE FROM cammarket237.users WHERE id=? AND role != 'admin'", [$id]);
        $msg = "User #$id deleted";
    }
if ($act === 'approve_ad' && $id) {
    run("UPDATE cammarket237.ad_campaigns SET status='approved', reviewed_at=NOW() WHERE id=?", [$id]);
    $msg = "Ad #$id approved — notify advertiser to pay.";
}
if ($act === 'activate_ad' && $id) {
    run("UPDATE cammarket237.ad_campaigns SET status='running' WHERE id=?", [$id]);
    $msg = "Ad #$id is now LIVE.";
}
if ($act === 'reject_ad' && $id) {
    run("UPDATE cammarket237.ad_campaigns SET status='rejected', reviewed_at=NOW() WHERE id=?", [$id]);
    $msg = "Ad #$id rejected.";
}
if ($act === 'complete_ad' && $id) {
    run("UPDATE cammarket237.ad_campaigns SET status='completed' WHERE id=?", [$id]);
    $msg = "Ad #$id marked complete.";
}
}

// ── Stats ─────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'dashboard';
$stats = [
    'users'    => q1("SELECT COUNT(*) AS n FROM cammarket237.users")['n'],
    'sellers'  => q1("SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='seller'")['n'],
    'buyers'   => q1("SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='buyer'")['n'],
    'listings' => q1("SELECT COUNT(*) AS n FROM cammarket237.listings WHERE status='active'")['n'],
    'stores'   => q1("SELECT COUNT(*) AS n FROM cammarket237.stores")['n'],
    'flags'    => q1("SELECT COUNT(*) AS n FROM cammarket237.verification_queue")['n'],
    'uploads'  => q1("SELECT COUNT(*) AS n FROM cammarket237.listing_media")['n'],
];

function act($action, $id, $label, $color='#e74c3c') {
    echo "<form method='post' style='display:inline' onsubmit=\"return confirm('Sure?')\">
        <input type='hidden' name='action' value='$action'/>
        <input type='hidden' name='id' value='$id'/>
        <button style='background:$color;color:white;border:none;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer'>$label</button>
    </form> ";
}

showPage($tab, $stats, $msg);

// ── Views ─────────────────────────────────────────────────
function showPage($tab, $stats, $msg) { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>CamMarket237 Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f0f2f5;color:#1a1a2e}
.header{background:#0f1923;color:white;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;border-bottom:4px solid #fcd116}
.header h1{font-size:18px;font-weight:800}
.header h1 span{color:#1a7a3c}
.nav{background:#1a2535;display:flex;gap:2px;padding:0 20px;overflow-x:auto}
.nav a{color:#aaa;padding:12px 18px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;border-bottom:3px solid transparent}
.nav a.active{color:white;border-bottom-color:#fcd116}
.nav a:hover{color:white}
.content{padding:20px;max-width:1200px;margin:0 auto}
.msg{background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:10px 16px;margin-bottom:16px;color:#155724;font-weight:600}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat{background:white;border-radius:12px;padding:16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.stat .n{font-size:32px;font-weight:900;color:#1a7a3c}
.stat .l{font-size:12px;color:#888;margin-top:4px}
table{width:100%;border-collapse:collapse;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)}
th{background:#0f1923;color:white;padding:10px 12px;text-align:left;font-size:12px;font-weight:700;text-transform:uppercase}
td{padding:10px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.b-seller{background:#fff3cd;color:#856404}
.b-buyer{background:#d1ecf1;color:#0c5460}
.b-active{background:#d4edda;color:#155724}
.b-pending{background:#fff3cd;color:#856404}
.b-rejected{background:#f8d7da;color:#721c24}
.b-flag{background:#f8d7da;color:#721c24}
.section-title{font-size:18px;font-weight:800;color:#0f1923;margin-bottom:14px}
.search-box{padding:8px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;width:250px;margin-bottom:14px}
.card{background:white;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px}
</style>
</head>
<body>
<div class="header">
  <h1>&#x1F6D2; CamMarket<span>237</span> Admin</h1>
  <div style="display:flex;align-items:center;gap:16px">
    <span style="font-size:12px;color:#aaa">&#x1F7E2; Live</span>
    <a href="?logout=1" style="color:#e74c3c;font-size:13px;text-decoration:none">Sign Out</a>
  </div>
</div>

<nav class="nav">
  <a href="?tab=dashboard"  class="<?= $tab==='dashboard' ?'active':'' ?>">&#x1F4CA; Dashboard</a>
  <a href="?tab=users"      class="<?= $tab==='users'     ?'active':'' ?>">&#x1F465; Users</a>
  <a href="?tab=listings"   class="<?= $tab==='listings'  ?'active':'' ?>">&#x1F4E6; Listings</a>
  <a href="?tab=stores"     class="<?= $tab==='stores'    ?'active':'' ?>">&#x1F3EA; Stores</a>
  <a href="?tab=flags"      class="<?= $tab==='flags'     ?'active':'' ?>">&#x26A0;&#xFE0F; Flags <?= $stats['flags']>0 ? "<span style='background:#e74c3c;color:white;border-radius:10px;padding:1px 7px;font-size:11px'>{$stats['flags']}</span>" : '' ?></a>
  <a href="?tab=uploads"    class="<?= $tab==='uploads'   ?'active':'' ?>">&#x1F4F7; Uploads</a>
  <a href="?tab=streaming"  class="<?= $tab==='streaming' ?'active':'' ?>">&#x1F4F9; Live Streams</a>
  <a href="?tab=ads"        class="<?= $tab==='ads'       ?'active':'' ?>">&#x1F4E3; Ads <?= ($adPending=q1("SELECT COUNT(*) AS n FROM cammarket237.ad_campaigns WHERE status='submitted'")['n'])>0 ? "<span style='background:#e67e22;color:white;border-radius:10px;padding:1px 7px;font-size:11px'>$adPending</span>" : '' ?></a>
  <?php
  $openTickets = 0;
  try { $openTickets = q1("SELECT COUNT(*) AS n FROM cammarket237.support_tickets WHERE status='open'")['n'] ?? 0; } catch(Exception $_e){}
  ?>
  <a href="?tab=tickets" class="<?= $tab==='tickets' ?'active':'' ?>">&#x1F3AB; Help <?= $openTickets>0 ? "<span style='background:#e74c3c;color:white;border-radius:10px;padding:1px 7px;font-size:11px'>$openTickets</span>" : '' ?></a>
</nav>

<div class="content">
<?php if ($msg): ?><div class="msg">&#x2705; <?= htmlspecialchars($msg) ?></div><?php endif ?>

<?php if ($tab === 'dashboard'): ?>
  <div class="section-title">Dashboard</div>
  <div class="stats">
    <div class="stat"><div class="n"><?= $stats['users'] ?></div><div class="l">Total Users</div></div>
    <div class="stat"><div class="n"><?= $stats['sellers'] ?></div><div class="l">Sellers</div></div>
    <div class="stat"><div class="n"><?= $stats['buyers'] ?></div><div class="l">Buyers</div></div>
    <div class="stat"><div class="n"><?= $stats['listings'] ?></div><div class="l">Active Listings</div></div>
    <div class="stat"><div class="n"><?= $stats['stores'] ?></div><div class="l">Stores</div></div>
    <div class="stat"><div class="n" style="color:<?= $stats['flags']>0?'#e74c3c':'#1a7a3c' ?>"><?= $stats['flags'] ?></div><div class="l">Flagged Items</div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card">
      <div style="font-weight:700;margin-bottom:12px">&#x1F553; Recent Registrations</div>
      <?php $recent = q("SELECT full_name,phone,role,created_at FROM cammarket237.users ORDER BY created_at DESC LIMIT 8"); ?>
      <?php foreach($recent as $u): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars($u['full_name']) ?></span>
          <span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:12px">&#x1F4E6; Recent Listings</div>
      <?php $rl = q("SELECT l.title, l.price, l.moderation_status, s.store_name FROM cammarket237.listings l LEFT JOIN cammarket237.stores s ON s.id=l.store_id ORDER BY l.created_at DESC LIMIT 8"); ?>
      <?php foreach($rl as $l): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
          <span><?= htmlspecialchars(substr($l['title'],0,25)) ?></span>
          <span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span>
        </div>
      <?php endforeach ?>
    </div>
  </div>

<?php elseif ($tab === 'users'): ?>
  <div class="section-title">Users (<?= $stats['users'] ?> total)</div>
  <?php $users = q("SELECT u.*, s.store_name FROM cammarket237.users u LEFT JOIN cammarket237.stores s ON s.user_id=u.id ORDER BY u.created_at DESC"); ?>
  <table>
    <tr><th>#</th><th>Name</th><th>Phone</th><th>Role</th><th>Region</th><th>Store</th><th>Joined</th><th>Actions</th></tr>
    <?php foreach($users as $u): ?>
    <tr>
      <td><?= $u['id'] ?></td>
      <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
      <td><?= $u['phone'] ?></td>
      <td><span class="badge b-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
      <td><?= $u['region'] ?>, <?= $u['town'] ?? $u['area_quarter'] ?></td>
      <td><?= $u['store_name'] ? htmlspecialchars($u['store_name']) : '-' ?></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($u['created_at'])) ?></td>
      <td>
        <?php act('suspend_user', $u['id'], 'Suspend', '#e67e22') ?>
        <?php act('delete_user', $u['id'], 'Delete', '#e74c3c') ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>

<?php elseif ($tab === 'listings'): ?>
  <div class="section-title">Listings (<?= $stats['listings'] ?> active)</div>
  <?php $listings = q("SELECT l.*, s.store_name, lm.media_url AS photo
    FROM cammarket237.listings l
    LEFT JOIN cammarket237.stores s ON s.id=l.store_id
    LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
    ORDER BY l.created_at DESC LIMIT 100"); ?>
  <table>
    <tr><th>#</th><th>Photo</th><th>Title</th><th>Price</th><th>Store</th><th>Status</th><th>Date</th><th>Actions</th></tr>
    <?php foreach($listings as $l): ?>
    <tr>
      <td><?= $l['id'] ?></td>
      <td><?php if($l['photo']): ?><img src="<?= htmlspecialchars($l['photo']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:6px"/><?php else: ?>-<?php endif ?></td>
      <td><strong><?= htmlspecialchars(substr($l['title'],0,30)) ?></strong><br><span style="color:#888;font-size:11px"><?= $l['category'] ?></span></td>
      <td><?= number_format($l['price']) ?> FCFA</td>
      <td><?= htmlspecialchars($l['store_name'] ?? '-') ?></td>
      <td><span class="badge b-<?= $l['moderation_status'] ?>"><?= $l['moderation_status'] ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
      <td>
        <?php act('approve_listing', $l['id'], 'Approve', '#1a7a3c') ?>
        <?php act('reject_listing',  $l['id'], 'Reject',  '#e67e22') ?>
        <?php act('delete_listing',  $l['id'], 'Delete',  '#e74c3c') ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>

<?php elseif ($tab === 'stores'): ?>
  <div class="section-title">Stores (<?= $stats['stores'] ?> total)</div>
  <?php $stores = q("SELECT s.*, u.full_name, u.phone,
    (SELECT COUNT(*) FROM cammarket237.listings l WHERE l.store_id=s.id AND l.status='active') AS listing_count
    FROM cammarket237.stores s JOIN cammarket237.users u ON u.id=s.user_id
    ORDER BY s.created_at DESC"); ?>
  <table>
    <tr><th>#</th><th>Store</th><th>Owner</th><th>Phone</th><th>Region</th><th>Listings</th><th>Trust</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($stores as $s): ?>
    <tr>
      <td><?= $s['id'] ?></td>
      <td><strong><?= htmlspecialchars($s['store_name']) ?></strong></td>
      <td><?= htmlspecialchars($s['full_name']) ?></td>
      <td><?= $s['phone'] ?></td>
      <td><?= $s['region'] ?></td>
      <td><?= $s['listing_count'] ?></td>
      <td><?= $s['trust_score'] ?? 50 ?>/100</td>
      <td><span class="badge" style="background:<?= $s['suspended']?'#f8d7da':'#d4edda' ?>;color:<?= $s['suspended']?'#721c24':'#155724' ?>"><?= $s['suspended']?'SUSPENDED':'Active' ?></span></td>
      <td>
        <?php if($s['suspended']): ?>
          <?php act('unsuspend_store', $s['id'], 'Unsuspend', '#1a7a3c') ?>
        <?php else: ?>
          <?php act('suspend_store', $s['id'], 'Suspend', '#e74c3c') ?>
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>

<?php elseif ($tab === 'flags'): ?>
  <div class="section-title">Flagged Content (<?= $stats['flags'] ?> items)</div>
  <?php if($stats['flags'] === 0): ?>
    <div class="card" style="text-align:center;padding:40px;color:#888">
      <div style="font-size:48px;margin-bottom:12px">&#x2705;</div>
      <div style="font-size:16px;font-weight:700">No flagged content!</div>
      <div style="font-size:13px;margin-top:6px">All content is clean.</div>
    </div>
  <?php else: ?>
  <?php $flags = q("SELECT vq.*, l.title AS listing_title, s.store_name
    FROM cammarket237.verification_queue vq
    LEFT JOIN cammarket237.listings l ON l.id=vq.content_id
    LEFT JOIN cammarket237.stores s ON s.id=vq.store_id
    ORDER BY vq.created_at DESC"); ?>
  <table>
    <tr><th>#</th><th>Type</th><th>Content</th><th>Store</th><th>Reason</th><th>Severity</th><th>Flagged</th><th>Actions</th></tr>
    <?php foreach($flags as $f): ?>
    <tr>
      <td><?= $f['id'] ?></td>
      <td><span class="badge b-flag"><?= $f['content_type'] ?? 'listing' ?></span></td>
      <td><?= htmlspecialchars(substr($f['content_title'] ?? $f['listing_title'] ?? 'Unknown', 0, 30)) ?></td>
      <td><?= htmlspecialchars($f['store_name'] ?? '-') ?></td>
      <td style="font-size:12px;color:#e74c3c"><?= htmlspecialchars(substr($f['flag_reason'] ?? $f['flag_keywords'] ?? '', 0, 40)) ?></td>
      <td><span class="badge" style="background:<?= $f['severity']==='high'?'#f8d7da':($f['severity']==='medium'?'#fff3cd':'#d4edda') ?>;color:#333"><?= strtoupper($f['severity'] ?? 'low') ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y H:i', strtotime($f['created_at'])) ?></td>
      <td>
        <?php if(!empty($f['content_id'])): ?>
          <?php act('approve_listing', $f['content_id'], 'Approve', '#1a7a3c') ?>
          <?php act('delete_listing',  $f['content_id'], 'Delete',  '#e74c3c') ?>
        <?php endif ?>
        <?php act('clear_flag', $f['id'], 'Clear Flag', '#888') ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>

<?php elseif ($tab === 'streaming'): ?>
  <?php
    $streamEnabled = q1("SELECT value FROM cammarket237.platform_settings WHERE key='live_streaming_enabled'");
    $isEnabled = $streamEnabled && $streamEnabled['value'] === 'true';
    $balances = q("SELECT sb.*, u.full_name, u.phone, st.store_name
        FROM cammarket237.stream_balance sb
        JOIN cammarket237.users u ON u.id=sb.seller_id
        LEFT JOIN cammarket237.stores st ON st.user_id=sb.seller_id
        ORDER BY sb.minutes_available DESC");
    $liveStreams = q("SELECT ls.*, s.store_name FROM cammarket237.live_streams ls
        LEFT JOIN cammarket237.stores s ON s.user_id=ls.seller_id
        ORDER BY ls.created_at DESC LIMIT 20");
  ?>
  <div class="section-title">Live Streaming Control</div>

  <!-- TOGGLE BUTTON -->
  <div class="card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="font-weight:800;font-size:16px">Live Streaming is: <span style="color:<?= $isEnabled?'#1a7a3c':'#e74c3c' ?>"><?= $isEnabled?'ENABLED':'DISABLED' ?></span></div>
      <div style="font-size:13px;color:#888;margin-top:4px">Toggle to enable/disable platform-wide</div>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="toggle_streaming"/>
      <input type="hidden" name="value" value="<?= $isEnabled?'false':'true' ?>"/>
      <button type="submit" style="padding:14px 28px;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;background:<?= $isEnabled?'#e74c3c':'#1a7a3c' ?>;color:white">
        <?= $isEnabled ? '🔴 Disable' : '🟢 Enable' ?>
      </button>
    </form>
  </div>

  <!-- ADD MINUTES -->
  <div class="card" style="margin-bottom:16px">
    <div style="font-weight:700;font-size:15px;margin-bottom:12px">➕ Add Minutes to Seller</div>
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
      <input type="hidden" name="action" value="add_stream_minutes"/>
      <div><label style="font-size:12px;color:#888">Seller ID</label><input type="number" name="id" placeholder="ID" style="width:100%;padding:10px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <div><label style="font-size:12px;color:#888">Minutes</label><input type="number" name="minutes" placeholder="e.g. 60" style="width:100%;padding:10px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <div><label style="font-size:12px;color:#888">Amount (FCFA)</label><input type="number" name="amount" placeholder="e.g. 600" style="width:100%;padding:10px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;margin-top:4px;box-sizing:border-box"/></div>
      <button type="submit" style="padding:10px 16px;background:#1a7a3c;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;white-space:nowrap">Add &#127873; +20 free</button>
    </form>
  </div>

  <!-- BALANCES -->
  <div class="section-title">Seller Balances</div>
  <table>
    <tr><th>ID</th><th>Seller</th><th>Store</th><th>Phone</th><th>Balance</th><th>Used</th><th>Bonus Given</th></tr>
    <?php foreach($balances as $b): ?>
    <tr>
      <td><?= $b['seller_id'] ?></td>
      <td><?= htmlspecialchars($b['full_name']) ?></td>
      <td><?= htmlspecialchars($b['store_name']??'-') ?></td>
      <td><?= $b['phone'] ?></td>
      <td style="font-weight:700;color:<?= $b['minutes_available']>10?'#1a7a3c':'#e74c3c' ?>"><?= round($b['minutes_available'],1) ?> mins</td>
      <td><?= round($b['minutes_used_total'],1) ?> mins</td>
      <td><?= $b['first_purchase_bonus_given']?'&#x2705;':'&#x274C;' ?></td>
    </tr>
    <?php endforeach; if(empty($balances)): ?>
    <tr><td colspan="7" style="text-align:center;padding:20px;color:#aaa">No seller balances yet</td></tr>
    <?php endif ?>
  </table>

  <!-- STREAM HISTORY -->
  <div class="section-title" style="margin-top:20px">Stream History</div>
  <table>
    <tr><th>#</th><th>Store</th><th>Title</th><th>Status</th><th>Mins Used</th><th>Peak Viewers</th><th>Date</th></tr>
    <?php foreach($liveStreams as $ls): ?>
    <tr>
      <td><?= $ls['id'] ?></td>
      <td><?= htmlspecialchars($ls['store_name']??'-') ?></td>
      <td><?= htmlspecialchars(substr($ls['title'],0,28)) ?></td>
      <td><span class="badge b-<?= $ls['status']==='live'?'active':'rejected' ?>"><?= strtoupper($ls['status']) ?></span></td>
      <td><?= round($ls['minutes_used'],1) ?> mins</td>
      <td><?= $ls['peak_viewers'] ?></td>
      <td style="font-size:11px;color:#888"><?= date('d/m/y H:i', strtotime($ls['created_at'])) ?></td>
    </tr>
    <?php endforeach; if(empty($liveStreams)): ?>
    <tr><td colspan="7" style="text-align:center;padding:20px;color:#aaa">No streams yet</td></tr>
    <?php endif ?>
  </table>

<?php elseif ($tab === 'uploads'): ?>
  <div class="section-title">Recent Uploads</div>
  <?php $uploads = q("SELECT lm.*, l.title, s.store_name
    FROM cammarket237.listing_media lm
    LEFT JOIN cammarket237.listings l ON l.id=lm.listing_id
    LEFT JOIN cammarket237.stores s ON s.id=l.store_id
    ORDER BY lm.created_at DESC LIMIT 50"); ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
    <?php foreach($uploads as $u): ?>
    <div style="background:white;border-radius:10px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.08)">
      <?php if(strpos($u['media_url'],'/uploads/') !== false): ?>
        <img src="<?= htmlspecialchars($u['media_url']) ?>" style="width:100%;height:120px;object-fit:cover" onerror="this.style.display='none'"/>
      <?php else: ?>
        <img src="<?= htmlspecialchars($u['media_url']) ?>" style="width:100%;height:120px;object-fit:cover" onerror="this.style.display='none'"/>
      <?php endif ?>
      <div style="padding:8px;font-size:11px">
        <div style="font-weight:700;color:#0f1923;margin-bottom:2px"><?= htmlspecialchars(substr($u['title'] ?? '',0,20)) ?></div>
        <div style="color:#888"><?= htmlspecialchars($u['store_name'] ?? '') ?></div>
        <div style="color:#aaa;margin-top:2px"><?= date('d/m/y', strtotime($u['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach ?>
  </div>

<?php elseif ($tab === 'ads'): ?>

  <div class="section-title">&#x1F4E6; Ad Packages</div>
  <table style="margin-bottom:24px">
    <tr><th>Package</th><th>Reach</th><th>Duration</th><th>Price (FCFA)</th><th>Status</th></tr>
    <?php foreach(q("SELECT * FROM cammarket237.ad_packages ORDER BY display_order") as $p): ?>
    <tr>
      <td><strong><?= htmlspecialchars($p['name']) ?></strong><br><span style="font-size:11px;color:#888"><?= htmlspecialchars($p['description']??'') ?></span></td>
      <td><?= number_format($p['audience_cap']??$p['notification_count']??0) ?> buyers</td>
      <td><?= $p['duration_days'] ?> days</td>
      <td><?= number_format($p['price']??0) ?> FCFA</td>
      <td><span style="background:<?= $p['active'] ? '#d4edda' : '#f8d7da' ?>;color:<?= $p['active'] ? '#155724' : '#721c24' ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700"><?= $p['active'] ? 'Active' : 'Inactive' ?></span></td>
    </tr>
    <?php endforeach ?>
  </table>

  <div class="section-title">&#x1F4E3; Ad Campaigns</div>
  <?php
    $campaigns = q("SELECT c.*, p.name AS package_name, p.price AS pkg_price, p.audience_cap AS max_reach,
        a.business_name, a.contact_phone, u.full_name, u.phone AS user_phone
        FROM cammarket237.ad_campaigns c
        LEFT JOIN cammarket237.ad_packages p ON p.id=c.package_id
        LEFT JOIN cammarket237.advertiser_accounts a ON a.id=c.advertiser_id
        LEFT JOIN cammarket237.users u ON u.id=a.user_id
        ORDER BY c.created_at DESC");
  ?>
  <?php if(!$campaigns): ?>
    <div style="background:white;border-radius:12px;padding:30px;text-align:center;color:#aaa">
      <div style="font-size:32px;margin-bottom:8px">&#x1F4E3;</div>
      <div style="font-size:14px;font-weight:600">No ad campaigns yet</div>
      <div style="font-size:12px;margin-top:4px">Campaigns will appear here when sellers submit ads</div>
    </div>
  <?php else: ?>
  <table>
    <tr><th>ID</th><th>Advertiser</th><th>Package</th><th>Ad Content</th><th>Status</th><th>Submitted</th><th>Actions</th></tr>
    <?php foreach($campaigns as $c):
      $statusColor = [
        'submitted'=>'#fff3cd','approved'=>'#cce5ff','active'=>'#d4edda',
        'rejected'=>'#f8d7da','completed'=>'#e2e3e5'
      ][$c['status']] ?? '#eee';
      $statusText = [
        'submitted'=>'Pending Review','approved'=>'Approved — Awaiting Payment',
        'active'=>'🟢 LIVE','rejected'=>'Rejected','completed'=>'Completed'
      ][$c['status']] ?? $c['status'];
    ?>
    <tr>
      <td>#<?= $c['id'] ?></td>
      <td>
        <strong><?= htmlspecialchars($c['business_name'] ?: $c['full_name'] ?: '—') ?></strong><br>
        <span style="font-size:11px;color:#888"><?= htmlspecialchars($c['contact_phone'] ?: $c['user_phone'] ?: '') ?></span>
      </td>
      <td><?= htmlspecialchars($c['package_name'] ?? '—') ?><br>
        <span style="font-size:11px;color:#888"><?= number_format($c['price']??$c['pkg_price']??0) ?> FCFA &bull; <?= number_format($c['max_reach']??0) ?> reach</span>
      </td>
      <td style="max-width:200px">
        <?php if($c['push_title']): ?>
          <strong style="font-size:12px"><?= htmlspecialchars($c['push_title']) ?></strong><br>
          <span style="font-size:11px;color:#555"><?= htmlspecialchars(substr($c['push_body']??'',0,60)) ?><?= strlen($c['push_body']??'')>60?'…':'' ?></span>
        <?php else: ?><span style="color:#aaa;font-size:11px">No content submitted</span>
        <?php endif ?>
      </td>
      <td><span style="background:<?= $statusColor ?>;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700"><?= $statusText ?></span></td>
      <td style="font-size:11px;color:#888"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
      <td>
        <?php if($c['status']==='submitted'): ?>
          <?php act('approve_ad', $c['id'], '✅ Approve', '#1a7a3c') ?>
          <?php act('reject_ad',  $c['id'], '❌ Reject',  '#e74c3c') ?>
        <?php elseif($c['status']==='approved'): ?>
          <?php act('activate_ad', $c['id'], '🟢 Go Live', '#e67e22') ?>
          <?php act('reject_ad',   $c['id'], '❌ Reject',  '#e74c3c') ?>
        <?php elseif($c['status']==='active'): ?>
          <?php act('complete_ad', $c['id'], '✔ Complete', '#7f8c8d') ?>
        <?php else: ?>—
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>

<?php elseif ($tab === 'tickets'): ?>
<?php
// Handle status updates
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ticket_id'])) {
    $tid = intval($_POST['ticket_id']);
    $newStatus = in_array($_POST['ticket_status']??'', ['open','in_progress','resolved','closed']) ? $_POST['ticket_status'] : 'open';
    try { run("UPDATE cammarket237.support_tickets SET status=? WHERE id=?", [$newStatus, $tid]); $msg = "Ticket #$tid updated."; } catch(Exception $e){}
}
$tickets = [];
try {
    $tickets = q("SELECT t.id, t.type, t.title, t.description, t.screenshot_url, t.status, t.created_at,
                         u.full_name, u.phone
                  FROM cammarket237.support_tickets t
                  LEFT JOIN cammarket237.users u ON u.id=t.user_id
                  ORDER BY t.created_at DESC LIMIT 100");
} catch(Exception $e) {}
?>
<div class="section-title">&#x1F3AB; Help Tickets</div>
<?php if (!$tickets): ?>
  <div class="card" style="text-align:center;color:#888;padding:40px">No tickets yet.</div>
<?php else: ?>
<table>
  <tr><th>#</th><th>Type</th><th>Title</th><th>User</th><th>Description</th><th>Screenshot</th><th>Status</th><th>Date</th><th>Action</th></tr>
  <?php foreach($tickets as $t): ?>
  <tr>
    <td><?= $t['id'] ?></td>
    <td><span class="badge" style="background:<?= $t['type']==='bug'?'#f8d7da':'#d1ecf1' ?>;color:<?= $t['type']==='bug'?'#721c24':'#0c5460' ?>"><?= $t['type'] ?></span></td>
    <td><strong><?= htmlspecialchars($t['title']) ?></strong></td>
    <td style="font-size:12px"><?= htmlspecialchars($t['full_name'] ?? 'Guest') ?><br><span style="color:#888"><?= htmlspecialchars($t['phone'] ?? '') ?></span></td>
    <td style="font-size:12px;max-width:260px;color:#555"><?= htmlspecialchars(mb_substr($t['description'],0,140)) ?><?= mb_strlen($t['description'])>140?'…':'' ?></td>
    <td><?= $t['screenshot_url'] ? "<a href='{$t['screenshot_url']}' target='_blank'><img src='{$t['screenshot_url']}' style='width:50px;height:50px;object-fit:cover;border-radius:6px'/></a>" : '—' ?></td>
    <td><span class="badge" style="background:<?= $t['status']==='open'?'#fff3cd':($t['status']==='resolved'?'#d4edda':($t['status']==='in_progress'?'#cce5ff':'#e2e3e5')) ?>;color:#333"><?= $t['status'] ?></span></td>
    <td style="font-size:11px;color:#888"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
    <td>
      <form method="post" style="display:flex;gap:4px;align-items:center">
        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>"/>
        <input type="hidden" name="tab" value="tickets"/>
        <select name="ticket_status" style="padding:3px 6px;border-radius:6px;border:1px solid #ddd;font-size:11px">
          <?php foreach(['open','in_progress','resolved','closed'] as $s): ?>
          <option value="<?= $s ?>"<?= $t['status']===$s?' selected':'' ?>><?= $s ?></option>
          <?php endforeach ?>
        </select>
        <button style="background:#1a7a3c;color:white;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer">Save</button>
      </form>
    </td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>

<?php endif ?>
</div>
</body>
</html>
<?php }

function showLogin($err='') { ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Login - CamMarket237</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f1923;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:white;border-radius:16px;padding:40px;width:100%;max-width:360px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
h2{font-size:22px;font-weight:900;color:#0f1923;margin-bottom:6px}
.sub{font-size:13px;color:#888;margin-bottom:24px}
input{width:100%;padding:12px;border:1.5px solid #ddd;border-radius:10px;font-size:14px;margin-bottom:14px;box-sizing:border-box}
button{width:100%;padding:13px;background:#1a7a3c;color:white;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer}
.err{background:#fde8e8;border:1px solid #f5c0c0;border-radius:8px;padding:10px;margin-bottom:14px;color:#ce1126;font-size:13px}
.flag{text-align:center;font-size:28px;margin-bottom:16px}
</style>
</head>
<body>
<div class="box">
  <div class="flag">&#x1F1E8;&#x1F1F2;</div>
  <h2>Admin Panel</h2>
  <div class="sub">CamMarket237 Management</div>
  <?php if($err): ?><div class="err">&#x274C; <?= htmlspecialchars($err) ?></div><?php endif ?>
  <form method="post">
    <input type="password" name="admin_pass" placeholder="Admin password" autofocus/>
    <button type="submit">&#x1F512; Login</button>
  </form>
</div>
</body>
</html>
<?php }
