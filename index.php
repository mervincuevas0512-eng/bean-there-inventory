<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/notifications.php';
// Finished-product menu reference removed; this system manages ingredients only.
try { db(); } catch (PDOException $e) { redirect('setup.php'); }
if (!one("SELECT id FROM users WHERE role='owner' LIMIT 1")) redirect('setup.php');
$page=(string)($_GET['page']??(current_user()?'dashboard':'login'));
$pages=['login','dashboard','inventory','item','stock-in','stock-out','movements','reports','settings','users','batches','adjust-stock'];
if (!in_array($page,$pages,true)) $page='dashboard';
if ($page!=='login') {
  require_login();
}
if (current_user() && $page==='login') redirect('index.php?page=dashboard');
if (in_array($page,['settings','users','item','adjust-stock'],true) && current_user()['role']!=='owner') {
  http_response_code(403);exit('Not authorized.');
}
// Keep old item URLs compatible, but edit inside the Inventory modal instead.
if ($page==='item') {
  $legacyId=(int)($_GET['id']??0);
  redirect('index.php?page=inventory'.($legacyId>0?'&edit='.$legacyId:'&new=1'));
}
$itemFormRetry=$page==='inventory' && current_user() && current_user()['role']==='owner'
  ? ($_SESSION['item_form_retry']??null) : null;
unset($_SESSION['item_form_retry']);
$categoryFormRetry=$page==='inventory' && current_user() && current_user()['role']==='owner'
  ? ($_SESSION['category_form_retry']??null) : null;
unset($_SESSION['category_form_retry']);
$staffFormRetry=$page==='users' && current_user() && current_user()['role']==='owner'
  ? ($_SESSION['staff_form_retry']??null) : null;
unset($_SESSION['staff_form_retry']);
$adjustFormRetry=$page==='adjust-stock' && current_user() && current_user()['role']==='owner'
  ? ($_SESSION['adjust_form_retry']??null) : null;
unset($_SESSION['adjust_form_retry']);
$stockFormRetry=$_SESSION['stock_form_retry']??[];
unset($_SESSION['stock_form_retry']);
if (($stockFormRetry['action']??'')!==str_replace('-','_',$page)) $stockFormRetry=[];
$passwordFormRetry=(bool)($_SESSION['password_form_retry']??false);
unset($_SESSION['password_form_retry']);
$fl=take_flash();
$categories=$page==='login'?[]:all('SELECT c.id,c.name,(SELECT COUNT(*) FROM items i WHERE i.category_id=c.id) AS item_count FROM categories c ORDER BY c.name');
$inventory=$page==='login'?[]:stock_totals();
$activeItems=array_values(array_filter($inventory,fn($r)=>(int)$r['is_active']===1));

