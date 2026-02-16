<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * AntonX Dummy Seeder (ONE-TIME)
 * - Upload to server, run once, then DELETE the file.
 *
 * Usage:
 *   https://yourdomain.com/seed_dummy.php?key=CHANGE_ME&run=1
 *
 * Safety:
 * - Requires ?run=1 and a secret key
 * - Stops if already seeded (unless force=1)
 */

session_start();

// =========================
// SAFETY CONFIG
// =========================
$SECRET_KEY = '7T3R48GFWE8DG76EF8GF27F9G37F97FG'; // <-- change this before running
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

// Try common includes used in your app
if (file_exists($base . '/lib/db.php')) require_once $base . '/lib/db.php';
if (file_exists($base . '/lib/helpers.php')) require_once $base . '/lib/helpers.php';
if (file_exists($base . '/lib/auth.php')) require_once $base . '/lib/auth.php';

// =========================
// GET DB HANDLE (PDO or mysqli)
// =========================
$db = null;

// Case 1: $pdo global
if (isset($pdo)) $db = $pdo;

// Case 2: $conn global (mysqli)
if (!$db && isset($conn)) $db = $conn;

// Case 3: db() helper returns PDO
if (!$db && function_exists('db')) {
  try { $db = db(); } catch (\Throwable $e) {}
}

// Final check
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
    // simple param binding for ? placeholders only
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
  // mysqli
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

function table_exists($db, $table) {
  $row = fetch_one($db, "SHOW TABLES LIKE ?", [$table]);
  return $row !== null;
}

function col_exists($db, $table, $col) {
  $row = fetch_one($db, "SHOW COLUMNS FROM `$table` LIKE ?", [$col]);
  return $row !== null;
}

function now() { return date('Y-m-d H:i:s'); }

