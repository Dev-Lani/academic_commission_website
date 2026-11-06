<?php
// Load-test data. Run from the command line in the project folder:
//   php seed.php            creates 100 clients, 100 commissioners, 300 posts, offers, and users.csv
//   php seed.php --reset    deletes everything it created (all usernames starting with load_)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
date_default_timezone_set('Asia/Manila');
$pdo = new PDO('mysql:host=localhost;dbname=acw;charset=utf8mb4', 'root', '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

if (in_array('--reset', $argv, true)) {
  $n = $pdo->exec("DELETE FROM users WHERE username LIKE 'load\\_%'"); // posts, offers, orders cascade
  echo "Removed $n test users and their data.\n"; exit;
}
if ($pdo->query("SELECT COUNT(*) FROM users WHERE username='load_c1'")->fetchColumn()) {
  exit("Seed data already exists. Run: php seed.php --reset\n");
}

const PER_ROLE = 100;
const POSTS = 300;
const PASSWORD = 'LoadTest123!';
$hash  = password_hash(PASSWORD, PASSWORD_DEFAULT); // one hash is enough for all test accounts
$types = ['Essay','Research paper','Thesis chapter','Editing / proofreading','Reflection paper','Case study','Report','Problem set'];
$subjects = $pdo->query('SELECT id FROM subjects')->fetchAll(PDO::FETCH_COLUMN);

$pdo->beginTransaction();
$u = $pdo->prepare('INSERT INTO users (username,email,password_hash,role,tier,is_verified) VALUES (?,?,?,?,?,1)');
$csv = fopen('users.csv', 'w');
$clients = []; $comms = [];
for ($i = 1; $i <= PER_ROLE; $i++) {
  $u->execute(["load_c$i", "load_c$i@test.local", $hash, 'client', 'New']);
  $clients[] = (int)$pdo->lastInsertId(); fputcsv($csv, ["load_c$i", PASSWORD]);
  $u->execute(["load_m$i", "load_m$i@test.local", $hash, 'commissioner', 'Bronze']);
  $comms[] = (int)$pdo->lastInsertId(); fputcsv($csv, ["load_m$i", PASSWORD]);
}
fclose($csv);

$p = $pdo->prepare('INSERT INTO posts (user_id,subject_id,title,type,pages,budget_min,budget_max,deadline,description,score) VALUES (?,?,?,?,?,?,?,?,?,?)');
$o = $pdo->prepare('INSERT INTO offers (post_id,user_id,price,deliver_by,message) VALUES (?,?,?,?,?)');
$first = $last = 0;
for ($i = 1; $i <= POSTS; $i++) {
  $min = random_int(200, 1000);
  $dl  = time() + random_int(2, 30) * 86400;
  $p->execute([$clients[array_rand($clients)], $subjects[array_rand($subjects)], "Load test request $i", $types[array_rand($types)],
               random_int(1, 30), $min, $min + random_int(0, 2000), date('Y-m-d H:i:s', $dl),
               'Load test request. ' . str_repeat('Sample requirement text. ', 8), random_int(-5, 20)]);
  $id = (int)$pdo->lastInsertId();
  $first = $first ?: $id; $last = $id;
  if ($i <= 100) { // offers on the first 100 posts
    foreach ((array)array_rand($comms, random_int(3, 8)) as $k) {
      $o->execute([$id, $comms[$k], random_int(200, 3000), date('Y-m-d H:i:s', $dl - 86400), 'Load test offer from the seed script.']);
    }
  }
}
$pdo->commit();
echo "Done. " . (2 * PER_ROLE) . " users (password " . PASSWORD . "), " . POSTS . " posts (ids $first to $last), offers on the first 100.\n";
echo "users.csv written. Use -JpostMin=$first -JpostMax=$last in JMeter.\n";