$warnDays=$page==='login'?7:(int)setting('expiry_warning_days','7');
$lots=$page==='login'?[]:all("SELECT l.*,i.name item_name,i.unit,i.sku
 FROM lots l JOIN items i ON i.id=l.item_id
 WHERE l.qty_on_hand>0 AND i.is_active=1
 ORDER BY (l.expiry_date IS NULL),l.expiry_date,l.received_on,l.id");
$adjustLots=$page==='adjust-stock'
  ? all("SELECT l.id,l.item_id,l.batch_code,l.received_on,l.expiry_date,l.qty_on_hand,i.unit,i.name item_name
         FROM lots l JOIN items i ON l.item_id=i.id WHERE i.is_active=1
         ORDER BY i.name,l.received_on,l.id")
  : [];
$expiring=array_values(array_filter($lots,fn($r)=>$r['expiry_date']!==null && $r['expiry_date']>=date('Y-m-d') && $r['expiry_date']<=date('Y-m-d',strtotime('+'.$warnDays.' days'))));
$expired=array_values(array_filter($lots,fn($r)=>$r['expiry_date']!==null && $r['expiry_date']<date('Y-m-d')));
$low=array_values(array_filter($activeItems,fn($r)=>inventory_status($r)[0]==='low'));
$outOfStock=array_values(array_filter($activeItems,fn($r)=>inventory_status($r)[0]==='out'));
$needsRestock=array_merge($outOfStock,$low);
$movements=[];
if (in_array($page,['dashboard','movements','reports'],true)) {
  $from=(string)($_GET['from']??date('Y-m-01'));
  $to=(string)($_GET['to']??date('Y-m-d'));
  if (!valid_date($from)) $from=date('Y-m-01');
  if (!valid_date($to)) $to=date('Y-m-d');
  if ($from>$to) [$from,$to]=[$to,$from];
  $movements=all("SELECT m.*,i.name item_name,i.unit,i.sku,l.batch_code,u.full_name
   FROM movements m JOIN items i ON m.item_id=i.id
   LEFT JOIN lots l ON m.lot_id=l.id JOIN users u ON u.id=m.user_id
   WHERE ".($page==='dashboard'?'1=1':'m.created_at >= ? AND m.created_at < DATE_ADD(?, INTERVAL 1 DAY)').
   " ORDER BY m.created_at DESC,m.id DESC LIMIT ".($page==='dashboard'?'6':'1000'),
   $page==='dashboard'?[]:[$from,$to]);
}
$nav=[
 ['dashboard','Overview','grid'],['inventory','Inventory','box'],
 ['stock-in','Stock In','plus'],['stock-out','Stock Out','minus'],
 ['batches','Stock Batches','layers'],['movements','Activity Log','clock'],
 ['reports','Reports','chart']
];
if (current_user() && current_user()['role']==='owner')
  array_splice($nav,4,0,[['adjust-stock','Adjust Stock','adjust']]);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07392f">
<title><?=e(ucwords(str_replace('-',' ',$page)))?> | Bean There Cafe</title>
<link rel="stylesheet" href="<?=e(base_url('assets/style.css'))?>?v=<?=filemtime(__DIR__.'/assets/style.css')?>">
<script defer src="<?=e(base_url('assets/app.js'))?>?v=<?=filemtime(__DIR__.'/assets/app.js')?>"></script>
</head><body class="<?= $page==='login'?'auth-body':'app-body'?>">
<?php if ($fl) echo notification_html((string)$fl[0], [(string)$fl[1]]); ?>
<?php if ($page==='login'): ?>
<main class="auth-panel">
  <div class="brand-mark brand-mark-large" role="img" aria-label="Bean There Cafe logo"><span>BEAN<br>THERE</span></div>
  <p class="eyebrow">INVENTORY MANAGEMENT</p><h1>Welcome back</h1>
  <p class="muted">Sign in to manage your cafe's ingredients and supplies.</p>
  <form method="post" action="<?=e(base_url('actions.php'))?>" class="form-stack">
    <?=csrf_field()?><input type="hidden" name="action" value="login">
    <label>Email address<input type="email" name="email" required autocomplete="username" placeholder="you@example.com"></label>
    <label>Password<div class="password-field"><input id="login-pass" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
      <button type="button" class="show-pass" data-toggle-password="login-pass" aria-label="Show password">Show</button></div></label>
    <button class="btn btn-primary btn-full">Sign In</button>
  </form>
  <p class="auth-foot">For authorized cafe personnel only</p>
</main>
<?php else: ?>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <a href="<?=e(base_url('index.php?page=dashboard'))?>" class="side-brand">
        <div class="brand-mark"><span>BEAN<br>THERE</span></div>
        <span><strong>Bean There Cafe</strong><small>Inventory System</small></span>
      </a>
    </div>
    <nav aria-label="Main navigation" class="side-nav">
      <?php foreach($nav as [$p,$label,$ico]):?>
      <a class="nav-link <?=$page===$p?'active':''?>" href="<?=e(base_url('index.php?page='.$p))?>" <?=$page===$p?'aria-current="page"':''?>>
        <span class="nav-icon"><?=e(['grid'=>'▦','box'=>'▤','plus'=>'+','minus'=>'−','layers'=>'≡','clock'=>'◷','adjust'=>'±','chart'=>'▥'][$ico])?></span>
        <span><?=e($label)?></span>
        <?php if($p==='inventory' && count($needsRestock)>0):?><span class="nav-count"><?=count($needsRestock)?></span><?php endif;?>
      </a><?php endforeach;?>
    </nav>
    <?php if(current_user()['role']==='owner'):?>
    <div class="sidebar-heading">ADMINISTRATION</div>
    <nav aria-label="Admin navigation" class="side-nav">
      <a class="nav-link <?=$page==='users'?'active':''?>" href="<?=e(base_url('index.php?page=users'))?>"><span class="nav-icon">◎</span>Staff Accounts</a>
      <a class="nav-link <?=$page==='settings'?'active':''?>" href="<?=e(base_url('index.php?page=settings'))?>"><span class="nav-icon">⚙</span>Settings</a>
    </nav>
    <?php endif;?>
    <div class="sidebar-bottom">
      <button type="button" class="user-chip user-chip-button" data-password-open
         aria-label="Open My Account and change password" title="My Account">
        <span class="avatar"><?=e(strtoupper(mb_substr(current_user()['name'],0,1)))?></span>
        <span class="user-text"><strong><?=e(current_user()['name'])?></strong>
          <small><?=e(ucfirst(current_user()['role']))?> · My Account</small>
        </span>
        <span class="account-chevron" aria-hidden="true">›</span>
      </button>
      <form method="post" action="<?=e(base_url('actions.php'))?>" data-signout-form>
        <?=csrf_field()?><input type="hidden" name="action" value="logout">
        <button class="logout-button" type="submit">Sign Out <span aria-hidden="true">↗</span></button>
      </form>
    </div>
  </aside>
  <div class="sidebar-overlay" data-close-menu></div>
  <main class="main">
    <button type="button" class="menu-toggle mobile-menu-button"
      aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar"
      data-menu-toggle>&#9776;</button>
    <div class="main-content">

    <?php if($page==='dashboard'): ?>
      <div class="page-intro"><div><h1>Inventory Overview</h1></div>
      <a class="btn btn-primary" href="<?=e(base_url('index.php?page=stock-in'))?>">+ Add Stock</a></div>
      <section class="stats-grid">
        <article class="stat"><span class="stat-name">Active Items</span><strong><?=count($activeItems)?></strong><small>Tracked ingredients & supplies</small></article>
        <article class="stat"><span class="stat-name">Needs Restocking</span><strong><?=count($needsRestock)?></strong><small><?=count($outOfStock)?> out of stock · <?=count($low)?> low</small></article>
        <article class="stat"><span class="stat-name">Expiring Soon</span><strong><?=count($expiring)?></strong><small>Within <?=$warnDays?> days</small></article>
        <article class="stat"><span class="stat-name">Expired Batches</span><strong><?=count($expired)?></strong><small>Require stock review</small></article>
      </section>
      <div class="two-panels">
        <section class="panel"><div class="panel-title"><h2>Stock Alerts</h2><a href="<?=e(base_url('index.php?page=inventory'))?>">View inventory</a></div>
          <?php if(!$needsRestock && !$expiring && !$expired):?><p class="empty-inline">You're all caught up. No alerts right now.</p>
          <?php else: ?><div class="alert-list">
          <?php foreach(array_slice($needsRestock,0,4) as $r):
            $stockStatus=inventory_status($r);
          ?><div class="alert-line"><span class="alert-dot <?=$stockStatus[0]==='out'?'danger':'warn'?>"></span>
            <div><strong><?=e($r['name'])?></strong><small><?=nice_qty($r['usable'])?> <?=e($r['unit'])?> usable · Minimum <?=nice_qty($r['min_level'])?> <?=e($r['unit'])?></small></div>
            <?=badge($stockStatus[1],$stockStatus[2])?></div><?php endforeach;?>
          <?php foreach(array_slice($expired,0,3) as $r):?><div class="alert-line"><span class="alert-dot danger"></span><div><strong><?=e($r['item_name'])?></strong><small>Batch <?=e($r['batch_code'])?> · <?=e($r['expiry_date'])?></small></div><?=badge('Expired','danger')?></div><?php endforeach;?>
          <?php foreach(array_slice($expiring,0,3) as $r):?><div class="alert-line"><span class="alert-dot orange"></span><div><strong><?=e($r['item_name'])?></strong><small>Batch <?=e($r['batch_code'])?> · <?=e($r['expiry_date'])?></small></div><?=badge('Soon','warning')?></div><?php endforeach;?>
          </div><?php endif;?>
        </section>
        <section class="panel"><div class="panel-title"><h2>Recent Activity</h2><a href="<?=e(base_url('index.php?page=movements'))?>">View all</a></div>
          <?php if(!$movements):?><p class="empty-inline">No stock movement yet.</p><?php else:?>
          <div class="activity-list"><?php foreach($movements as $m):?>
          <div class="activity"><span class="activity-symbol <?=in_array($m['type'],['IN','ADJUST_IN'])?'positive':'negative'?>"><?=in_array($m['type'],['IN','ADJUST_IN'])?'+':'−'?></span>
            <div><strong><?=e($m['item_name'])?></strong><small><?=e($m['full_name'])?> · <?=e(date('M j, g:i A',strtotime($m['created_at'])))?></small></div>
            <strong><?=nice_qty($m['quantity'])?> <?=e($m['unit'])?></strong></div>
          <?php endforeach;?></div><?php endif;?>
        </section>
      </div>
    <?php elseif($page==='inventory'): ?>
      <div class="page-intro inventory-heading"><div><h1>Inventory</h1></div>
      <?php if(current_user()['role']==='owner'):?>
      <div class="inventory-heading-actions">
        <button class="btn btn-ghost" type="button" data-category-open>Manage Categories</button>
        <button class="btn btn-primary" type="button" data-item-modal-open>+ New Item</button>
      </div><?php endif;?></div>
      <section class="panel" data-page-scope>
      <div class="tools-row"><label class="search-field"><span class="sr-only">Search inventory</span><input type="search" placeholder="Search by name or SKU..." data-filter-input></label>
        <select aria-label="Filter inventory" data-filter-status>
          <option value="all">All Items</option><option value="out">Out of Stock</option><option value="low">Low Stock</option><option value="available">In Stock</option><option value="inactive">Archived</option>
        </select></div>
      <div class="table-wrap"><table class="data-table inventory-table" data-page-items data-filter-table><thead><tr>
        <th>Item</th><th>Available Stock</th><th>Status</th><th class="inventory-action-header">Actions</th>
      </tr></thead><tbody>
      <?php foreach($inventory as $i):
        $stockStatus=inventory_status($i);
      ?>
      <tr data-search="<?=e(mb_strtolower($i['name'].' '.$i['sku'].' '.$i['category']))?>" data-status="<?=e($stockStatus[0])?>">
        <td data-label="Item" class="inventory-item-cell">
          <strong class="inventory-item-name"><?=e($i['name'])?></strong>
          <small class="inventory-item-meta"><?=e($i['sku'])?> · <?=e($i['category']??'Uncategorized')?></small>
        </td>
        <td data-label="Stock" class="inventory-balance-cell">
          <strong class="inventory-stock-value"><?=nice_qty($i['usable'])?> <?=e($i['unit'])?></strong>
          <small class="inventory-stock-detail">Min <?=nice_qty($i['min_level'])?> <?=e($i['unit'])?></small>
          <?php if((float)$i['expired']>0):?>
          <small class="inventory-stock-expired"><?=nice_qty($i['expired'])?> <?=e($i['unit'])?> expired</small>
          <?php endif;?>
        </td>
        <td data-label="Status" class="inventory-status-cell"><?=badge($stockStatus[1],$stockStatus[2])?></td>
        <td class="actions-cell">
          <div class="inventory-row-actions">
            <?php if((int)$i['is_active']===1):?>
              <a class="inventory-action-link" href="<?=e(base_url('index.php?page=stock-in&item_id='.$i['id']))?>" title="Receive stock for <?=e($i['name'])?>">Stock In</a>
              <a class="inventory-action-link" href="<?=e(base_url('index.php?page=stock-out&item_id='.$i['id']))?>" title="Record usage for <?=e($i['name'])?>">Stock Out</a>
            <?php endif;?>
            <?php if(current_user()['role']==='owner'):?>
            <button class="inventory-edit-action" type="button" data-item-modal-open
              data-item-id="<?=$i['id']?>" data-item-name="<?=e($i['name'])?>" data-item-sku="<?=e($i['sku'])?>"
              data-item-category="<?=$i['category_id']?>" data-item-unit="<?=e($i['unit'])?>"
              data-item-min="<?=e($i['min_level'])?>" data-item-has-history="<?= (int)$i['batch_count']>0?'1':'0' ?>"
              data-item-active="<?= (int)$i['is_active'] ?>" data-item-has-stock="<?= (float)$i['total']>0?'1':'0' ?>"
              aria-label="Edit <?=e($i['name'])?>">Edit</button>
            <?php endif;?>
          </div>
        </td>
      </tr><?php endforeach;?>
      </tbody></table></div>
      <p class="table-empty" data-empty-table hidden>No matching items.</p>
      <div class="pagination-bar" data-page-controls hidden></div>
      </section>
      <?php if(current_user()['role']==='owner'):?>
      <div class="item-modal-backdrop" data-item-modal data-item-retry="<?=e(json_encode($itemFormRetry??null, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>" hidden>
        <section class="item-dialog" role="dialog" aria-modal="true" aria-labelledby="item-dialog-title" tabindex="-1">
          <header class="item-dialog-header">
            <div><p class="eyebrow">INVENTORY ITEM</p><h2 id="item-dialog-title" data-item-dialog-title>Add New Item</h2></div>
            <button class="item-dialog-close" type="button" data-item-modal-close aria-label="Close item form">×</button>
          </header>
          <form method="post" action="<?=e(base_url('actions.php'))?>" data-item-form class="item-dialog-form">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="item_save">
            <input type="hidden" name="id" value="0">
            <div class="item-dialog-body">
              <p class="muted item-dialog-help" data-item-dialog-help>Set up the item details. Add its quantity later using Stock In.</p>
              <div class="form-stack">
                <label>Item Name<input name="name" maxlength="150" required placeholder="e.g. Fresh Milk"></label>
                <div class="form-grid">
                  <label>SKU / Code<input name="sku" maxlength="40" required pattern="[A-Za-z0-9_-]{2,40}" placeholder="e.g. MILK-001"></label>
                  <label>Category<select name="category_id" required><option value="">Select category</option>
                    <?php foreach($categories as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?>
                  </select></label>
                  <label>Unit of Measurement
                    <select name="unit" required>
                      <option value="">Select unit</option>
                      <?php foreach(['g','kg','ml','L','pcs','packs','boxes'] as $u):?><option value="<?=e($u)?>"><?=e($u)?></option><?php endforeach;?>
                    </select>
                    <input type="hidden" name="unit" data-locked-unit disabled>
                    <span class="hint" data-unit-lock-message hidden>Unit cannot change after stock has been recorded.</span>
                  </label>
                  <label>Low-stock Alert Level<input type="number" name="min_level" min="0" max="999999999" step="0.001" value="0" required></label>
                </div>
              </div>
            </div>
            <footer class="item-dialog-footer">
              <button type="submit" class="item-archive-button" formnovalidate data-item-toggle hidden>Archive Item</button>
              <button type="button" class="btn btn-ghost" data-item-modal-close>Cancel</button>
              <button class="btn btn-primary" type="submit" data-item-submit>Save Item</button>
            </footer>
          </form>
        </section>
      </div>
      <?php endif;?>
      <?php if(current_user()['role']==='owner'):?>
      <div class="item-modal-backdrop category-modal-backdrop" data-category-modal
        data-category-retry="<?=e(json_encode($categoryFormRetry??null, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>" hidden>
        <section class="item-dialog category-dialog" role="dialog" aria-modal="true" aria-labelledby="category-modal-title" tabindex="-1">
          <header class="item-dialog-header">
            <div><h2 id="category-modal-title">Manage Categories</h2></div>
            <button type="button" class="item-dialog-close" data-category-close aria-label="Close categories">×</button>
          </header>
          <div class="category-modal-body">
            <div data-category-normal-view>
              <div class="category-list">
                <?php foreach($categories as $c):?>
                <div class="category-list-row" data-category-row data-category-id="<?=$c['id']?>"
                  data-category-name="<?=e($c['name'])?>" data-category-count="<?=$c['item_count']?>">
                  <div class="category-list-info"><strong><?=e($c['name'])?></strong>
                    <small><?=$c['item_count']?> <?=((int)$c['item_count']===1?'item':'items')?></small></div>
                  <div class="category-row-actions">
                    <button type="button" class="category-text-action" data-category-edit>Edit</button>
                    <button type="button" class="category-text-action category-delete-action" data-category-delete
                      <?=((int)$c['item_count']>0?'disabled title="Category is in use"':'')?>>Delete</button>
                  </div>
                </div>
                <?php endforeach;?>
              </div>
              <form class="category-form" method="post" action="<?=e(base_url('actions.php'))?>" data-category-form>
                <?=csrf_field()?>
                <input type="hidden" name="action" value="category">
                <input type="hidden" name="id" value="0">
                <label for="category-input-name" data-category-form-label>New Category</label>
                <input id="category-input-name" name="name" required minlength="2" maxlength="90" placeholder="Category name" autocomplete="off">
                <div class="category-form-actions">
                  <button type="button" class="btn btn-ghost" data-category-cancel-edit hidden>Cancel</button>
                  <button type="submit" class="btn btn-primary" data-category-submit>Add Category</button>
                </div>
              </form>
            </div>
            <div class="category-confirm-view" data-category-confirm-view hidden>
              <div class="category-confirm-mark" aria-hidden="true">!</div>
              <h3>Delete category?</h3>
              <p data-category-confirm-text></p>
              <p class="category-confirm-note">This action cannot be undone.</p>
              <form method="post" action="<?=e(base_url('actions.php'))?>" class="category-confirm-actions">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="category_delete">
                <input type="hidden" name="id" value="">
                <button type="button" class="btn btn-ghost" data-category-cancel-delete>Cancel</button>
                <button type="submit" class="btn category-danger-btn">Delete Category</button>
              </form>
            </div>
          </div>
        </section>
      </div>
      <?php endif;?>
    <?php elseif($page==='stock-in' || $page==='stock-out'):
      $in=$page==='stock-in'; ?>
      <div class="page-intro"><div><p class="eyebrow">STOCK MOVEMENTS</p><h1><?=$in?'Receive Stock':'Record Stock Out'?></h1><p class="muted"><?=$in?'Record incoming ingredients and their batch dates.':'Log consumption, waste, or damage.'?></p></div></div>
      <div class="form-layout compact-stock-layout"><section class="panel stock-form-panel">
      <form method="post" action="<?=e(base_url('actions.php'))?>" class="form-stack" data-lock-submit <?=$in?'':'data-stock-out-form'?>><?=csrf_field()?>
      <input type="hidden" name="action" value="<?=$in?'stock_in':'stock_out'?>">
      <label>Ingredient / Supply<select name="item_id" required data-searchable-select data-search-placeholder="Search ingredient or supply..."><option value="">Select item</option><?php foreach($activeItems as $i):?>
      <option value="<?=$i['id']?>" <?= (int)($stockFormRetry['item_id']??$_GET['item_id']??0)===(int)$i['id']?'selected':'' ?>><?=e($i['name'])?> (<?=nice_qty($i['usable'])?> <?=e($i['unit'])?> usable)</option><?php endforeach;?></select></label>
      <div class="form-grid"><label>Quantity<input type="number" name="quantity" min="0.001" max="999999999" step="0.001" required placeholder="0.000" value="<?=e($stockFormRetry['quantity']??'')?>"></label>
      <?php if($in):?><label>Date Received<input type="date" name="received_on" required max="<?=e(date('Y-m-d'))?>" value="<?=e($stockFormRetry['received_on']??date('Y-m-d'))?>"></label>
      <label><span>Expiration Date <span class="optional">(optional)</span></span><input type="date" name="expiry_date" value="<?=e($stockFormRetry['expiry_date']??'')?>"></label>
      <label><span>Batch Reference <span class="optional">(optional)</span></span><input name="batch_code" maxlength="80" placeholder="Auto if blank" value="<?=e($stockFormRetry['batch_code']??'')?>"></label>
      <?php else:?><label>Reason<select name="reason" required><option value="">Select reason</option>
      <?php foreach(['Used / consumed','Waste / expired','Damaged','Other'] as $reason):?><option <?=($stockFormRetry['reason']??'')===$reason?'selected':''?>><?=e($reason)?></option><?php endforeach;?></select></label><?php endif;?></div>
      <?php if($in):?><label><span>Notes <span class="optional">(optional)</span></span><input name="reason" maxlength="180" placeholder="e.g. Supplier delivery" value="<?=e($stockFormRetry['reason']??'')?>"></label>
      <?php else:?>
      <label data-affected-batch hidden>Affected Stock Batch
        <select name="lot_id" disabled data-searchable-select data-search-placeholder="Search stock batch...">
          <option value="">Select the batch to deduct</option>
          <?php foreach($lots as $l): ?>
            <option value="<?=$l['id']?>" <?=((int)($stockFormRetry['lot_id']??0)===(int)$l['id'])?'selected':''?> data-batch-item="<?=$l['item_id']?>" data-search-primary="<?=e($l['batch_code'])?>" data-search-secondary="<?=nice_qty($l['qty_on_hand'])?> <?=e($l['unit'])?> · <?=e($l['expiry_date']??'No expiry date')?>">
              <?=e($l['item_name'])?> — <?=e($l['batch_code'])?> · <?=nice_qty($l['qty_on_hand'])?> <?=e($l['unit'])?> · <?=e($l['expiry_date']??'No expiry date')?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Choose the affected batch.</span>
      </label>
      <?php endif;?>
      <div class="form-actions"><button class="btn btn-primary"><?=$in?'Save Stock Receipt':'Record Stock Out'?></button>
      <a class="btn btn-ghost" href="<?=e(base_url('index.php?page=inventory'))?>">Cancel</a></div>
      </form></section></div>
    <?php elseif($page==='adjust-stock'): ?>
      <div class="page-intro">
        <div><h1>Adjust Stock</h1><p class="muted">Correct a batch quantity after a physical stock count.</p></div>
      </div>
      <div class="form-layout compact-stock-layout"><section class="panel stock-form-panel">
        <form class="form-stack" data-adjust-stock-form data-lock-submit method="post" action="<?=e(base_url('actions.php'))?>">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="stock_adjust">
          <label>Ingredient / Supply
            <select name="item_id" required data-searchable-select data-search-placeholder="Search ingredient or supply...">
              <option value="">Select item</option>
              <?php foreach($activeItems as $i):?>
              <option value="<?=$i['id']?>" <?=((int)($adjustFormRetry['item_id']??($_GET['item_id']??0))===(int)$i['id'])?'selected':''?>><?=e($i['name'])?></option>
              <?php endforeach;?>
            </select>
          </label>
          <label>Stock Batch
            <select name="lot_id" required data-searchable-select data-search-placeholder="Search stock batch...">
              <option value="">Select a batch</option>
              <?php foreach($adjustLots as $lot):?>
              <option value="<?=$lot['id']?>" data-adjust-item="<?=$lot['item_id']?>"
                data-adjust-quantity="<?=e($lot['qty_on_hand'])?>" data-adjust-unit="<?=e($lot['unit'])?>" data-search-primary="<?=e($lot['batch_code'])?>" data-search-secondary="<?=nice_qty($lot['qty_on_hand'])?> <?=e($lot['unit'])?> · <?=e($lot['expiry_date']??'No expiry date')?>"
                <?=((int)($adjustFormRetry['lot_id']??0)===(int)$lot['id'])?'selected':''?>>
                <?=e($lot['batch_code'])?> (<?=nice_qty($lot['qty_on_hand'])?> <?=e($lot['unit'])?> recorded<?= $lot['expiry_date']!==null?', expires '.e($lot['expiry_date']):'' ?>)
              </option>
              <?php endforeach;?>
            </select>
          </label>
          <div class="adjust-count-current" data-adjust-current hidden>
            <span>Recorded batch quantity</span><strong data-adjust-recorded>—</strong>
          </div>
          <label>Actual Counted Quantity
            <input name="actual_quantity" type="number" min="0" max="999999999"
              step="0.001" required inputmode="decimal" placeholder="Enter the quantity you counted"
              value="<?=e((string)($adjustFormRetry['actual_quantity']??''))?>">
              <span class="hint">Count the quantity in this batch.</span>
          </label>
          <div class="adjust-count-result" data-adjust-result aria-live="polite" hidden>
            <span>Difference to record</span><strong data-adjust-difference>—</strong>
          </div>
          <label>Reason for Correction
            <input name="reason" required minlength="5" maxlength="140"
              placeholder="e.g. Physical count mismatch"
              value="<?=e((string)($adjustFormRetry['reason']??''))?>">
          </label>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-adjust-save>Save Adjustment</button>
            <a class="btn btn-ghost" href="<?=e(base_url('index.php?page=inventory'))?>">Cancel</a>
          </div>
        </form>
      </section>
      </div>
    <?php elseif($page==='batches'):?>
      <div class="page-intro"><div><p class="eyebrow">EXPIRATION MONITORING</p><h1>Stock Batches</h1><p class="muted">See batch quantities and expiration dates.</p></div></div>
      <section class="panel" data-page-scope><div class="tools-row"><label class="search-field"><span class="sr-only">Search batch</span><input data-filter-input type="search" placeholder="Search item or batch..."></label>
        <select data-filter-status aria-label="Filter batch status"><option value="all">All Batches</option><option value="expired">Expired</option><option value="soon">Expiring Soon</option><option value="good">Good</option><option value="no-date">No expiry date</option></select></div>
      <div class="table-wrap"><table class="data-table" data-page-items data-filter-table><thead><tr><th>Item / Batch</th><th>Received</th><th>Expires</th><th>Remaining</th><th>Status</th></tr></thead><tbody>
      <?php foreach($lots as $l):
        $state=$l['expiry_date']===null?'no-date':($l['expiry_date']<date('Y-m-d')?'expired':($l['expiry_date']<=date('Y-m-d',strtotime('+'.$warnDays.' days'))?'soon':'good'));
      ?>
      <tr data-search="<?=e(mb_strtolower($l['item_name'].' '.$l['batch_code']))?>" data-status="<?=$state?>">
        <td data-label="Item"><strong><?=e($l['item_name'])?></strong><small class="cell-sub">Batch <?=e($l['batch_code'])?></small></td>
        <td data-label="Received"><?=e($l['received_on'])?></td><td data-label="Expiry"><?=e($l['expiry_date']??'Not recorded')?></td>
        <td data-label="Remaining"><?=nice_qty($l['qty_on_hand'])?> <?=e($l['unit'])?></td>
        <td data-label="Status"><?=badge($state==='expired'?'Expired':($state==='soon'?'Expiring Soon':($state==='no-date'?'No expiry date':'Good')),
          $state==='expired'?'danger':($state==='soon'?'warning':($state==='no-date'?'neutral':'success')))?></td>
      </tr><?php endforeach;?></tbody></table></div><p class="table-empty" data-empty-table hidden>No matching batches.</p><div class="pagination-bar" data-page-controls hidden></div></section>
    <?php elseif($page==='movements' || $page==='reports'):?>
      <div class="page-intro"><div><p class="eyebrow"><?=$page==='reports'?'INVENTORY ANALYTICS':'AUDIT TRAIL'?></p><h1><?=$page==='reports'?'Reports':'Activity Log'?></h1><p class="muted">Review recorded stock movements and dates.</p></div>
        
      </div>
      <section class="panel" data-page-scope>
      <form method="get" action="<?=e(base_url('index.php'))?>" class="filters-form">
        <input type="hidden" name="page" value="<?=e($page)?>">
        <label>From<input type="date" name="from" value="<?=e($from)?>"></label>
        <label>To<input type="date" name="to" value="<?=e($to)?>"></label>
        <button class="btn btn-secondary">Apply Filter</button>
        <?php if($page==='reports'):?><button type="button" class="btn btn-ghost" onclick="window.print()">Print</button><?php endif;?>
      </form>
      <?php
        $inQty=0;$outQty=0;
        foreach($movements as $m) {if(in_array($m['type'],['IN','ADJUST_IN'])) $inQty++;else $outQty++;}
      ?>
      <?php if($page==='reports'):?><div class="report-summary">
        <div><span>Transactions</span><strong><?=count($movements)?></strong></div>
        <div><span>Stock-in Entries</span><strong><?=$inQty?></strong></div>
        <div><span>Stock-out Entries</span><strong><?=$outQty?></strong></div>
      </div><?php endif;?>
             <div class="tools-row movement-search-row">
         <label class="search-field"><span class="sr-only">Search movements</span><input data-filter-input type="search" placeholder="Search item, reason or staff..." autocomplete="off"></label>
       </div>
       <div class="table-wrap"><table class="data-table" data-page-items><thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Quantity</th><th>Recorded By</th></tr></thead><tbody>
      <?php foreach($movements as $m):?>
             <tr data-search="<?=e(mb_strtolower($m['item_name'].' '.$m['sku'].' '.$m['reason'].' '.$m['full_name'].' '.$m['type'].' '.($m['batch_code']??'')))?>"><td data-label="Date"><?=e(date('M j, Y',strtotime($m['created_at'])))?><small class="cell-sub"><?=e(date('g:i A',strtotime($m['created_at'])))?></small></td>
      <td data-label="Item"><strong><?=e($m['item_name'])?></strong><small class="cell-sub"><?=e($m['reason']??'No notes')?><?php if(!empty($m['batch_code'])):?> · Batch <?=e($m['batch_code'])?><?php endif;?></small></td>
      <td data-label="Type"><?=badge(str_replace('_',' ',$m['type']),in_array($m['type'],['IN','ADJUST_IN'])?'success':'neutral')?></td>
      <td data-label="Quantity"><?=nice_qty($m['quantity'])?> <?=e($m['unit'])?></td><td data-label="Recorded By"><?=e($m['full_name'])?></td></tr>
      <?php endforeach;?></tbody></table></div>
             <p class="table-empty" data-empty-table hidden>No matching transactions.</p>
       <div class="pagination-bar" data-page-controls hidden></div>
       <?php if(count($movements)>=1000):?><p class="hint">Showing the latest 1,000 entries. Narrow the date range to view older activity.</p><?php endif;?>
      </section>
    <?php elseif($page==='settings'):?>
      <div class="page-intro"><div><p class="eyebrow">ADMINISTRATION</p><h1>Settings</h1>
        <p class="muted">Manage alerts and account security.</p></div></div>
      <div class="settings-grid">
        <section class="panel settings-card">
          <h2>Expiry Alerts</h2>
          <p class="muted">Show an alert before a stock batch expires.</p>
          <form method="post" action="<?=e(base_url('actions.php'))?>" class="form-stack settings-form">
            <?=csrf_field()?><input type="hidden" name="action" value="settings">
            <label>Alert before expiry (days)
              <input type="number" name="days" min="1" max="60" step="1" inputmode="numeric"
                value="<?=$warnDays?>" required>
            </label>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Save Changes</button></div>
          </form>
        </section>
        <section class="panel settings-card settings-account-card">
          <h2>Account Security</h2>
          <p class="muted">Keep your sign-in password up to date.</p>
          <button type="button" class="btn btn-secondary" data-password-open>Change Password</button>
        </section>
      </div>
    <?php elseif($page==='users'): $staff=all("SELECT id,full_name,email,active,created_at FROM users WHERE role='staff' AND email<>'inventory.setup@inventory.local.invalid' ORDER BY created_at DESC");?>
      <div class="page-intro staff-heading">
        <div><h1>Staff Accounts</h1><p class="muted">Manage authorized staff access.</p></div>
        <button class="btn btn-primary" type="button" data-staff-open>+ Add Staff</button>
      </div>
      <section class="panel staff-panel" data-page-scope>
        <div class="staff-panel-heading">
          <h2>Staff Members <span class="staff-total"><?=count($staff)?></span></h2>
        </div>
        <?php if($staff):?>
        <div class="tools-row staff-search-tools">
          <label class="search-field"><span class="sr-only">Search staff accounts</span>
            <input data-filter-input type="search" placeholder="Search name or email..." autocomplete="off"></label>
          <select data-filter-status aria-label="Filter staff status">
            <option value="all">All Staff</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
          </select>
        </div>
        <?php endif;?>
        <div class="staff-list" data-page-items data-page-row-selector=".staff-row">
        <?php foreach($staff as $s):?>
        <div class="staff-row" data-search="<?=e(mb_strtolower($s['full_name'].' '.$s['email']))?>" data-status="<?=$s['active']?'active':'disabled'?>">
          <div class="avatar"><?=e(strtoupper(mb_substr($s['full_name'],0,1)))?></div>
          <div class="staff-info"><strong><?=e($s['full_name'])?></strong><small><?=e($s['email'])?></small></div>
          <?=badge($s['active']?'Active':'Disabled',$s['active']?'success':'neutral')?>
          <form method="post" action="<?=e(base_url('actions.php'))?>">
            <?=csrf_field()?><input type="hidden" name="action" value="user_toggle">
            <input type="hidden" name="id" value="<?=$s['id']?>">
            <button class="btn btn-ghost staff-toggle-button" type="submit"><?=$s['active']?'Disable':'Enable'?></button>
          </form>
        </div>
        <?php endforeach;?>
        </div>
        <?php if(!$staff):?>
          <div class="staff-empty">
            <p>No staff accounts yet.</p>
            <button type="button" class="btn btn-secondary" data-staff-open>Add First Staff</button>
          </div>
        <?php endif;?>
        <p class="table-empty" data-empty-table hidden>No staff accounts match your search.</p>
        <div class="pagination-bar" data-page-controls hidden></div>
      </section>
      <div class="item-modal-backdrop staff-modal-backdrop" data-staff-modal data-staff-retry="<?=e(json_encode($staffFormRetry??null,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>" hidden>
        <section class="item-dialog staff-dialog" role="dialog" aria-modal="true" aria-labelledby="staff-dialog-title" tabindex="-1">
          <header class="item-dialog-header">
            <h2 id="staff-dialog-title">Add Staff Member</h2>
            <button type="button" class="item-dialog-close" data-staff-close aria-label="Close staff form">×</button>
          </header>
          <form class="item-dialog-form" method="post" action="<?=e(base_url('actions.php'))?>" data-staff-form>
            <?=csrf_field()?><input type="hidden" name="action" value="user_save">
            <div class="item-dialog-body">
              <div class="form-stack">
                <label>Full Name<input name="name" required minlength="2" maxlength="120" autocomplete="name" placeholder="Staff member's name"></label>
                <label>Email Address<input name="email" type="email" required maxlength="190" autocomplete="off" placeholder="name@example.com"></label>
                <label>Temporary Password
                  <div class="password-field">
                    <input name="password" id="staff-temp-password" type="password" minlength="8"
                      required autocomplete="new-password" placeholder="Create a temporary password">
                    <button class="show-pass" type="button" data-toggle-password="staff-temp-password" aria-label="Show password">Show</button>
                  </div>
                  <ul class="account-password-rules staff-requirements" data-staff-password-rules hidden aria-live="polite">
                    <li data-rule="length">At least 8 characters</li>
                    <li data-rule="uppercase">At least 1 uppercase letter</li>
                    <li data-rule="number">At least 1 number</li>
                  </ul>
                </label>
              </div>
            </div>
            <footer class="item-dialog-footer">
              <button type="button" class="btn btn-ghost" data-staff-close>Cancel</button>
              <button type="submit" class="btn btn-primary">Create Account</button>
            </footer>
          </form>
        </section>
      </div>
    <?php endif;?>
    <div class="item-modal-backdrop signout-backdrop" data-signout-modal hidden>
      <section class="item-dialog signout-dialog" role="alertdialog" aria-modal="true"
        aria-labelledby="signout-title" aria-describedby="signout-details" tabindex="-1">
        <div class="signout-content">
          <div class="signout-icon" aria-hidden="true">↪</div>
          <h2 id="signout-title">Sign out?</h2>
          <p class="muted" id="signout-details">You'll need to sign in again to access the inventory.</p>
        </div>
        <div class="signout-actions">
          <button type="button" class="btn btn-secondary" data-signout-cancel>Cancel</button>
          <button type="button" class="btn btn-primary" data-signout-confirm>Sign Out</button>
        </div>
      </section>
    </div>
    <div class="item-modal-backdrop account-password-backdrop" data-password-modal
       data-password-retry="<?= $passwordFormRetry?'1':'0' ?>" hidden>
      <section class="item-dialog account-password-dialog" role="dialog" aria-modal="true"
        aria-labelledby="account-password-title" tabindex="-1">
        <header class="item-dialog-header">
          <h2 id="account-password-title">My Account</h2>
          <button class="item-dialog-close" type="button" data-password-close aria-label="Close password form">×</button>
        </header>
        <form method="post" action="<?=e(base_url('actions.php'))?>" class="item-dialog-form" data-password-form>
          <?=csrf_field()?><input type="hidden" name="action" value="password_change">
          <div class="item-dialog-body">
            <div class="account-summary">
              <span class="avatar"><?=e(strtoupper(mb_substr(current_user()['name'],0,1)))?></span>
              <div class="account-summary-text"><strong><?=e(current_user()['name'])?></strong>
                <small><?=e(current_user()['email']??'')?></small></div>
              <span class="account-role"><?=e(ucfirst(current_user()['role']))?></span>
            </div>
            <h3 class="account-section-title">Change Password</h3>
            <div class="form-stack">
              <label>Current Password
                <div class="password-field">
                  <input id="account-current-password" name="current_password" type="password" required autocomplete="current-password">
                  <button type="button" class="show-pass" data-toggle-password="account-current-password">Show</button>
                </div>
              </label>
              <label>New Password
                <div class="password-field">
                  <input id="account-new-password" name="new_password" type="password"
                     required minlength="8" autocomplete="new-password">
                  <button type="button" class="show-pass" data-toggle-password="account-new-password">Show</button>
                </div>
              </label>
              <ul class="account-password-rules" data-password-rules hidden aria-live="polite">
                <li data-rule="length">At least 8 characters</li>
                <li data-rule="uppercase">At least 1 uppercase letter</li>
                <li data-rule="number">At least 1 number</li>
              </ul>
              <label>Confirm New Password
                <div class="password-field">
                  <input id="account-confirm-password" name="confirm_password" type="password"
                     required minlength="8" autocomplete="new-password">
                  <button type="button" class="show-pass" data-toggle-password="account-confirm-password">Show</button>
                </div>
                <small class="field-error" data-password-mismatch hidden>Passwords do not match.</small>
              </label>
            </div>
          </div>
          <footer class="item-dialog-footer">
            <button class="btn btn-ghost" type="button" data-password-close>Cancel</button>
            <button class="btn btn-primary" type="submit">Save Password</button>
          </footer>
        </form>
      </section>
    </div>
    <footer class="footer">Bean There Cafe · Inventory Management</footer>
    </div>
  </main>
</div>
<?php endif;?></body></html>
