<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/inventory_logic.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit('Method not allowed.');}
require_csrf();
$action=(string)($_POST['action']??'');
if ($action==='login') {
  $email=strtolower(trim((string)($_POST['email']??'')));
  $pass=(string)($_POST['password']??'');
  $ip=substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,45);
  try {
    $hits=(int)(one('SELECT COUNT(*) total FROM login_attempts WHERE email=? AND ip=? AND attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
      [$email,$ip])['total']??0);
    if ($hits>=5) throw new InvalidArgumentException('Too many attempts. Try again in 15 minutes.');
    $user=one('SELECT * FROM users WHERE email=? AND active=1',[$email]);
    if (!$user || !password_verify($pass,$user['password_hash'])) {
      query('INSERT INTO login_attempts (email,ip) VALUES (?,?)',[$email,$ip]);
      throw new InvalidArgumentException('Incorrect email or password.');
    }
    query('DELETE FROM login_attempts WHERE email=? AND ip=?',[$email,$ip]);
    session_regenerate_id(true);
    $_SESSION['user']=['id'=>(int)$user['id'],'name'=>$user['full_name'],'email'=>$user['email'],'role'=>$user['role']];
    $_SESSION['auth_fingerprint']=hash('sha256',$user['password_hash']);
    $_SESSION['last_seen']=time();
    $_SESSION['csrf']=bin2hex(random_bytes(24));
    flash('success', 'Signed in successfully.');
    redirect('index.php?page=dashboard');
  } catch(Throwable $e) {flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'Unable to sign in.');redirect('index.php?page=login');}
}
if ($action==='logout') {
  require_login();$_SESSION=[];session_regenerate_id(true);
  flash('success','You have signed out.');
  redirect('index.php?page=login');
}
require_login();
$user=current_user();
$return='index.php?page=dashboard';
try {
  // Legacy checkbox submissions must not silently classify regular stock as corrections.
  if (in_array($action,['stock_in','stock_out'],true) && !empty($_POST['adjustment']))
    throw new InvalidArgumentException('Use Adjust Stock to correct a physical stock count.');
  switch($action) {
    case 'category':
    case 'category_edit':
      require_owner();
      $categoryId=(int)($_POST['id']??0);
      $name=preg_replace('/\\s+/u',' ',trim((string)($_POST['name']??'')));
      if (mb_strlen($name)<2 || mb_strlen($name)>90)
        throw new InvalidArgumentException('Category name must be 2 to 90 characters.');
      if (one('SELECT id FROM categories WHERE name=? AND id<>? LIMIT 1',[$name,$categoryId]))
        throw new InvalidArgumentException('This category already exists.');
      if ($action==='category_edit') {
        if ($categoryId<=0 || !one('SELECT id FROM categories WHERE id=?',[$categoryId]))
          throw new InvalidArgumentException('Category not found.');
        query('UPDATE categories SET name=? WHERE id=?',[$name,$categoryId]);
        flash('success','Category updated.');
      } else {
        query('INSERT INTO categories (name) VALUES (?)',[$name]);
        flash('success','Category added.');
      }
      $return='index.php?page=inventory&categories=1';break;
    case 'category_delete':
      require_owner();
      $categoryId=(int)($_POST['id']??0);
      if ($categoryId<=0 || !one('SELECT id FROM categories WHERE id=?',[$categoryId]))
        throw new InvalidArgumentException('Category not found.');
      if (one('SELECT id FROM items WHERE category_id=? LIMIT 1',[$categoryId]))
        throw new InvalidArgumentException('Cannot delete a category that still contains inventory items.');
      query('DELETE FROM categories WHERE id=?',[$categoryId]);
      $return='index.php?page=inventory&categories=1';
      flash('success','Category deleted.');break;
    case 'item_save':
      require_owner();
      $id=(int)($_POST['id']??0);
      $name=trim((string)($_POST['name']??''));
      $sku=strtoupper(trim((string)($_POST['sku']??'')));
      $unit=trim((string)($_POST['unit']??''));
      $cat=(int)($_POST['category_id']??0);
      $min=trim((string)($_POST['min_level']??'0'));
      if (mb_strlen($name)<2 || mb_strlen($name)>150) throw new InvalidArgumentException('Enter a name (2–150 characters).');
      if (!preg_match('/^[A-Z0-9_-]{2,40}$/',$sku)) throw new InvalidArgumentException('SKU must use 2–40 letters, numbers, dashes or underscores.');
      if (!in_array($unit,['g','kg','ml','L','pcs','packs','boxes'],true)) throw new InvalidArgumentException('Choose a valid unit.');
      if (!one('SELECT id FROM categories WHERE id=?',[$cat])) throw new InvalidArgumentException('Choose a category.');
      if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$min)) throw new InvalidArgumentException('Invalid minimum level.');
      if ($id>0) {
        $previous=one('SELECT unit FROM items WHERE id=?',[$id]);
        if (!$previous) throw new InvalidArgumentException('Item not found.');
        if ($previous['unit']!==$unit && one('SELECT id FROM lots WHERE item_id=? LIMIT 1',[$id]))
          throw new InvalidArgumentException('Unit cannot be changed after stock transactions. Keep the original unit for accurate history.');
        query('UPDATE items SET name=?,sku=?,category_id=?,unit=?,min_level=? WHERE id=?',[$name,$sku,$cat,$unit,$min,$id]);
        flash('success','Item updated.');
      } else {
        query('INSERT INTO items (name,sku,category_id,unit,min_level) VALUES (?,?,?,?,?)',[$name,$sku,$cat,$unit,$min]);
        flash('success','Item created.');
      }
      $return='index.php?page=inventory';break;
    case 'item_toggle':
      require_owner();
      $id=(int)($_POST['id']??0);
      db()->beginTransaction();
      try {
        $row=one('SELECT is_active FROM items WHERE id=? FOR UPDATE',[$id]);
        if (!$row) throw new InvalidArgumentException('Item not found.');
        if ((int)$row['is_active']===1) {
          $stock=(float)(one('SELECT COALESCE(SUM(qty_on_hand),0) q FROM lots WHERE item_id=?',[$id])['q']??0);
          if ($stock>0) throw new InvalidArgumentException('Clear remaining stock before archiving this item.');
        }
        query('UPDATE items SET is_active=? WHERE id=?',[(int)$row['is_active']?0:1,$id]);
        db()->commit();
      } catch(Throwable $e) {db()->rollBack();throw $e;}
      $return='index.php?page=inventory';flash('success','Item status updated.');break;
    case 'stock_in':
      $id=(int)($_POST['item_id']??0);
      $qty=qty_value($_POST['quantity']??'');
      $received=(string)($_POST['received_on']??'');
      $expiry=trim((string)($_POST['expiry_date']??''))?:null;
      $reason=trim((string)($_POST['reason']??''));
      if (mb_strlen($reason)>180) throw new InvalidArgumentException('Reason is too long.');
      stock_in($id,$qty,$received,$expiry,(string)($_POST['batch_code']??''),$reason,(int)$user['id']);
      $return='index.php?page=movements';flash('success','Stock receipt saved.');break;
    case 'stock_out':
      $id=(int)($_POST['item_id']??0);
      $qty=qty_value($_POST['quantity']??'');
      $reason=(string)($_POST['reason']??'');
      $lotId=filter_var($_POST['lot_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
      if (!in_array($reason,['Used / consumed','Waste / expired','Damaged','Other'],true))
        throw new InvalidArgumentException('Select a stock-out reason.');
      stock_out($id,$qty,$reason,(int)$user['id'],false,$lotId?:null);
      $return='index.php?page=movements';flash('success','Stock-out saved.');break;
    case 'stock_adjust':
      require_owner();
      $id=(int)($_POST['item_id']??0);
      $lotId=(int)($_POST['lot_id']??0);
      $actualRaw=trim((string)($_POST['actual_quantity']??''));
      if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$actualRaw))
        throw new InvalidArgumentException('Actual count must be zero or greater, with up to 3 decimal places.');
      $item=item_row($id);
      $change=reconcile_batch_stock($id,$lotId,(float)$actualRaw,(string)($_POST['reason']??''),(int)$user['id']);
      $return='index.php?page=movements';
      flash('success','Stock corrected by '.($change>0?'+':'').nice_qty($change).' '.$item['unit'].'.');
      break;
    case 'adjust_in':
    case 'adjust_out':
      require_owner();
      throw new InvalidArgumentException('Use Adjust Stock to correct a physical stock count.');
    case 'password_change':
      $current=(string)($_POST['current_password']??'');
      $new=(string)($_POST['new_password']??'');
      $confirm=(string)($_POST['confirm_password']??'');
      if (strlen($new)<8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new))
        throw new InvalidArgumentException('New password needs 8 characters, an uppercase letter and a number.');
      if (!hash_equals($new,$confirm)) throw new InvalidArgumentException('Password confirmation does not match.');
      $account=one('SELECT password_hash FROM users WHERE id=? AND active=1',[(int)$user['id']]);
      if (!$account || !password_verify($current,(string)$account['password_hash']))
        throw new InvalidArgumentException('Current password is incorrect.');
      if (password_verify($new,(string)$account['password_hash']))
        throw new InvalidArgumentException('Choose a different new password.');
      $newHash=password_hash($new,PASSWORD_DEFAULT);
      query('UPDATE users SET password_hash=? WHERE id=?',[$newHash,(int)$user['id']]);
      $_SESSION['auth_fingerprint']=hash('sha256',$newHash);
      session_regenerate_id(true);
      $return='index.php?page=dashboard';flash('success','Password updated successfully.');
      break;
    case 'settings':
      require_owner();
      $rawDays=trim((string)($_POST['days']??''));
      if (!preg_match('/^[0-9]{1,2}$/',$rawDays)) throw new InvalidArgumentException('Enter a whole number of days from 1 to 60.');
      $days=(int)$rawDays;
      if ($days<1 || $days>60) throw new InvalidArgumentException('Choose 1–60 days.');
      query('UPDATE settings SET setting_value=? WHERE setting_key=?',[(string)$days,'expiry_warning_days']);
      $return='index.php?page=settings';flash('success','Settings updated.');break;
    case 'user_save':
      require_owner();
      $name=trim((string)($_POST['name']??''));
      $email=strtolower(trim((string)($_POST['email']??'')));
      $pw=(string)($_POST['password']??'');
      if (mb_strlen($name)<2 || mb_strlen($name)>120 || preg_match('/[\\p{C}<>]/u',$name) || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>190)
        throw new InvalidArgumentException('Enter a name and valid email.');
      if (strlen($pw)<8 || !preg_match('/[A-Z]/',$pw) || !preg_match('/[0-9]/',$pw))
        throw new InvalidArgumentException('Staff password needs at least 8 characters, an uppercase letter and a number.');
      if (one('SELECT id FROM users WHERE email=? LIMIT 1',[$email]))
        throw new InvalidArgumentException('This email address is already registered.');
      query("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'staff')",
        [$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
      $return='index.php?page=users';flash('success','Staff account added.');break;
    case 'user_toggle':
      require_owner();
      $id=(int)($_POST['id']??0);
      $row=one('SELECT role,active FROM users WHERE id=?',[$id]);
      if (!$row || $row['role']==='owner') throw new InvalidArgumentException('Only staff accounts may be changed.');
      query('UPDATE users SET active=? WHERE id=?',[$row['active']?0:1,$id]);
      $return='index.php?page=users';flash('success','Staff access updated.');break;
    default: throw new InvalidArgumentException('Unknown action.');
  }
} catch (InvalidArgumentException $e) {
  flash('error',$e->getMessage());
  $allowed=['category'=>'inventory','category_edit'=>'inventory','category_delete'=>'inventory','item_save'=>'inventory','item_toggle'=>'inventory',
    'stock_in'=>'stock-in','stock_out'=>'stock-out','stock_adjust'=>'adjust-stock','adjust_in'=>'adjust-stock','adjust_out'=>'adjust-stock',
    'settings'=>'settings','user_save'=>'users','user_toggle'=>'users','password_change'=>'dashboard'];
  $return='index.php?page='.($allowed[$action]??'dashboard');
} catch(PDOException $e) {
  error_log($e->getMessage());
  flash('error','Unable to save. Check for duplicate entries or invalid values.');
  $savePages=['user_save'=>'users','stock_adjust'=>'adjust-stock','stock_in'=>'stock-in','stock_out'=>'stock-out','settings'=>'settings','password_change'=>'dashboard'];
  $return='index.php?page='.($savePages[$action]??'inventory');
} catch(Throwable $e) {
  error_log($e->getMessage());flash('error','An unexpected error occurred.');
  $return='index.php?page=dashboard';
}
if ($action==='password_change' && ($_SESSION['flash'][0]??'')==='error') {
  $_SESSION['password_form_retry']=true;
  $return='index.php?page=dashboard&change_password=1';
}
// Restore the in-progress item modal after a save error; never retain password fields.
if ($action==='user_save' && ($_SESSION['flash'][0]??'')==='error') {
  $_SESSION['staff_form_retry']=[
    'name'=>(string)($_POST['name']??''),
    'email'=>(string)($_POST['email']??'')
  ];
  $return='index.php?page=users&add=1';
}
if ($action==='item_save' && ($_SESSION['flash'][0]??'')==='error') {
  $_SESSION['item_form_retry']=[
    'id'=>(int)($_POST['id']??0),
    'name'=>(string)($_POST['name']??''),
    'sku'=>(string)($_POST['sku']??''),
    'category_id'=>(string)($_POST['category_id']??''),
    'unit'=>(string)($_POST['unit']??''),
    'min_level'=>(string)($_POST['min_level']??'0')
  ];
  $return='index.php?page=inventory';
}
if (in_array($action,['category','category_edit','category_delete'],true)) {
  $return='index.php?page=inventory&categories=1';
  if ($action!=='category_delete' && ($_SESSION['flash'][0]??'')==='error') {
    $_SESSION['category_form_retry']=[
      'action'=>$action,
      'id'=>(int)($_POST['id']??0),
      'name'=>(string)($_POST['name']??'')
    ];
  }
}
if ($action==='stock_adjust' && ($_SESSION['flash'][0]??'')==='error') {
  $_SESSION['adjust_form_retry']=[
    'item_id'=>(int)($_POST['item_id']??0),
    'lot_id'=>(int)($_POST['lot_id']??0),
    'actual_quantity'=>(string)($_POST['actual_quantity']??''),
    'reason'=>(string)($_POST['reason']??'')
  ];
  $return='index.php?page=adjust-stock';
}
if (in_array($action,['stock_in','stock_out'],true) && ($_SESSION['flash'][0]??'')==='error') {
  $fields=['item_id','quantity','received_on','expiry_date','batch_code','reason','lot_id'];
  $_SESSION['stock_form_retry']=['action'=>$action];
  foreach ($fields as $field) $_SESSION['stock_form_retry'][$field]=(string)($_POST[$field]??'');
  $return='index.php?page='.($action==='stock_in'?'stock-in':'stock-out');
}
redirect($return);
