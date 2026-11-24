<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') {
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('X-Frame-Options: DENY');
}
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (session_status() !== PHP_SESSION_ACTIVE) {
  // Isolate sessions when multiple inventory installations share localhost.
  $appPath=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/');
  if ($appPath==='.') $appPath='';
  $cookiePath=$appPath===''?'/':$appPath.'/';
  session_name('btc_'.substr(hash('sha256',$cookiePath),0,12));
  session_set_cookie_params(['lifetime'=>0,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
  session_start();
}
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
function base_url(string $path=''): string {
  $base=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php'));
  if ($base==='/' || $base==='.') $base='';
  return $base.'/'.ltrim($path,'/');
}
function redirect(string $route='index.php?page=dashboard'): never {
  header('Location: '.base_url($route),true,303); exit;
}
function db(bool $database=true): PDO {
  static $cache=[];
  $k=$database?'db':'server';
  if (!isset($cache[$k])) {
    $dsn='mysql:host='.DB_HOST.';port='.DB_PORT.($database?';dbname='.DB_NAME:'').';charset=utf8mb4';
    $cache[$k]=new PDO($dsn, DB_USER, DB_PASS,[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES=>false]);
  }
  return $cache[$k];
}
function e(?string $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf_field(): string {return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function require_csrf(): void {
  if (!hash_equals($_SESSION['csrf']??'',(string)($_POST['csrf']??''))) {
    http_response_code(419);exit('Security token expired. Reload and try again.');
  }
}
function flash(string $kind,string $message): void {$_SESSION['flash']=[$kind,$message];}
function take_flash(): ?array {$v=$_SESSION['flash']??null;unset($_SESSION['flash']);return $v;}
function current_user(): ?array {return $_SESSION['user']??null;}
function require_login(): void {
  static $validated=false;
  if (!current_user()) redirect('index.php?page=login');
  if (time()-(int)($_SESSION['last_seen']??0)>3600) {
    $_SESSION=[];session_regenerate_id(true);
    flash('error','Your session expired. Sign in again.');
    redirect('index.php?page=login');
  }
  if (!$validated) {
    // Apply disabled-account and role changes to POST actions as well as pages.
    $id=(int)(current_user()['id']??0);
    $live=$id>0?one('SELECT id,full_name,email,role,active,password_hash FROM users WHERE id=?',[$id]):null;
    if (!$live || !(int)$live['active'] || !in_array($live['role'],['owner','staff'],true)
        || !hash_equals((string)($_SESSION['auth_fingerprint']??''),hash('sha256',(string)$live['password_hash']))) {
      $_SESSION=[];session_regenerate_id(true);
      flash('error','Your account is inactive or unavailable. Contact the owner.');
      redirect('index.php?page=login');
    }
    $_SESSION['user']=['id'=>(int)$live['id'],'name'=>$live['full_name'],'email'=>$live['email'],'role'=>$live['role']];
    $validated=true;
  }
  $_SESSION['last_seen']=time();
}
function require_owner(): void {
  require_login();
  if (current_user()['role']!=='owner') {http_response_code(403);exit('Not authorized.');}
}
function query(string $sql,array $args=[]): PDOStatement {
  $st=db()->prepare($sql);$st->execute($args);return $st;
}
function one(string $sql,array $args=[]): ?array {$r=query($sql,$args)->fetch();return $r?:null;}
function all(string $sql,array $args=[]): array {return query($sql,$args)->fetchAll();}
function valid_date(?string $s): bool {
  if (!$s) return false;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
  return $d!==false && $d->format('Y-m-d')===$s;
}
function qty_value(mixed $v): float {
  $s=trim((string)$v);
  if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$s))
    throw new InvalidArgumentException('Enter a positive quantity with up to 3 decimal places.');
  $n=round((float)$s,3);
  if ($n<=0 || $n>999999999) throw new InvalidArgumentException('Quantity must be greater than zero.');
  return $n;
}
function nice_qty(mixed $v): string {return rtrim(rtrim(number_format((float)$v,3,'.',','),'0'),'.');}
function stock_totals(): array {
  return all("SELECT i.id,i.sku,i.name,i.unit,i.min_level,i.is_active,i.category_id,
    c.name category,COUNT(l.id) batch_count,COALESCE(SUM(l.qty_on_hand),0) total,
    COALESCE(SUM(CASE WHEN l.expiry_date IS NULL OR l.expiry_date>=CURDATE() THEN l.qty_on_hand ELSE 0 END),0) usable,
    COALESCE(SUM(CASE WHEN l.expiry_date<CURDATE() THEN l.qty_on_hand ELSE 0 END),0) expired
    FROM items i LEFT JOIN categories c ON i.category_id=c.id
    LEFT JOIN lots l ON l.item_id=i.id GROUP BY i.id ORDER BY i.name");
}
/**
 * Distinguish zero available stock from low stock; expired batches never count as usable.
 * Returned values: key, label and badge theme.
 */
function inventory_status(array $item): array {
  if (!(int)$item['is_active']) return ['inactive', 'Archived', 'neutral'];
  $usable=round((float)$item['usable'],3);
  $minimum=round((float)$item['min_level'],3);
  if ($usable<=0) return ['out','Out of Stock','danger'];
  if ($usable<=$minimum) return ['low','Low Stock','warning'];
  return ['available','In Stock','success'];
}
function item_row(int $id): array {
  $r=one('SELECT * FROM items WHERE id=? AND is_active=1',[$id]);
  if (!$r) throw new InvalidArgumentException('Ingredient or supply not found.');
  return $r;
}
function lock_active_item(int $id): array {
  $item=one('SELECT * FROM items WHERE id=? FOR UPDATE',[$id]);
  if (!$item || !(int)$item['is_active']) throw new InvalidArgumentException('Ingredient or supply not found.');
  return $item;
}
function setting(string $key,string $fallback=''): string {
  $r=one('SELECT setting_value FROM settings WHERE setting_key=?',[$key]);
  return $r['setting_value']??$fallback;
}
function badge(string $text,string $kind='neutral'): string {
  return '<span class="badge badge-'.e($kind).'">'.e($text).'</span>';
}
