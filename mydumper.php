<?php

// User name and password
$login = ['admin', 'dump'];

$connection = [
  'host' => 'localhost',
  'mysqldump' => 'mysqldump',
];
$debug = $_REQUEST['debug'] === '1';
$user = $_SERVER['PHP_AUTH_USER'];
$password = $_SERVER['PHP_AUTH_PW'];

if (!$user) {
  if (!($auth = $_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }

  if ($auth && strtolower(substr($auth, 0, 6)) === 'basic ') {
    list($user, $password) = explode(':', base64_decode(substr($auth, 6)));
  }
}

if ($user !== $login[0] || $password !== $login[1]) {
  header('WWW-Authenticate: Basic realm="Database dumper"');
  http_response_code(401);
  echo 'Please authenticate.';
  exit;
}

if ($_REQUEST['no_detect'] === '1') {
  // Do not try to read any configuration
} else if (is_dir('administrator') &&
  is_dir('components') &&
  is_file('configuration.php')) {
  // Joomla!
  require_once 'configuration.php';

  $config = new \JConfig();
  $connection = [
    'host' => $config->host,
    'user' => $config->user,
    'password' => $config->password,
    'db' => $config->db,
  ] + $connection;
} elseif (is_file('includes/config.JTL-Shop.ini.php')) {
  // JTL Shop
  require_once 'includes/config.JTL-Shop.ini.php';

  $connection = [
    'host' => DB_HOST,
    'user' => DB_USER,
    'password' => DB_PASS,
    'db' => DB_NAME,
  ] + $connection;
} elseif (is_file('app/etc/local.xml')) {
  // Magento 1.x
  $config = new \SimpleXMLElement(file_get_contents('app/etc/local.xml'));
  $dbConfig = $config->global->resources->default_setup->connection;
  $connection = [
    'host' => (string)$dbConfig->host,
    'user' => (string)$dbConfig->username,
    'password' => (string)$dbConfig->password,
    'db' => (string)$dbConfig->dbname,
  ] + $connection;
} elseif (is_file('typo3conf/LocalConfiguration.php')) {
  // TYPO3 CMS
  $config = require 'typo3conf/LocalConfiguration.php';
  $dbConfig = isset($config['DB']['Connections']['Default'])
    ? $config['DB']['Connections']['Default']
    : $config['DB'];
  $connection = [
    'host' => $dbConfig['host'],
    'port' => $dbConfig['port'],
    'user' => $dbConfig['username'],
    'password' => $dbConfig['password'],
    'db' => $dbConfig['database'],
  ] + $connection;
} elseif (is_file('wp-config.php')) {
  // WordPress
  require_once 'wp-config.php';

  $connection = [
    'host' => DB_HOST,
    'user' => DB_USER,
    'password' => DB_PASSWORD,
    'db' => DB_NAME,
  ] + $connection;
}

foreach (['host', 'db', 'user', 'password', 'port', 'mysqldump'] as $variable) {
  if (isset($_REQUEST[$variable])) {
    $connection[$variable] = $_REQUEST[$variable];
  }
}

if (strpos($connection['host'], ':') !== false) {
  list($connection['host'], $connection['port']) = explode(':', $connection['host']);
}

foreach ([
           'host' => 'host',
           'db' => 'database',
           'user' => 'user',
           'password' => 'password',
           'mysqldump' => 'mysqldump',
         ] as $variable => $name) {
  if (!$connection[$variable]) {
    http_response_code(500);
    die("No $name configured.");
  }
}

$debugFile = $debug ? 'mydumper.err.txt' : '/dev/null';

if ($debug) {
  file_put_contents($debugFile, "\n".var_export($connection, true)."\n", FILE_APPEND);
}

set_time_limit(0);
setlocale(LC_CTYPE, 'en_US.UTF-8');

$descriptors = [
  0 => ['pipe', 'r'],
  1 => ['pipe', 'w'],
  2 => ['file', $debugFile, 'a'],
];
$command = sprintf(
  '%1$s --opt --add-drop-table --user=%2$s --password --host=%3$s%5$s %4$s',
  $connection['mysqldump'],
  escapeshellarg($connection['user']),
  escapeshellarg($connection['host']),
  escapeshellarg($connection['db']),
  isset($connection['port']) ? ' --port='.escapeshellarg($connection['port']) : ''
);
$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
  http_response_code(500);
  die('Could not spawn process.');
}

// Send password
fwrite($pipes[0], "${connection['password']}\n");
fclose($pipes[0]);

$output = fopen('php://output', 'wb');

if ($output === false) {
  http_response_code(500);
  die('Could not open output.');
}

$filename = "${connection['db']}.sql";

header('Content-Type: application/g-zip');
header("Content-Disposition: attachment; filename=\"$filename.gz\"");

// gzip header
$now = time();
$header = "\x1F\x8B\x08\x08".pack('V', $now)."\0\xFF";
fwrite($output, $header, 10);
fwrite($output, "$filename\0", 1 + strlen($filename));

$filter = stream_filter_append($output, 'zlib.deflate', STREAM_FILTER_WRITE, -1);
$hashingContext = hash_init('crc32b');
$data = true;
$fileSize = 0;

while (($data !== false) && !feof($pipes[1])) {
  $data = fread($pipes[1], 64 * 1024);

  if ($data !== false) {
    hash_update($hashingContext, $data);
    $dataLength = strlen($data);
    $fileSize += $dataLength;
    fwrite($output, $data, $dataLength);
  }
}

stream_filter_remove($filter);
$crc = hash_final($hashingContext, true);
fwrite($output, $crc[3].$crc[2].$crc[1].$crc[0], 4);
fwrite($output, pack('V', $fileSize), 4);
fclose($output);
fclose($pipes[1]);
proc_close($process);