// =========================
// TRANSACTION
// =========================
try {
  if (is_pdo($db)) $db->beginTransaction();

  // =========================
  // BASIC “ALREADY SEEDED” CHECK
  // =========================
  if (table_exists($db, 'clients') && !$FORCE) {
    $c = fetch_one($db, "SELECT COUNT(*) AS c FROM clients");
    if ($c && (int)$c['c'] > 5) {
      exit("Looks already seeded (clients > 5). Add &force=1 to run anyway.\n");
    }
  }

  // =========================
  // 1) USERS (if table exists)
  // =========================
  $userIds = [];
  if (table_exists($db, 'users')) {
    // detect column names
    $col_email = col_exists($db, 'users', 'email') ? 'email' : (col_exists($db,'users','username') ? 'username' : null);
    $col_name  = col_exists($db, 'users', 'name') ? 'name' : (col_exists($db,'users','full_name') ? 'full_name' : null);
    $col_pass  = col_exists($db, 'users', 'password_hash') ? 'password_hash' : (col_exists($db,'users','password') ? 'password' : null);
    $col_role  = col_exists($db, 'users', 'role') ? 'role' : (col_exists($db,'users','role_name') ? 'role_name' : null);
    $col_active = col_exists($db,'users','is_active') ? 'is_active' : (col_exists($db,'users','active') ? 'active' : null);

    if ($col_email && $col_name && $col_pass) {
      $seedUsers = [
        ['Super Admin', 'admin@example.com', 'Super Admin'],
        ['CTO User', 'cto@example.com', 'CTO'],
        ['Dev A', 'dev.a@example.com', 'Developer'],
        ['Dev B', 'dev.b@example.com', 'Developer'],
        ['Dev C', 'dev.c@example.com', 'Developer'],
        ['Finance User', 'finance@example.com', 'Finance'],
      ];

      foreach ($seedUsers as $u) {
        $name = $u[0]; $email = $u[1]; $role = $u[2];
        $existing = fetch_one($db, "SELECT id FROM users WHERE `$col_email` = ?", [$email]);
        if ($existing) { $userIds[$email] = (int)$existing['id']; continue; }

        $pass = password_hash('Password123!', PASSWORD_DEFAULT);
        $cols = [$col_name, $col_email, $col_pass];
        $vals = [$name, $email, $pass];
        if ($col_role) { $cols[] = $col_role; $vals[] = $role; }
        if ($col_active) { $cols[] = $col_active; $vals[] = 1; }
        if (col_exists($db,'users','created_at')) { $cols[]='created_at'; $vals[]=now(); }

        $colSql = implode('`,`', $cols);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q($db, "INSERT INTO users (`$colSql`) VALUES ($ph)", $vals);
        $userIds[$email] = last_id($db);
      }
    }
  }

  // Helper to pick user IDs if we need them
  $devEmails = ['dev.a@example.com','dev.b@example.com','dev.c@example.com'];
  $devIds = array_values(array_filter(array_map(fn($e)=>$userIds[$e] ?? null, $devEmails)));

  // =========================
  // 2) CLIENTS
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
      if (col_exists($db,'clients','created_at')) { $cols[]='created_at'; $vals[]=now(); }
      $colSql = implode('`,`', $cols);
      $ph = implode(',', array_fill(0, count($cols), '?'));
      q($db, "INSERT INTO clients (`$colSql`) VALUES ($ph)", $vals);
      $clientIds[] = last_id($db);
    }
  }

  // =========================
  // 3) PROJECTS
  // =========================
  $projectIds = [];
  if (table_exists($db, 'projects') && $clientIds) {
    $projectNames = [
      'Website Redesign','SEO Sprint','CRM Implementation','Mobile App MVP','Brand Refresh',
      'Analytics Setup','Landing Page Factory','Support Process Revamp','Marketing Automation','Performance Audit'
    ];
    foreach ($clientIds as $idx => $cid) {
      // 2–4 projects per client
      $count = 2 + ($idx % 3);
      for ($i=0; $i<$count; $i++) {
        $pname = $projectNames[($idx+$i) % count($projectNames)] . " #" . ($i+1);
        $existing = fetch_one($db, "SELECT id FROM projects WHERE client_id=? AND name=?", [$cid, $pname]);
        if ($existing) { $projectIds[] = (int)$existing['id']; continue; }

        $cols = ['client_id','name'];
        $vals = [$cid, $pname];

        if (col_exists($db,'projects','status')) { $cols[]='status'; $vals[] = 'Active'; }
        if (col_exists($db,'projects','type')) { $cols[]='type'; $vals[] = 'General'; }
        if (col_exists($db,'projects','created_at')) { $cols[]='created_at'; $vals[]=now(); }

        $colSql = implode('`,`', $cols);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q($db, "INSERT INTO projects (`$colSql`) VALUES ($ph)", $vals);
        $projectIds[] = last_id($db);
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
        if ($existing) { $phaseIdsByProject[$pid][]=(int)$existing['id']; continue; }

        $cols = ['project_id','name'];
        $vals = [$pid, $phName];
        if (col_exists($db,'phases','sort_order')) { $cols[]='sort_order'; $vals[]=$order+1; }
        if (col_exists($db,'phases','created_at')) { $cols[]='created_at'; $vals[]=now(); }

        $colSql = implode('`,`', $cols);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q($db, "INSERT INTO phases (`$colSql`) VALUES ($ph)", $vals);
        $phaseIdsByProject[$pid][] = last_id($db);
      }
    }
  }

  // =========================
  // 5) TASKS + ASSIGNMENTS + CTO PIPELINE FLAGS
  // =========================
  $taskIds = [];
  if (table_exists($db, 'tasks') && $projectIds) {
    $taskTitles = [
      'Set up project structure','Create UI components','Implement authentication',
      'Fix dashboard stats','Build search results page','Add threaded comments',
      'Implement finance totals','QA pass and bugfix','Prepare client review','Deploy updates'
    ];

    foreach ($projectIds as $pid) {
      $phases = $phaseIdsByProject[$pid] ?? [];
      $taskCount = 10 + ($pid % 8); // 10–17 tasks
      for ($i=0; $i<$taskCount; $i++) {
        $title = $taskTitles[$i % count($taskTitles)] . " (" . ($i+1) . ")";
        $phaseId = $phases ? $phases[$i % count($phases)] : null;

        $cols = ['project_id','title'];
        $vals = [$pid, $title];

        if ($phaseId && col_exists($db,'tasks','phase_id')) { $cols[]='phase_id'; $vals[]=$phaseId; }
        if (col_exists($db,'tasks','status')) {
          $statuses = ['Open','In Progress','Blocked','Completed'];
          $cols[]='status';
          $vals[] = $statuses[$i % count($statuses)];
        }
        if (col_exists($db,'tasks','priority')) { $cols[]='priority'; $vals[] = ['Low','Medium','High'][($i%3)]; }
        if (col_exists($db,'tasks','created_at')) { $cols[]='created_at'; $vals[]=now(); }

        // CTO pipeline (common columns)
        if (col_exists($db,'tasks','needs_cto_review')) { $cols[]='needs_cto_review'; $vals[] = ($i%7===0)?1:0; }
        if (col_exists($db,'tasks','cto_status')) { $cols[]='cto_status'; $vals[] = ($i%7===0)?'Pending':'None'; }

        $colSql = implode('`,`', $cols);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q($db, "INSERT INTO tasks (`$colSql`) VALUES ($ph)", $vals);
        $tid = last_id($db);
        $taskIds[] = $tid;

        // Assignments (if exists)
        if ($devIds && table_exists($db,'task_assignments') && col_exists($db,'task_assignments','task_id') && col_exists($db,'task_assignments','user_id')) {
          $assignee = $devIds[$i % count($devIds)];
          q($db, "INSERT INTO task_assignments (task_id,user_id) VALUES (?,?)", [$tid, $assignee]);
        } elseif ($devIds && col_exists($db,'tasks','assignee_id')) {
          // some schemas store assignee in tasks table
          q($db, "UPDATE tasks SET assignee_id=? WHERE id=?", [$devIds[$i % count($devIds)], $tid]);
        }
      }
    }
  }

  // =========================
  // 6) COMMENTS (threaded if possible)
  // =========================
  if (table_exists($db,'comments') && $taskIds) {
    $canThread = col_exists($db,'comments','parent_comment_id');
    foreach (array_slice($taskIds, 0, 60) as $idx => $tid) {
      $baseText = "Update on task #$tid: progress looks good.";
      // parent comment
      q($db, "INSERT INTO comments (task_id, content".(col_exists($db,'comments','created_at')?", created_at":"").") VALUES (?,?,?)",
        col_exists($db,'comments','created_at') ? [$tid, $baseText, now()] : [$tid, $baseText, null]
      );
      $parentId = last_id($db);

      // child reply
      if ($canThread) {
        $replyText = "Reply: noted, will update by EOD.";
        $cols = ['task_id','content','parent_comment_id'];
        $vals = [$tid, $replyText, $parentId];
        if (col_exists($db,'comments','created_at')) { $cols[]='created_at'; $vals[]=now(); }
        $colSql = implode('`,`', $cols);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        q($db, "INSERT INTO comments (`$colSql`) VALUES ($ph)", $vals);
      }
    }
  }

  // =========================
  // 7) TAGS + TASK_TAGS
  // =========================
  if (table_exists($db,'tags')) {
    $tags = ['UI','Backend','Bug','Urgent','Client','Finance','SEO','Docs','QA','Release'];
    $tagIds = [];
    foreach ($tags as $t) {
      $existing = fetch_one($db, "SELECT id FROM tags WHERE name=?", [$t]);
      if ($existing) { $tagIds[]=(int)$existing['id']; continue; }
      $cols=['name']; $vals=[$t];
      if (col_exists($db,'tags','created_at')) { $cols[]='created_at'; $vals[]=now(); }
      $colSql = implode('`,`', $cols);
      $ph = implode(',', array_fill(0, count($cols), '?'));
      q($db, "INSERT INTO tags (`$colSql`) VALUES ($ph)", $vals);
      $tagIds[] = last_id($db);
    }

    if (table_exists($db,'task_tags') && $taskIds && $tagIds) {
      foreach (array_slice($taskIds, 0, 80) as $i => $tid) {
        $t1 = $tagIds[$i % count($tagIds)];
        q($db, "INSERT IGNORE INTO task_tags (task_id, tag_id) VALUES (?,?)", [$tid, $t1]);
      }
    }
  }

  // =========================
  // 8) CUSTOM FIELDS + VALUES
  // =========================
  if (table_exists($db,'custom_fields') && table_exists($db,'custom_field_values') && $taskIds) {
    $fields = [
      ['entity'=>'task','name'=>'Estimate Hours','field_type'=>'number','options_json'=>null],
      ['entity'=>'task','name'=>'Risk Level','field_type'=>'select','options_json'=>json_encode(['Low','Medium','High'])],
      ['entity'=>'project','name'=>'Client Tier','field_type'=>'select','options_json'=>json_encode(['Standard','Premium'])],
    ];
    $fieldIds = [];
    foreach ($fields as $f) {
      $existing = fetch_one($db, "SELECT id FROM custom_fields WHERE entity=? AND name=?", [$f['entity'],$f['name']]);
      if ($existing) { $fieldIds[]=(int)$existing['id']; continue; }
      $cols=['entity','name','field_type'];
      $vals=[$f['entity'],$f['name'],$f['field_type']];
      if (col_exists($db,'custom_fields','options_json')) { $cols[]='options_json'; $vals[]=$f['options_json']; }
      if (col_exists($db,'custom_fields','created_at')) { $cols[]='created_at'; $vals[]=now(); }
      $colSql = implode('`,`', $cols);
      $ph = implode(',', array_fill(0, count($cols), '?'));
      q($db, "INSERT INTO custom_fields (`$colSql`) VALUES ($ph)", $vals);
      $fieldIds[] = last_id($db);
    }

    // values for tasks
    foreach (array_slice($taskIds,0,60) as $i => $tid) {
      // estimate hours
      q($db, "INSERT INTO custom_field_values (custom_field_id, entity_id, value_text) VALUES (?,?,?)",
        [$fieldIds[0], $tid, (string)(1 + ($i%12))]
      );
      // risk
      q($db, "INSERT INTO custom_field_values (custom_field_id, entity_id, value_text) VALUES (?,?,?)",
        [$fieldIds[1], $tid, ['Low','Medium','High'][$i%3]]
      );
    }
  }

  // =========================
  // 9) DOCS
  // =========================
  if (table_exists($db,'docs') && $projectIds) {
    foreach (array_slice($projectIds,0,20) as $i => $pid) {
      $title = "Project Notes";
      $content = "Dummy documentation for project #$pid.\n\n- Goals\n- Scope\n- Notes\n";
      $cols=['project_id','title','content'];
      $vals=[$pid,$title,$content];
      if (col_exists($db,'docs','created_at')) { $cols[]='created_at'; $vals[]=now(); }
      $colSql = implode('`,`', $cols);
      $ph = implode(',', array_fill(0, count($cols), '?'));
      q($db, "INSERT INTO docs (`$colSql`) VALUES ($ph)", $vals);
    }
  }

  // =========================
  // 10) FINANCE (payments, expenses, salaries, overhead)
  // =========================
  if (table_exists($db,'finance_payments') && $projectIds) {
    foreach (array_slice($projectIds,0,25) as $i => $pid) {
      q($db, "INSERT INTO finance_payments (project_id, amount, paid_on, note) VALUES (?,?,?,?)",
        [$pid, 500 + ($i*125), date('Y-m-d', strtotime("-".($i%20)." days")), "Dummy payment"]
      );
    }
  }
  if (table_exists($db,'finance_expenses') && $projectIds) {
    foreach (array_slice($projectIds,0,25) as $i => $pid) {
      q($db, "INSERT INTO finance_expenses (project_id, amount, spent_on, category, note) VALUES (?,?,?,?,?)",
        [$pid, 60 + ($i*15), date('Y-m-d', strtotime("-".($i%25)." days")), "Tools", "Dummy expense"]
      );
    }
  }
  if (table_exists($db,'finance_salaries')) {
    $names = ['Dev A','Dev B','Dev C','Designer','PM'];
    foreach ($names as $i => $n) {
      q($db, "INSERT INTO finance_salaries (name, amount, paid_on, note) VALUES (?,?,?,?)",
        [$n, 1200 + ($i*250), date('Y-m-d', strtotime("-".($i%10)." days")), "Dummy salary"]
      );
    }
  }
  if (table_exists($db,'finance_overheads')) {
    $items = [['Hosting',30],['Subscriptions',120],['Ads',200],['Office',150]];
    foreach ($items as $i => $it) {
      q($db, "INSERT INTO finance_overheads (name, amount, paid_on, note) VALUES (?,?,?,?)",
        [$it[0], $it[1], date('Y-m-d', strtotime("-".($i%15)." days")), "Dummy overhead"]
      );
    }
  }

  // =========================
  // 11) ACTIVITY LOG
  // =========================
  if (table_exists($db,'activity_log') && $taskIds) {
    foreach (array_slice($taskIds,0,40) as $i => $tid) {
      q($db, "INSERT INTO activity_log (entity_type, entity_id, action, details, created_at) VALUES (?,?,?,?,?)",
        ['task', $tid, 'seed', 'Dummy activity created', now()]
      );
    }
  }

  if (is_pdo($db)) $db->commit();

  echo "✅ Dummy seed completed.\n";
  echo "Login test users (if users table supported):\n";
  echo "- admin@example.com / Password123!\n";
  echo "- cto@example.com / Password123!\n";
  echo "- dev.a@example.com / Password123!\n";
  echo "- finance@example.com / Password123!\n";
  echo "\nIMPORTANT: Delete seed_dummy.php now.\n";

} catch (\Throwable $e) {
  if (is_pdo($db) && $db->inTransaction()) $db->rollBack();
  http_response_code(500);
  echo "❌ Seeder failed: " . $e->getMessage() . "\n";
}
