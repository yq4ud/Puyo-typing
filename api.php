<?php
// 連鎖タイピング ランキングAPI（PHP 7.4以上 / Xserver対応・データベース不要）
// renzoku-typing.html と同じフォルダに置くだけで、ゲームが自動でオンラインランキングにつなぎます。
// 点数は同じフォルダの ranking-data.php に保存されます（先頭でPHPを終了させるので、ブラウザから直接は読めません）。

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$MODES = ['easy', 'normal', 'hard', 'speed'];
$DATA  = __DIR__ . '/ranking-data.php';
$HEAD  = "<?php http_response_code(404); exit; ?>\n";

function open_db($path, $lock) {
    $fp = fopen($path, 'c+');
    if (!$fp) { http_response_code(500); echo '{"error":"storage"}'; exit; }
    flock($fp, $lock);
    $raw = stream_get_contents($fp);
    $nl  = strpos($raw, "\n");
    $j   = ($raw !== '' && $nl !== false) ? json_decode(substr($raw, $nl + 1), true) : null;
    $d   = is_array($j) ? $j : [];
    foreach (['easy', 'normal', 'hard', 'speed', 'rl'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = [];
    }
    return [$fp, $d];
}
function save_db($fp, $d, $head) {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $head . json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fp);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r = $_GET['r'] ?? '';

if ($method === 'GET' && $r === 'ranking') {
    $m = $_GET['mode'] ?? '';
    if (!in_array($m, $MODES, true)) { http_response_code(400); echo '{"error":"mode"}'; exit; }
    list($fp, $d) = open_db($DATA, LOCK_SH);
    flock($fp, LOCK_UN); fclose($fp);
    echo json_encode(array_slice($d[$m], 0, 10), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $r === 'score') {
    $body = file_get_contents('php://input');
    if (strlen($body) > 2000) { http_response_code(400); echo '{"error":"size"}'; exit; }
    $j = json_decode($body, true);
    if (!is_array($j)) { http_response_code(400); echo '{"error":"json"}'; exit; }

    $mode  = $j['mode'] ?? '';
    $name  = preg_replace('/[\x00-\x1f\x7f<>&"\']/u', '', (string)($j['name'] ?? ''));
    $name  = trim((string)$name);
    $name  = function_exists('mb_substr') ? mb_substr($name, 0, 12, 'UTF-8') : substr($name, 0, 12);
    $score = (int)floor((float)($j['score'] ?? 0));
    $chain = (int)floor((float)($j['chain'] ?? 0));
    if (!in_array($mode, $MODES, true) || $name === '' || $score < 1 || $score > 1000000 || $chain < 0 || $chain > 30) {
        http_response_code(400); echo '{"error":"invalid"}'; exit;
    }

    list($fp, $d) = open_db($DATA, LOCK_EX);
    // 登録制限：同じ接続元から10秒に1回、1時間に30回まで
    $now = time();
    $ip  = sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $hits = array_values(array_filter($d['rl'][$ip] ?? [], function ($t) use ($now) { return $now - $t < 3600; }));
    if ((count($hits) && $now - end($hits) < 10) || count($hits) >= 30) {
        $d['rl'][$ip] = $hits;
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(429); echo '{"error":"rate"}'; exit;
    }
    $hits[] = $now;
    $d['rl'][$ip] = $hits;
    foreach ($d['rl'] as $k => $v) {           // 古い記録を掃除
        if (!$v || $now - end($v) >= 3600) unset($d['rl'][$k]);
    }
    $d[$mode][] = ['name' => $name, 'score' => $score, 'chain' => $chain, 'date' => date('Y-m-d')];
    usort($d[$mode], function ($a, $b) { return $b['score'] <=> $a['score']; });
    $d[$mode] = array_slice($d[$mode], 0, 100);
    save_db($fp, $d, $HEAD);
    flock($fp, LOCK_UN); fclose($fp);
    echo '{"ok":true}';
    exit;
}

http_response_code(404);
echo '{"error":"not found"}';
