<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/**
 * AntonX Dummy Seeder (ONE-TIME)
 * Run:
 *   /seed_dummy.php?key=NEW_KEY&run=1
 * Optional:
 *   &force=1
 *
 * IMPORTANT:
 * - CHANGE SECRET KEY (your old one is exposed)
 * - Run once
 * - DELETE file after success
 */

// =========================
// SAFETY CONFIG
// =========================
$SECRET_KEY = '7T3R48GFWE8DG76EF8GF27F9G37F97FG';

if (!isset($_GET['run']) || $_GET['run'] !== '1') { http_response_code(400); exit("Add ?run=1\n"); }
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) { http_response_code(403); exit("Invalid key\n"); }
$FORCE = (isset($_GET['force']) && $_GET['force'] === '1');

// =========================
// LOAD APP BOOTSTRAP
// =========================
$base = __DIR__;
$paths_to_try = [$base.'/config.php', $base.'/config.local.php'];
foreach ($paths_to_try as $p) { if (file_exists($p)) { require_once $p; break; } }
if (file_exists($base.'/lib/db.php')) require_once $base.'/lib/db.php';
if (file_exists($base.'/lib/helpers.php')) require_once $base.'/lib/helpers.php';
if (file_exists($base.'/lib/auth.php')) require_once $base.'/lib/auth.php';

// =========================
// GET DB HANDLE (PDO or mysqli)
// =========================
$db = null;
if (isset($pdo)) $db = $pdo;
if (!$db && isset($conn)) $db = $conn;
if (!$db && function_exists('db')) { try { $db = db(); } catch (Throwable $e) {} }
if (!$db) { http_response_code(500); exit("Could not detect DB connection.\n"); }

// =========================
// DB HELPERS
// =========================
function is_pdo($db) { return $db instanceof PDO; }
function is_mysqli($db) { return $db instanceof mysqli; }

function q($db, $sql, $params = array()) {
  if (is_pdo($db)) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st;
  }
  if (is_mysqli($db)) {
    if (!$params) {
      $res = $db->query($sql);
      if ($res === false) throw new Exception("MySQLi error: ".$db->error);
      return $res;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception("MySQLi prepare error: ".$db->error);
    $types = '';
    $bind = array();
    foreach ($params as $v) {
      if (is_int($v)) $types .= 'i';
      elseif (is_float($v)) $types .= 'd';
      else $types .= 's';
      $bind[] = $v;
    }
    $stmt->bind_param($types, ...$bind);
    if (!$stmt->execute()) throw new Exception("MySQLi execute error: ".$stmt->error);
    return $stmt;
  }
  throw new Exception("Unknown DB type");
}

function fetch_all($db, $sql, $params = array()) {
  $r = q($db, $sql, $params);
  if (is_pdo($db)) return $r->fetchAll(PDO::FETCH_ASSOC);
  $res = $r->get_result();
  return $res ? $res->fetch_all(MYSQLI_ASSOC) : array();
}

function fetch_one($db, $sql, $params = array()) {
  $rows = fetch_all($db, $sql, $params);
  return $rows ? $rows[0] : null;
}

function last_id($db) {
  if (is_pdo($db)) return (int)$db->lastInsertId();
  if (is_mysqli($db)) return (int)$db->insert_id;
  return 0;
}

// MariaDB-safe SHOW helpers (no ? placeholders)
function table_exists($db, $table) {
  $table = str_replace(array('\\','%','_'), array('\\\\','\\%','\\_'), $table);
  $row = fetch_one($db, "SHOW TABLES LIKE '$table'");
  return $row !== null;
}

