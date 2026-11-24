<?php
/**
 * OPTIONAL local starter data for practicing an inventory workflow.
 * Values and dates are illustrative; do not treat them as official Bean There Cafe records.
 */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/../app/inventory_logic.php';
if (DB_NAME!=='bean_there_inventory') {
    fwrite(STDERR,"Only the named local prototype database is supported.\n"); exit(1);
}
$owner=one("SELECT id FROM users WHERE role='owner' AND active=1 LIMIT 1");
if (!$owner) {fwrite(STDERR,"Create Owner account before using starter data.\n");exit(1);}

$systemEmail='inventory.setup@inventory.local.invalid';
$systemUser=one('SELECT id FROM users WHERE email=?',[$systemEmail]);
if (!$systemUser) {
    query("INSERT INTO users(full_name,email,password_hash,role,active) VALUES (?,?,?,'staff',0)",[
      'Inventory Setup',$systemEmail,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $systemUser=['id'=>(int)db()->lastInsertId()];
}
$userId=(int)$systemUser['id'];
$categories=[];
foreach(all('SELECT id,name FROM categories') as $category)
    $categories[$category['name']]=(int)$category['id'];
$items=[
 ['MILK-001','Fresh Milk','Milk & dairy','L',5,[[10,-2,5],[5,-1,12],[1.5,-14,-2]],3],
 ['COFFEE-001','Arabica Coffee Beans','Coffee beans','kg',2,[[6,-5,90]],1],
 ['MATCHA-001','Matcha Powder','Tea & powders','kg',1,[[2,-6,80]],0.4],
 ['CARAMEL-001','Caramel Syrup','Syrups & sauces','L',2,[[2.5,-4,80]],1],
 ['STRAWBERRY-001','Strawberry Puree','Syrups & sauces','L',1,[],0],
 ['OREO-001','Oreo Cookies','Food ingredients','packs',4,[[7,-4,70]],3],
 ['CUPS-001','Paper Cups 16 oz','Cups & packaging','pcs',50,[[120,-6,null]],20],
 ['CHICKEN-001','Chicken Fillet','Food ingredients','kg',2,[],0],
];
$created=0;$skipped=0;
foreach($items as [$sku,$name,$cat,$unit,$min,$batches,$used]){
    if (one('SELECT id FROM items WHERE sku=?',[$sku])) {$skipped++;continue;}
    if (!isset($categories[$cat])) throw new RuntimeException("Category not found: $cat");
    query('INSERT INTO items(sku,name,category_id,unit,min_level) VALUES (?,?,?,?,?)',[
      $sku,$name,$categories[$cat],$unit,$min
    ]);
    $itemId=(int)db()->lastInsertId();
    foreach($batches as $i=>$batch) {
      [$amount,$receivedDays,$expiryDays]=$batch;
      $received=date('Y-m-d',strtotime(($receivedDays>=0?'+':'').$receivedDays.' days'));
      $expiry=$expiryDays===null?null:date('Y-m-d',strtotime(($expiryDays>=0?'+':'').$expiryDays.' days'));
      stock_in($itemId,(float)$amount,$received,$expiry,$sku.'-B'.($i+1),
          'Initial stock entry',$userId);
    }
    if($used>0) stock_out($itemId,(float)$used,'Used / consumed',$userId);
    $created++;
}
echo "Added $created starter inventory items; $skipped already existed.\n";
