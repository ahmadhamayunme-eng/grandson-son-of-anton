<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function crud_handle_simple_table(PDO $pdo, string $table, array $fields, int $workspace_id, string $redirect_url): void {
  // create
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='create') {
    $cols = ['workspace_id'];
    $vals = [$workspace_id];
    foreach ($fields as $f) {
      $cols[] = $f;
      $vals[] = trim((string)($_POST[$f] ?? ''));
    }
    $place = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `$table`(".implode(',',$cols).",created_at,updated_at) VALUES ($place,NOW(),NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
    flash_set('success', 'Created.');
    redirect($redirect_url);
  }
  // update
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='update') {
    $id = (int)($_POST['id'] ?? 0);
    $sets=[]; $vals=[];
    foreach ($fields as $f) {
      $sets[] = "$f=?";
      $vals[] = trim((string)($_POST[$f] ?? ''));
    }
    $vals[] = $workspace_id;
    $vals[] = $id;
    $sql = "UPDATE `$table` SET ".implode(',',$sets).", updated_at=NOW() WHERE workspace_id=? AND id=?";
    $pdo->prepare($sql)->execute($vals);
    flash_set('success', 'Updated.');
    redirect($redirect_url);
  }
  // delete
  if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM `$table` WHERE workspace_id=? AND id=?")->execute([$workspace_id,$id]);
    flash_set('success', 'Deleted.');
    redirect($redirect_url);
  }
}

function crud_fetch_rows(PDO $pdo, string $table, int $workspace_id): array {
  $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE workspace_id=? ORDER BY id DESC");
  $stmt->execute([$workspace_id]);
  return $stmt->fetchAll();
}