function col_exists($db, $table, $col) {
  $table = str_replace('`','', $table);
  $col = str_replace(array('\\','%','_'), array('\\\\','\\%','\\_'), $col);
  $row = fetch_one($db, "SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $row !== null;
}

function seed_now() { return date('Y-m-d H:i:s'); }

function add_workspace_if_needed($db, $table, &$cols, &$vals, $workspaceId) {
  if ($workspaceId && col_exists($db, $table, 'workspace_id')) { $cols[]='workspace_id'; $vals[]=$workspaceId; }
}

function add_timestamps_if_needed($db, $table, &$cols, &$vals) {
  // your schema uses NOT NULL created_at & updated_at in many tables
  if (col_exists($db,$table,'created_at') && !in_array('created_at',$cols)) { $cols[]='created_at'; $vals[]=seed_now(); }
  if (col_exists($db,$table,'updated_at') && !in_array('updated_at',$cols)) { $cols[]='updated_at'; $vals[]=seed_now(); }
}

function insert_row($db, $table, $cols, $vals) {
  $colSql = implode('`,`', $cols);
  $ph = implode(',', array_fill(0, count($cols), '?'));
  q($db, "INSERT INTO `$table` (`$colSql`) VALUES ($ph)", $vals);
  return last_id($db);
}

// =========================
// TRANSACTION
// =========================
try {
  if (is_pdo($db)) $db->beginTransaction();

  // Already seeded guard
  if (table_exists($db, 'clients') && !$FORCE) {
    $c = fetch_one($db, "SELECT COUNT(*) AS c FROM clients");
    if ($c && (int)$c['c'] > 5) exit("Looks already seeded (clients > 5). Add &force=1\n");
  }

  // =========================
  // 0) WORKSPACE
  // =========================
  $workspaceId = null;
  if (table_exists($db,'workspaces')) {
    $ws = fetch_one($db, "SELECT id FROM workspaces ORDER BY id ASC LIMIT 1");
    if ($ws) $workspaceId = (int)$ws['id'];
    else {
      $cols = array('name');
      $vals = array('AntonX Workspace');
      if (col_exists($db,'workspaces','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }
      $workspaceId = insert_row($db,'workspaces',$cols,$vals);
    }
  }

  // =========================
  // 0.5) ROLES
  // =========================
  $roleIds = array(); // name=>id
  if (table_exists($db,'roles')) {
    $rows = fetch_all($db, "SELECT id,name FROM roles");
    foreach ($rows as $r) $roleIds[$r['name']] = (int)$r['id'];

    $need = array('Super Admin','CTO','Developer','Finance');
    foreach ($need as $nm) {
      if (!isset($roleIds[$nm])) {
        $cols=array('name'); $vals=array($nm);
        $roleIds[$nm] = insert_row($db,'roles',$cols,$vals);
      }
    }
  }

  // =========================
  // 1) USERS (needs workspace_id + role_id + timestamps)
  // =========================
  $userIds = array();
  $adminUserId = null;

  if (table_exists($db,'users')) {
    $seedUsers = array(
      array('Super Admin','admin@example.com','Super Admin'),
      array('CTO User','cto@example.com','CTO'),
      array('Dev A','dev.a@example.com','Developer'),
      array('Dev B','dev.b@example.com','Developer'),
      array('Dev C','dev.c@example.com','Developer'),
      array('Finance User','finance@example.com','Finance'),
    );

    foreach ($seedUsers as $u) {
      $name=$u[0]; $email=$u[1]; $roleName=$u[2];

      $existing = fetch_one($db, "SELECT id FROM users WHERE email = ?", array($email));
      if ($existing) {
        $uid=(int)$existing['id'];
        $userIds[$email]=$uid;
        if ($email==='admin@example.com') $adminUserId=$uid;
        continue;
      }

      $cols=array('name','email','password_hash','role_id','is_active');
      $vals=array($name,$email,password_hash('Password123!', PASSWORD_DEFAULT), (int)$roleIds[$roleName], 1);

      add_workspace_if_needed($db,'users',$cols,$vals,$workspaceId);
      add_timestamps_if_needed($db,'users',$cols,$vals);

      $uid = insert_row($db,'users',$cols,$vals);
      $userIds[$email]=$uid;
      if ($email==='admin@example.com') $adminUserId=$uid;
    }
  }

  if (!$adminUserId && $userIds) {
    // fallback to first seeded user
    foreach ($userIds as $id) { $adminUserId = (int)$id; break; }
  }

  // =========================
  // 2) PROJECT TYPES (projects.type_id FK)
  // =========================
  $projectTypeIds=array();
  if (table_exists($db,'project_types')) {
    $rows = fetch_all($db, "SELECT id,name FROM project_types WHERE workspace_id=?", array($workspaceId));
    foreach ($rows as $r) $projectTypeIds[$r['name']] = (int)$r['id'];

    $need = array('General','SEO','Development','Design');
    $order=1;
    foreach ($need as $nm) {
      if (!isset($projectTypeIds[$nm])) {
        $cols=array('name','sort_order'); $vals=array($nm,$order++);
        add_workspace_if_needed($db,'project_types',$cols,$vals,$workspaceId);
        add_timestamps_if_needed($db,'project_types',$cols,$vals);
        $projectTypeIds[$nm] = insert_row($db,'project_types',$cols,$vals);
      }
    }
  }

  // =========================
  // 2.1) PROJECT STATUSES (projects.status_id FK)
  // =========================
  $projectStatusIds=array();
  if (table_exists($db,'project_statuses')) {
    $rows = fetch_all($db, "SELECT id,name FROM project_statuses WHERE workspace_id=?", array($workspaceId));
    foreach ($rows as $r) $projectStatusIds[$r['name']] = (int)$r['id'];

    $need = array('Active','On Hold','Completed','Cancelled');
    $order=1;
    foreach ($need as $nm) {
      if (!isset($projectStatusIds[$nm])) {
        $cols=array('name','sort_order'); $vals=array($nm,$order++);
        add_workspace_if_needed($db,'project_statuses',$cols,$vals,$workspaceId);
        add_timestamps_if_needed($db,'project_statuses',$cols,$vals);
        $projectStatusIds[$nm] = insert_row($db,'project_statuses',$cols,$vals);
      }
    }
  }

  // =========================
  // 3) CLIENTS (clients.workspace_id FK)
  // =========================
  $clientIds=array();
  if (table_exists($db,'clients')) {
    $clients = array('SpeedX Marketing','Nova Retail','BluePeak Logistics','KiteWorks SaaS','GreenLeaf Foods','Atlas Builders','Sunrise Clinics','PixelForge Studio','ZenFit Gym','Orchid Education');

    foreach ($clients as $name) {
      $existing = fetch_one($db, "SELECT id FROM clients WHERE workspace_id=? AND name=?", array($workspaceId,$name));
      if ($existing) { $clientIds[]=(int)$existing['id']; continue; }

      $cols=array('name'); $vals=array($name);
      add_workspace_if_needed($db,'clients',$cols,$vals,$workspaceId);
      add_timestamps_if_needed($db,'clients',$cols,$vals);

      $clientIds[] = insert_row($db,'clients',$cols,$vals);
    }
  }

  // =========================
  // 4) PROJECTS (needs type_id + status_id + timestamps)
  // =========================
  $projectIds=array();
  if (table_exists($db,'projects') && $clientIds) {
    $projectNames = array('Website Redesign','SEO Sprint','CRM Implementation','Mobile App MVP','Brand Refresh','Analytics Setup','Landing Page Factory','Support Process Revamp','Marketing Automation','Performance Audit');

    $typeNames = array_keys($projectTypeIds);
    $statusNames = array_keys($projectStatusIds);

    foreach ($clientIds as $idx=>$cid) {
      $count = 2 + ($idx % 3);
      for ($i=0; $i<$count; $i++) {
        $pname = $projectNames[($idx+$i) % count($projectNames)] . " #".($i+1);

        $existing = fetch_one($db, "SELECT id FROM projects WHERE workspace_id=? AND client_id=? AND name=?", array($workspaceId,$cid,$pname));
        if ($existing) { $projectIds[]=(int)$existing['id']; continue; }

        $pickType = $typeNames ? $typeNames[($idx+$i) % count($typeNames)] : 'General';
        $pickStatus = $statusNames ? $statusNames[0] : 'Active';

        $cols=array('client_id','name','type_id','status_id');
        $vals=array((int)$cid, $pname, (int)$projectTypeIds[$pickType], (int)$projectStatusIds[$pickStatus]);

        add_workspace_if_needed($db,'projects',$cols,$vals,$workspaceId);
        add_timestamps_if_needed($db,'projects',$cols,$vals);

        $projectIds[] = insert_row($db,'projects',$cols,$vals);
      }
    }
  }

  // =========================
  // 5) PHASES (tasks.phase_id FK)
  // =========================
  $phaseIdsByProject=array();
  if (table_exists($db,'phases') && $projectIds) {
    $phaseNames=array('Discovery','Design','Development','QA','Launch');

    foreach ($projectIds as $pid) {
      $phaseIdsByProject[$pid]=array();
      $order=1;
      foreach ($phaseNames as $phName) {
        $existing = fetch_one($db, "SELECT id FROM phases WHERE workspace_id=? AND project_id=? AND name=?", array($workspaceId,$pid,$phName));
        if ($existing) { $phaseIdsByProject[$pid][]=(int)$existing['id']; $order++; continue; }

        $cols=array('project_id','name','sort_order');
        $vals=array((int)$pid, $phName, $order++);
        add_workspace_if_needed($db,'phases',$cols,$vals,$workspaceId);
        add_timestamps_if_needed($db,'phases',$cols,$vals);

        $phaseIdsByProject[$pid][] = insert_row($db,'phases',$cols,$vals);
      }
    }
  }

  // =========================
  // 6) TASKS (needs phase_id + created_by + timestamps)
  // =========================
  $taskIds=array();
  if (table_exists($db,'tasks') && $projectIds) {
    $taskTitles=array('Set up project structure','Create UI components','Implement authentication','Fix dashboard stats','Build search results page','Add threaded comments','Implement finance totals','QA pass and bugfix','Prepare client review','Deploy updates');
    $statuses=array('To Do','In Progress','Blocked','Done');

    foreach ($projectIds as $pid) {
      $phases = isset($phaseIdsByProject[$pid]) ? $phaseIdsByProject[$pid] : array();
      if (!$phases) continue;

      $taskCount = 10 + ($pid % 8);
      for ($i=0; $i<$taskCount; $i++) {
        $title = $taskTitles[$i % count($taskTitles)]." (".($i+1).")";
        $phaseId = $phases[$i % count($phases)];

        $existing = fetch_one($db, "SELECT id FROM tasks WHERE workspace_id=? AND project_id=? AND phase_id=? AND title=?", array($workspaceId,$pid,$phaseId,$title));
        if ($existing) { $taskIds[]=(int)$existing['id']; continue; }

        $cols=array('project_id','phase_id','title','status','created_by');
        $vals=array((int)$pid,(int)$phaseId,$title,$statuses[$i % count($statuses)], (int)$adminUserId);

        add_workspace_if_needed($db,'tasks',$cols,$vals,$workspaceId);
        add_timestamps_if_needed($db,'tasks',$cols,$vals);

        $taskIds[] = insert_row($db,'tasks',$cols,$vals);
      }
    }
  }

  if (is_pdo($db)) $db->commit();

  echo "✅ Dummy seed completed.\n\n";
  echo "Logins:\n";
  echo "- admin@example.com / Password123!\n";
  echo "- cto@example.com / Password123!\n";
  echo "- dev.a@example.com / Password123!\n";
  echo "- finance@example.com / Password123!\n\n";
  echo "IMPORTANT: DELETE seed_dummy.php now.\n";

} catch (Throwable $e) {
  if (is_pdo($db) && $db->inTransaction()) $db->rollBack();
  http_response_code(500);
  echo "❌ Seeder failed: " . $e->getMessage() . "\n";
}
