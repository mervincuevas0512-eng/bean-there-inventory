<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Receive stock into a dated lot; edits to a lot use the same reference and dates. */
function stock_in(int $itemId,float $quantity,string $received,?string $expiry,string $batch,string $reason,int $userId,bool $adjust=false): void {
  if ($quantity<=0) throw new InvalidArgumentException('Quantity must be greater than zero.');
  if (!valid_date($received)) throw new InvalidArgumentException('A valid received date is required.');
  if ($received>date('Y-m-d')) throw new InvalidArgumentException('Date received cannot be in the future.');
  if ($expiry!==null && (!valid_date($expiry) || $expiry<$received))
    throw new InvalidArgumentException('Expiry must be on or after the received date.');
  $batch=trim($batch);
  if ($batch==='') $batch='BT-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
  if (mb_strlen($batch)>80) throw new InvalidArgumentException('Batch reference is too long.');
  db()->beginTransaction();
  try {
    lock_active_item($itemId);
    $existing=one('SELECT * FROM lots WHERE item_id=? AND batch_code=? FOR UPDATE',[$itemId,$batch]);
    if ($existing) {
      if ($existing['received_on']!==$received || ($existing['expiry_date']?:null)!==$expiry)
        throw new InvalidArgumentException('Batch reference exists with different dates.');
      query('UPDATE lots SET qty_on_hand=qty_on_hand+? WHERE id=?',[$quantity,$existing['id']]);
      $lotId=(int)$existing['id'];
    } else {
      query('INSERT INTO lots (item_id,batch_code,received_on,expiry_date,qty_on_hand) VALUES (?,?,?,?,?)',
        [$itemId,$batch,$received,$expiry,$quantity]);
      $lotId=(int)db()->lastInsertId();
    }
    query('INSERT INTO movements (item_id,lot_id,user_id,type,quantity,reason) VALUES (?,?,?,?,?,?)',
      [$itemId,$lotId,$userId,$adjust?'ADJUST_IN':'IN',$quantity,$reason?:null]);
    db()->commit();
  } catch(Throwable $e) { db()->rollBack();throw $e; }
}

/**
 * Consumption uses FEFO on usable batches. Waste/damage requires a concrete
 * selected batch so disposal never silently changes a different delivery.
 */
function stock_out(int $itemId,float $quantity,string $reason,int $userId,bool $adjust=false,?int $specificLotId=null): void {
  if ($quantity<=0) throw new InvalidArgumentException('Quantity must be greater than zero.');
  $reason=trim($reason);
  if ($reason==='') throw new InvalidArgumentException('Choose a reason.');
  $batchRequired=in_array($reason,['Waste / expired','Damaged'],true);
  if ($batchRequired && ($specificLotId===null || $specificLotId<=0))
    throw new InvalidArgumentException('Select the affected stock batch for waste or damage.');
  db()->beginTransaction();
  try {
    // Serialize stock changes with archive and item edits.
    lock_active_item($itemId);
    if ($batchRequired) {
      // The selected lot must belong to the selected ingredient.
      $lots=all('SELECT id,qty_on_hand,expiry_date FROM lots WHERE id=? AND item_id=? AND qty_on_hand>0 FOR UPDATE',
        [$specificLotId,$itemId]);
      if (!$lots) throw new InvalidArgumentException('The selected batch has no remaining stock for this item.');
    } else {
      $lots=all('SELECT id,qty_on_hand,expiry_date FROM lots WHERE item_id=? AND qty_on_hand>0 '.
        'AND (expiry_date IS NULL OR expiry_date>=CURDATE()) '.
        'ORDER BY (expiry_date IS NULL) ASC,expiry_date ASC,received_on ASC,id ASC FOR UPDATE',
        [$itemId]);
    }
    $available=0.0;
    foreach($lots as $lot) $available+=(float)$lot['qty_on_hand'];
    if (round($available+0.00001,3)<$quantity)
      throw new InvalidArgumentException('Insufficient '.($batchRequired?'batch':'usable').' stock. Available: '.nice_qty($available).'.');
    $left=$quantity;
    foreach($lots as $lot) {
      if ($left<=0.00001) break;
      $take=round(min($left,(float)$lot['qty_on_hand']),3);
      query('UPDATE lots SET qty_on_hand=qty_on_hand-? WHERE id=?',[$take,$lot['id']]);
      query('INSERT INTO movements (item_id,lot_id,user_id,type,quantity,reason) VALUES (?,?,?,?,?,?)',
        [$itemId,$lot['id'],$userId,$adjust?'ADJUST_OUT':'OUT',$take,$reason]);
      $left=round($left-$take,3);
    }
    db()->commit();
  } catch(Throwable $e) { db()->rollBack();throw $e; }
}


/**
 * Reconcile a physically counted batch to its actual balance. Batch-specific
 * correction keeps expiry and movement history intact, including expired lots.
 * All updates and the audit entry happen in one transaction.
 */
function reconcile_batch_stock(int $itemId,int $lotId,float $counted,string $reason,int $userId): float {
  $reason=trim($reason);
  if ($itemId<=0 || $lotId<=0) throw new InvalidArgumentException('Choose an ingredient and its stock batch.');
  if (!is_finite($counted) || $counted<0 || $counted>999999999)
    throw new InvalidArgumentException('Enter a valid actual count (zero or more).');
  if (mb_strlen($reason)<5 || mb_strlen($reason)>140)
    throw new InvalidArgumentException('Explain the difference (5 to 140 characters).');
  $counted=round($counted,3);
  db()->beginTransaction();
  try {
    // Same lock order as stock receipts and issues: item, then lot.
    lock_active_item($itemId);
    $lot=one('SELECT id,qty_on_hand FROM lots WHERE id=? AND item_id=? FOR UPDATE',[$lotId,$itemId]);
    if (!$lot) throw new InvalidArgumentException('Batch does not belong to the selected item.');
    $previous=round((float)$lot['qty_on_hand'],3);
    $change=round($counted-$previous,3);
    if (abs($change)<0.0005)
      throw new InvalidArgumentException('Actual count already matches the recorded batch quantity.');
    query('UPDATE lots SET qty_on_hand=? WHERE id=?',[$counted,$lotId]);
    query('INSERT INTO movements (item_id,lot_id,user_id,type,quantity,reason) VALUES (?,?,?,?,?,?)',
      [$itemId,$lotId,$userId,$change>0?'ADJUST_IN':'ADJUST_OUT',abs($change),'Stock count correction: '.$reason]);
    db()->commit();
    return $change;
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
}
