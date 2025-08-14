<?php
// form.php
mb_internal_encoding('UTF-8');

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'student_data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0755, true); }

function json_response($arr){
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_filename($filename){
  $base = pathinfo($filename, PATHINFO_FILENAME);
  $parts = preg_split('/_+/', $base);
  if (count($parts) < 3) return null;
  $roll = array_pop($parts);
  $class = array_pop($parts);
  $name = implode(' ', $parts); // আন্ডারস্কোর -> স্পেস
  return ['name'=>trim($name), 'class'=>trim($class), 'roll'=>trim($roll)];
}

function ci_equal($a, $b){
  if (function_exists('mb_strtolower')) return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
  return strtolower($a) === strtolower($b);
}
function ci_contains($haystack, $needle){
  if ($needle === '') return true;
  if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
  return stripos($haystack, $needle) !== false;
}
function norm_space($s){ return trim(preg_replace('/\s+/u', ' ', $s)); }

// Unicode-safe filename sanitize: letters, numbers, space, hyphen; space->underscore
function sanitize_segment($s){
  $s = trim($s);
  $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
  $s = preg_replace('/\s+/u', '_', $s);
  $s = preg_replace('/_+/', '_', $s);
  return $s;
}
function normalize_class($c){
  $c = trim($c);
  $cL = function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
  $map = [
    'play'=>'Play', 'nursery'=>'Nursery', 'kg'=>'Kg',
    '1'=>'1','2'=>'2','3'=>'3','4'=>'4','5'=>'5','6'=>'6','7'=>'7','8'=>'8','9'=>'9','10'=>'10'
  ];
  if (isset($map[$cL])) return $map[$cL];
  if (ctype_digit($c) && (int)$c>=1 && (int)$c<=10) return (string)((int)$c);
  return null;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// ---------- LIST ----------
if ($action === 'list' && $method === 'GET') {
  $search = isset($_GET['search']) ? trim($_GET['search']) : '';
  $classFilter = isset($_GET['class']) ? trim($_GET['class']) : 'All';
  $rollFilter  = isset($_GET['roll'])  ? trim($_GET['roll'])  : 'All';

  $files = glob($DATA_DIR . DIRECTORY_SEPARATOR . '*.txt');
  $students = [];

  foreach ($files as $file) {
    $fn = basename($file);
    $p = parse_filename($fn);
    if (!$p) continue;

    $name = $p['name'];
    $class = $p['class'];
    $roll = $p['roll'];

    if ($search !== '' && !ci_contains($name, $search)) continue;
    if ($classFilter !== 'All' && !ci_equal($class, $classFilter)) continue;

    if ($rollFilter !== 'All') {
      $r1 = (int)preg_replace('/\D+/', '', $roll);
      $r2 = (int)preg_replace('/\D+/', '', $rollFilter);
      if ($r1 !== $r2) continue;
    }

    $students[] = [
      'name' => $name,
      'class' => $class,
      'roll' => $roll,
      'filename' => $fn
    ];
  }

  usort($students, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
  json_response(['success'=>true, 'count'=>count($students), 'students'=>$students]);
}

// ---------- CREATE ----------
if ($action === 'create' && $method === 'POST') {
  $nameIn  = isset($_POST['name'])  ? trim($_POST['name'])  : '';
  $rollIn  = isset($_POST['roll'])  ? trim($_POST['roll'])  : '';
  $classIn = isset($_POST['class']) ? trim($_POST['class']) : '';

  if ($nameIn === '' || $rollIn === '' || $classIn === '') {
    json_response(['success'=>false, 'message'=>'সব ফিল্ড পূরণ করুন।']);
  }
  if (!ctype_digit($rollIn) || (int)$rollIn < 1 || (int)$rollIn > 100) {
    json_response(['success'=>false, 'message'=>'Roll 1-100 এর মধ্যে হতে হবে।']);
  }
  $classNorm = normalize_class($classIn);
  if ($classNorm === null) {
    json_response(['success'=>false, 'message'=>'Class অবশ্যই Play, Nursery, Kg বা 1-10 হতে হবে।']);
  }

  $nameNorm = norm_space($nameIn);
  $rollNorm = (string)((int)$rollIn);

  // Duplicate: same Name + Roll (class ভিন্ন হলেও ব্লক)
  $files = glob($DATA_DIR . DIRECTORY_SEPARATOR . '*.txt');
  foreach ($files as $file) {
    $p = parse_filename(basename($file));
    if (!$p) continue;
    if (ci_equal(norm_space($p['name']), $nameNorm) && (string)((int)$p['roll']) === $rollNorm) {
      json_response(['success'=>false, 'message'=>'অনুগ্রহ করে সঠিক ডেটা প্রদান করুন।']);
    }
  }

  $filename = sanitize_segment($nameNorm) . '_' . sanitize_segment($classNorm) . '_' . sanitize_segment($rollNorm) . '.txt';
  $target = $DATA_DIR . DIRECTORY_SEPARATOR . $filename;
  if (file_exists($target)) {
    json_response(['success'=>false, 'message'=>'এই ফাইলটি আগে থেকেই আছে।']);
  }

  $content = "Name: {$nameNorm}\nClass: {$classNorm}\nRoll: {$rollNorm}\nCreated: " . date('Y-m-d H:i:s');
  $ok = @file_put_contents($target, $content);
  if ($ok === false) json_response(['success'=>false, 'message'=>'সেভ করতে সমস্যা হয়েছে। পারমিশন চেক করুন।']);

  json_response(['success'=>true, 'message'=>'সফলভাবে সেভ হয়েছে!', 'filename'=>$filename]);
}

// ---------- DELETE ONE ----------
if ($action === 'delete' && $method === 'POST') {
  $filename = $_POST['filename'] ?? '';
  if ($filename === '') json_response(['success'=>false, 'message'=>'ফাইল নির্দিষ্ট নেই।']);
  $base = basename($filename);
  if (pathinfo($base, PATHINFO_EXTENSION) !== 'txt') {
    json_response(['success'=>false, 'message'=>'.txt ফাইল নয়।']);
  }

  $target = realpath($DATA_DIR . DIRECTORY_SEPARATOR . $base);
  $root   = realpath($DATA_DIR);
  if (!$target || strpos($target, $root) !== 0) json_response(['success'=>false, 'message'=>'ফাইল পাথ অবৈধ।']);
  if (!file_exists($target)) json_response(['success'=>false, 'message'=>'ফাইল পাওয়া যায়নি।']);
  if (!@unlink($target)) json_response(['success'=>false, 'message'=>'ফাইল ডিলিট করতে ব্যর্থ।']);

  json_response(['success'=>true, 'message'=>'ডিলিট সম্পন্ন।']);
}

// ---------- DELETE ALL ----------
if ($action === 'delete_all' && $method === 'POST') {
  $files = glob($DATA_DIR . DIRECTORY_SEPARATOR . '*.txt');
  $count = 0;
  foreach ($files as $f) { if (@unlink($f)) $count++; }
  json_response(['success'=>true, 'deleted'=>$count, 'message'=>'All Delete সম্পন্ন।']);
}

// Unknown
json_response(['success'=>false, 'message'=>'Invalid action or method.']);