<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/**
 * AntonX Dummy Seeder (ONE-TIME)
 *
 * Run:
 *   /seed_dummy.php?key=YOUR_NEW_KEY&run=1
 *
 * Optional:
 *   &force=1   (runs even if it looks seeded)
 *
 * IMPORTANT:
 * - Change SECRET KEY
 * - Run once
 * - DELETE this file after success
 */

// =========================
// SAFETY CONFIG
// =========================
$SECRET_KEY = '7T3R48GFWE8DG76EF8GF27F9G37F97FG'; // CHANGE THIS NOW (your old one is exposed)

if (!isset($_GET['run']) || $_GET['run'] !== '1') {
  http_response_code(400);
  exit("Add ?run=1\n");
}
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
  http_response_code(403);
  exit("Invalid key\n");
}
$FORCE = (isset($_GET['force']) && $_GET['force'] === '1');

// =========================
// LOAD APP BOOTSTRAP
// =========================
$base = __DIR__;
$paths_to_try = [
  $base . '/config.php',
  $base . '/config.local.php',
];
foreach ($paths_to_try as $p) {
  if (file_exists($p)) { require_once $p; break; }
}
if (file_exists($base . '/lib/db.php')) require_once $base . '/lib/db.php';
if (file_exists($base . '/lib/helpers.php')) require_once $base . '/lib/helpers.php';
if (file_exists($base . '/lib/auth.php')) require_once $base . '/lib/auth.php';

// =========================
// GET DB HANDLE (PDO or mysqli)
// =========================
$db = null;
if (isset($pdo)) $db = $pdo;
if (!$db && isset($conn)) $db = $conn;
if (!$db && function_exists('db')) {
  try { $db = db(); } catch (Throwable $e) {}
}
if (!$db) {
  http_response_code(500);
  exit("Could not detect DB connection. Ensure lib/db.php exposes \$pdo or \$conn or db().\n");
}

// =========================
// DB HELPERS (PDO + mysqli)
// =========================
function is_pdo($db) { return $db instanceof PDO; }
function is_mysqli($db) { return $db instanceof mysqli; }

function q($db, $sql, $params = []) {
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
    $bind = [];
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

function fetch_all($db, $sql, $params = []) {
  $r = q($db, $sql, $params);
  if (is_pdo($db)) return $r->fetchAll(PDO::FETCH_ASSOC);
  $res = $r->get_result();
  return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function fetch_one($db, $sql, $params = []) {
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
  $table = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
  $row = fetch_one($db, "SHOW TABLES LIKE '$table'");
  return $row !== null;
}

function col_exists($db, $table, $col) {
  $table = str_replace('`','', $table);
  $col = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $col);
  $row = fetch_one($db, "SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $row !== null;
}

function seed_now() { return date('Y-m-d H:i:s'); }

function add_workspace_if_needed($db, $table, &$cols, &$vals, $workspaceId) {
  if ($workspaceId && col_exists($db, $table, 'workspace_id')) {
    $cols[] = 'workspace_id';
    $vals[] = $workspaceId;
  }
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

  // Basic already-seeded check
  if (table_exists($db, 'clients') && !$FORCE) {
    $c = fetch_one($db, "SELECT COUNT(*) AS c FROM clients");
    if ($c && (int)$c['c'] > 5) {
      exit("Looks already seeded (clients > 5). Add &force=1 to run anyway.\n");
    }
  }

  // =========================
  // 0) WORKSPACE
  // =========================
  $workspaceId = null;
  if (table_exists($db, 'workspaces')) {
    $ws = fetch_one($db, "SELECT id FROM workspaces ORDER BY id ASC LIMIT 1");
    if ($ws) {
      $workspaceId = (int)$ws['id'];
    } else {
      $cols = ['name'];
      $vals = ['AntonX Workspace'];
      if (col_exists($db,'workspaces','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }
      $workspaceId = insert_row($db, 'workspaces', $cols, $vals);
    }
  }

  // =========================
  // 0.5) ROLES (for users.role_id FK)
  // =========================
  $roleIds = []; // role name => id
  if (table_exists($db, 'roles')) {
    $existingRoles = fetch_all($db, "SELECT id, name FROM roles");
    foreach ($existingRoles as $r) $roleIds[$r['name']] = (int)$r['id'];

    $need = ['Super Admin', 'CTO', 'Developer', 'Finance'];
    foreach ($need as $rn) {
      if (!isset($roleIds[$rn])) {
        $cols = ['name'];
        $vals = [$rn];
        add_workspace_if_needed($db, 'roles', $cols, $vals, $workspaceId);
        if (col_exists($db,'roles','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }
        $roleIds[$rn] = insert_row($db, 'roles', $cols, $vals);
      }
    }
  }

  // =========================
  // 1) USERS
  // =========================
  $userIds = [];
  if (table_exists($db, 'users')) {
    $col_email  = col_exists($db,'users','email') ? 'email' : (col_exists($db,'users','username') ? 'username' : null);
    $col_name   = col_exists($db,'users','name') ? 'name' : (col_exists($db,'users','full_name') ? 'full_name' : null);
    $col_pass   = col_exists($db,'users','password_hash') ? 'password_hash' : (col_exists($db,'users','password') ? 'password' : null);
    $col_role   = col_exists($db,'users','role') ? 'role' : (col_exists($db,'users','role_name') ? 'role_name' : null);
    $col_active = col_exists($db,'users','is_active') ? 'is_active' : (col_exists($db,'users','active') ? 'active' : null);

    if ($col_email && $col_name && $col_pass) {
      $seedUsers = [
        ['Super Admin', 'admin@example.com', 'Super Admin'],
        ['CTO User',    'cto@example.com',   'CTO'],
        ['Dev A',       'dev.a@example.com', 'Developer'],
        ['Dev B',       'dev.b@example.com', 'Developer'],
        ['Dev C',       'dev.c@example.com', 'Developer'],
        ['Finance User','finance@example.com','Finance'],
      ];

      foreach ($seedUsers as $u) {
        $name = $u[0]; $email = $u[1]; $roleName = $u[2];

        $existing = fetch_one($db, "SELECT id FROM users WHERE `$col_email` = ?", [$email]);
        if ($existing) { $userIds[$email] = (int)$existing['id']; continue; }

        $passHash = password_hash('Password123!', PASSWORD_DEFAULT);

        $cols = [$col_name, $col_email, $col_pass];
        $vals = [$name, $email, $passHash];

        // if schema stores text role and does NOT use role_id
        if ($col_role && !col_exists($db,'users','role_id')) {
          $cols[] = $col_role; $vals[] = $roleName;
        }

        if ($col_active) { $cols[] = $col_active; $vals[] = 1; }
        add_workspace_if_needed($db, 'users', $cols, $vals, $workspaceId);

        // role_id FK
        if (col_exists($db,'users','role_id')) {
          $rid = isset($roleIds[$roleName]) ? $roleIds[$roleName] : null;
          if ($rid) { $cols[] = 'role_id'; $vals[] = $rid; }
        }

        if (col_exists($db,'users','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }

        $uid = insert_row($db, 'users', $cols, $vals);
        $userIds[$email] = $uid;
      }
    }
  }

  // dev IDs (no fn() arrow funcs)
  $devEmails = ['dev.a@example.com','dev.b@example.com','dev.c@example.com'];
  $devIds = [];
  foreach ($devEmails as $e) { if (isset($userIds[$e])) $devIds[] = (int)$userIds[$e]; }
  $devIds = array_values(array_filter($devIds));

  // =========================
  // 2) CLIENTS (workspace FK!)
  // =========================
  $clientIds = [];
  if (table_exists($db, 'clients')) {
    $clients = [
      'SpeedX Marketing','Nova Retail','BluePeak Logistics','KiteWorks SaaS','GreenLeaf Foods',
      'Atlas Builders','Sunrise Clinics','PixelForge Studio','ZenFit Gym','Orchid Education'
    ];
    foreach ($clients as $name) {
      $existing = fetch_one($db, "SELECT id FROM clients WHERE name = ?", [$name]);
      if ($existing) { $clientIds[] = (int)$existing['id']; continue; }

      $cols = ['name'];
      $vals = [$name];
      add_workspace_if_needed($db, 'clients', $cols, $vals, $workspaceId);
      if (col_exists($db,'clients','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }

      $clientIds[] = insert_row($db, 'clients', $cols, $vals);
    }
  }

// =========================
// 2.5) PROJECT TYPES (required for projects.type_id FK)
// =========================
$projectTypeIds = [];

if (table_exists($db, 'project_types')) {
  // load existing
  $rows = fetch_all($db, "SELECT id, name FROM project_types");
  foreach ($rows as $r) {
    $projectTypeIds[$r['name']] = (int)$r['id'];
  }

  // ensure at least 1 exists
  $need = ['General', 'SEO', 'Development', 'Design'];
  foreach ($need as $nm) {
    if (!isset($projectTypeIds[$nm])) {
      $cols = ['name'];
      $vals = [$nm];
      add_workspace_if_needed($db, 'project_types', $cols, $vals, $workspaceId);
      if (col_exists($db,'project_types','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }
      $projectTypeIds[$nm] = insert_row($db, 'project_types', $cols, $vals);
    }
  }
}
  
  // =========================
  // 3) PROJECTS (often workspace FK too)
  // =========================
  $projectIds = [];
  if (table_exists($db, 'projects') && $clientIds) {
    $projectNames = [
      'Website Redesign','SEO Sprint','CRM Implementation','Mobile App MVP','Brand Refresh',
      'Analytics Setup','Landing Page Factory','Support Process Revamp','Marketing Automation','Performance Audit'
    ];
    foreach ($clientIds as $idx => $cid) {
      $count = 2 + ($idx % 3);
      for ($i=0; $i<$count; $i++) {
        $pname = $projectNames[($idx+$i) % count($projectNames)] . " #" . ($i+1);
        $existing = fetch_one($db, "SELECT id FROM projects WHERE client_id=? AND name=?", [$cid, $pname]);
        if ($existing) { $projectIds[] = (int)$existing['id']; continue; }

        $cols = ['client_id','name'];
        $vals = [$cid, $pname];
// type_id FK if required
if (col_exists($db,'projects','type_id')) {
  // pick a type deterministically
  $typeNames = array_keys($projectTypeIds);
  $pick = $typeNames ? $typeNames[($idx + $i) % count($typeNames)] : null;
  $tid = $pick ? $projectTypeIds[$pick] : null;

  if ($tid) {
    $cols[] = 'type_id';
    $vals[] = $tid;
  }
}

        add_workspace_if_needed($db, 'projects', $cols, $vals, $workspaceId);
        if (col_exists($db,'projects','status')) { $cols[]='status'; $vals[]='Active'; }
        if (col_exists($db,'projects','type')) { $cols[]='type'; $vals[]='General'; }
        if (col_exists($db,'projects','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }

        $projectIds[] = insert_row($db, 'projects', $cols, $vals);
      }
    }
  }

  // =========================
  // 4) PHASES
  // =========================
  $phaseIdsByProject = [];
  if (table_exists($db, 'phases') && $projectIds) {
    $phaseNames = ['Discovery','Design','Development','QA','Launch'];
    foreach ($projectIds as $pid) {
      $phaseIdsByProject[$pid] = [];
      foreach ($phaseNames as $order => $phName) {
        $existing = fetch_one($db, "SELECT id FROM phases WHERE project_id=? AND name=?", [$pid, $phName]);
        if ($existing) { $phaseIdsByProject[$pid][] = (int)$existing['id']; continue; }

        $cols = ['project_id','name'];
        $vals = [$pid, $phName];

        add_workspace_if_needed($db, 'phases', $cols, $vals, $workspaceId);
        if (col_exists($db,'phases','sort_order')) { $cols[]='sort_order'; $vals[]=$order+1; }
        if (col_exists($db,'phases','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }

        $phaseIdsByProject[$pid][] = insert_row($db, 'phases', $cols, $vals);
      }
    }
  }

  // =========================
  // 5) TASKS
  // =========================
  $taskIds = [];
  if (table_exists($db, 'tasks') && $projectIds) {
    $taskTitles = [
      'Set up project structure','Create UI components','Implement authentication',
      'Fix dashboard stats','Build search results page','Add threaded comments',
      'Implement finance totals','QA pass and bugfix','Prepare client review','Deploy updates'
    ];

    foreach ($projectIds as $pid) {
      $phases = isset($phaseIdsByProject[$pid]) ? $phaseIdsByProject[$pid] : [];
      $taskCount = 10 + ($pid % 8);

      for ($i=0; $i<$taskCount; $i++) {
        $title = $taskTitles[$i % count($taskTitles)] . " (" . ($i+1) . ")";
        $phaseId = $phases ? $phases[$i % count($phases)] : null;

        $cols = ['project_id','title'];
        $vals = [$pid, $title];

        add_workspace_if_needed($db, 'tasks', $cols, $vals, $workspaceId);

        if ($phaseId && col_exists($db,'tasks','phase_id')) { $cols[]='phase_id'; $vals[]=$phaseId; }
        if (col_exists($db,'tasks','status')) {
          $statuses = ['Open','In Progress','Blocked','Completed'];
          $cols[]='status'; $vals[]=$statuses[$i % count($statuses)];
        }
        if (col_exists($db,'tasks','priority')) { $cols[]='priority'; $vals[]=['Low','Medium','High'][($i%3)]; }
        if (col_exists($db,'tasks','created_at')) { $cols[]='created_at'; $vals[]=seed_now(); }
        if (col_exists($db,'tasks','needs_cto_review')) { $cols[]='needs_cto_review'; $vals[]=($i%7===0)?1:0; }
        if (col_exists($db,'tasks','cto_status')) { $cols[]='cto_status'; $vals[]=($i%7===0)?'Pending':'None'; }

        $tid = insert_row($db, 'tasks', $cols, $vals);
        $taskIds[] = $tid;

        // assignments
        if ($devIds && table_exists($db,'task_assignments') && col_exists($db,'task_assignments','task_id') && col_exists($db,'task_assignments','user_id')) {
          $assignee = $devIds[$i % count($devIds)];
          $colsA = ['task_id','user_id'];
          $valsA = [$tid, $assignee];
          add_workspace_if_needed($db, 'task_assignments', $colsA, $valsA, $workspaceId);
          insert_row($db, 'task_assignments', $colsA, $valsA);
        } elseif ($devIds && col_exists($db,'tasks','assignee_id')) {
          q($db, "UPDATE tasks SET assignee_id=? WHERE id=?", [$devIds[$i % count($devIds)], $tid]);
        }
      }
    }
  }

  // (Remaining modules: comments/tags/docs/finance/activity)
  // For your current blocker (clients workspace FK), the fixes above are the key.
  // You can keep the rest of your existing logic below, but ideally also add:
  // add_workspace_if_needed(...) on inserts if those tables have workspace_id.

  if (is_pdo($db)) $db->commit();

  echo "✅ Dummy seed completed.\n";
  echo "Test logins (if users inserted):\n";
  echo "- admin@example.com / Password123!\n";
  echo "- cto@example.com / Password123!\n";
  echo "- dev.a@example.com / Password123!\n";
  echo "- finance@example.com / Password123!\n";
  echo "\nIMPORTANT: Delete seed_dummy.php now.\n";

} catch (Throwable $e) {
  if (is_pdo($db) && $db->inTransaction()) $db->rollBack();
  http_response_code(500);
  echo "❌ Seeder failed: " . $e->getMessage() . "\n";
}

