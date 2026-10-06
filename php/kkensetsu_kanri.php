<?php
/**
 * Kurage 建設業許可 期限管理（kkensetsu の管理画面）― PHP＋SQLite。
 *
 * 行政書士事務所・建設会社が、顧客ごとの建設業許可（許可日・決算月）を保存し、
 *   ・更新申請の期限（満了の30日前・休日なら翌開庁日）と決算変更届の期限を、近い順に一覧
 *   ・「更新申請を出した」「決算変更届を出した」を記録すると、その期限の警告が消える
 *   ・期限の90/60/30/14日前などにメールで知らせる（?cron=鍵 を cron から1日1回呼ぶ）
 *   ・期限をカレンダーアプリで購読（?ics=鍵）
 *   ・CSV で取り込み・書き出し
 * をする。期限の数え方は kkensetsu.php（無料の計算画面）と同じ（建設業法3条3項・施行規則5条・法11条2項・
 * 行政機関の休日に関する法律）。
 *
 * 置き方: このファイルと kkensetsu_config.php（kkensetsu_config.php.example を写して書き換え）を同じ場所に置く。
 * データは kkensetsu_data/（.htaccess で外から読めないようにしてある）に SQLite で持つ。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

$KK = [
    'office'      => '建設業許可 期限管理',        // 画面の左上に出す事務所名
    'password'    => '',                          // ログインの合言葉。空なら入れない（demo のときだけ空で入れる）
    'db'          => __DIR__ . '/kkensetsu_data/kanri.sqlite',
    'demo'        => false,                       // true: 見本を入れ、毎日0時を過ぎたら初期化する（当社の公開デモ用）
    'notify_to'   => '',                          // 通知メールの宛先（カンマ区切り）。空なら送らない
    'mail_from'   => '',                          // 送信元アドレス（サーバーで送れるもの）
    'notify_days' => [90, 60, 30, 14, 7],         // 期限の何日前に知らせるか
    'cron_key'    => '',                          // ?cron=この鍵 で通知を送る（空なら通知の入口を閉じる）
    'public_url'  => '',                          // この画面の URL（通知メールに書く）
    'buy_url'     => '',                          // デモのときに出す導入版の URL
];
if (is_file(__DIR__ . '/kkensetsu_config.php')) { $KK = array_merge($KK, (array)require __DIR__ . '/kkensetsu_config.php'); }
function cfg(string $k) { global $KK; return $KK[$k] ?? null; }

// 国民の祝日（内閣府 syukujitsu.csv・公表されている2027年まで。kkensetsu.php と同じ表）。それより先は土日と年末年始だけで判定する
const HOLIDAYS = ['2026-01-01','2026-01-12','2026-02-11','2026-02-23','2026-03-20','2026-04-29','2026-05-03','2026-05-04','2026-05-05','2026-05-06','2026-07-20','2026-08-11','2026-09-21','2026-09-22','2026-09-23','2026-10-12','2026-11-03','2026-11-23',
                  '2027-01-01','2027-01-11','2027-02-11','2027-02-23','2027-03-21','2027-03-22','2027-04-29','2027-05-03','2027-05-04','2027-05-05','2027-07-19','2027-08-11','2027-09-20','2027-09-23','2027-10-11','2027-11-03','2027-11-23'];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function jd(string $ymd): string { [$y, $m, $d] = array_map('intval', explode('-', $ymd)); return $y . '年' . $m . '月' . $d . '日'; }
function wd(string $ymd): string { return ['日','月','火','水','木','金','土'][(int)date('w', strtotime($ymd))]; }
function days_to(string $ymd): int { return (int)round((strtotime($ymd) - strtotime(date('Y-m-d'))) / 86400); }
function self_url(array $q = []): string { $b = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'); return $b . ($q ? '?' . http_build_query($q) : ''); }

// ---------------- 期限の計算 ----------------
/** 行政機関の休日（行政機関の休日に関する法律 第1条）：土日・国民の祝日・12月29日〜1月3日 */
function closed(string $ymd): bool {
    $t = strtotime($ymd); $w = (int)date('w', $t); $md = date('m-d', $t);
    return $w === 0 || $w === 6 || in_array($ymd, HOLIDAYS, true) || $md >= '12-29' || $md <= '01-03';
}
function next_open(string $ymd): string { while (closed($ymd)) { $ymd = date('Y-m-d', strtotime($ymd . ' +1 day')); } return $ymd; }
/** 有効期間の満了日：許可日から5年目を経過する日の前日（休日でも動かない）。2月29日は5年後の2月末日を応当日とする */
function expiry(string $permit): string {
    [$y, $m, $d] = array_map('intval', explode('-', $permit));
    $y += 5; $d = min($d, (int)date('t', mktime(0, 0, 0, $m, 1, $y)));
    return date('Y-m-d', mktime(0, 0, 0, $m, $d - 1, $y));
}
/** 更新申請の期限：満了日の30日前（施行規則5条）。休日なら直後の開庁日 */
function apply_by(string $exp): string { return next_open(date('Y-m-d', strtotime($exp . ' -30 days'))); }
/** 決算変更届：事業年度（月末決算）の終わりから4か月後の月末（法11条2項）。休日なら翌開庁日 */
function kessan_due(string $end): string { $t = strtotime($end); return next_open(date('Y-m-t', mktime(0, 0, 0, (int)date('n', $t) + 4, 1, (int)date('Y', $t)))); }
/** 次に出す決算変更届。提出済みの記録が無ければ「期限がまだ来ていない直近の決算期」、
 *  記録があれば「記録した決算期より後で、いちばん古い決算期」（期限を過ぎていれば期限切れとして出す） */
function next_kessan(int $km, ?string $done_end): ?array {
    if ($km < 1 || $km > 12) { return null; }
    $today = date('Y-m-d');
    for ($y = (int)date('Y') - 6; $y <= (int)date('Y') + 1; $y++) {
        $end = date('Y-m-t', mktime(0, 0, 0, $km, 1, $y)); $due = kessan_due($end);
        if ($done_end !== null) { if ($end > $done_end) { return ['end' => $end, 'due' => $due]; } }
        elseif ($due >= $today) { return ['end' => $end, 'due' => $due]; }
    }
    return null;
}
/** 1件の許可の期限と状態 */
function calc(array $r): array {
    $exp = expiry($r['permit_date']); $ab = apply_by($exp);
    $renewed = $r['renewed_at'] && $r['renewed_at'] <= $exp && $r['renewed_at'] >= date('Y-m-d', strtotime($exp . ' -6 months'));
    $k = next_kessan((int)$r['kessan_month'], $r['kessan_done_end'] ?: null);
    $items = [];
    if (!$renewed) { $items[] = ['kind' => 'renew', 'label' => '更新申請', 'date' => $ab, 'left' => days_to($ab)]; }
    if ($k) { $items[] = ['kind' => 'kessan', 'label' => (int)date('n', strtotime($k['end'])) . '月決算（' . date('Y', strtotime($k['end'])) . '年）の決算変更届', 'date' => $k['due'], 'left' => days_to($k['due']), 'end' => $k['end']]; }
    usort($items, fn($a, $b) => $a['left'] <=> $b['left']);
    return ['exp' => $exp, 'apply_by' => $ab, 'renewed' => $renewed, 'kessan' => $k, 'items' => $items,
            'next' => $items[0] ?? null, 'expired' => !$renewed && days_to($exp) < 0];
}

// ---------------- データ ----------------
function db(): PDO {
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $f = cfg('db');
    if (!is_dir(dirname($f))) { mkdir(dirname($f), 0775, true); }
    if (!is_file(dirname($f) . '/.htaccess')) { @file_put_contents(dirname($f) . '/.htaccess', "Require all denied\n"); }
    $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000;');
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS permit(
  id INTEGER PRIMARY KEY, company TEXT NOT NULL, permit_no TEXT, authority TEXT, kind TEXT, gyoshu TEXT,
  permit_date TEXT NOT NULL, kessan_month INTEGER, contact TEXT, memo TEXT,
  renewed_at TEXT, kessan_done_end TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS sent(k TEXT PRIMARY KEY, at TEXT);
SQL);
    if (cfg('demo')) { demo_reset_if_old($pdo); }
    return $pdo;
}
function meta(string $k, ?string $set = null): ?string {
    if ($set !== null) { db()->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute([$k, $set]); return $set; }
    $v = db()->prepare('SELECT v FROM meta WHERE k=?'); $v->execute([$k]); $r = $v->fetchColumn(); return $r === false ? null : (string)$r;
}
function ics_key(): string { return meta('ics_key') ?? meta('ics_key', bin2hex(random_bytes(16))); }

function demo_reset_if_old(PDO $p): void {
    $today = date('Y-m-d');
    $v = $p->query("SELECT v FROM meta WHERE k='seeded'")->fetchColumn();
    if ($v === $today) { return; }
    $p->beginTransaction();
    $p->exec('DELETE FROM permit; DELETE FROM sent;');
    $t = strtotime($today);
    $d = fn(string $rel) => date('Y-m-d', strtotime($rel, $t));
    $rows = [   // 見本：期限の状態がそろって見えるよう、今日から数えた許可日で作る
        ['丸山建設（見本）', '愛知県知事 許可（般-3）第10101号', '知事', '一般', '土木・とび・舗装', $d('-5 years +50 days'), 3, '丸山 様 052-000-0001', '', null, $d('-1 year') ],
        ['東工務店（見本）', '愛知県知事 許可（般-3）第10202号', '知事', '一般', '建築・大工・内装', $d('-5 years +20 days'), 6, '東 様', '書類の回収待ち', null, null],
        ['中央設備（見本）', '愛知県知事 許可（特-5）第10303号', '知事', '特定', '管・機械器具設置', $d('-3 years'), 9, '', '', null, null],
        ['北電工（見本）', '国土交通大臣 許可（般-4）第20404号', '大臣', '一般', '電気・電気通信', $d('-5 years +200 days'), 12, '', '', null, null],
        ['南塗装（見本）', '岐阜県知事 許可（般-2）第30505号', '知事', '一般', '塗装・防水', $d('-5 years +10 days'), 3, '', '更新申請を提出済み', $d('-5 days'), null],
        ['港造園（見本）', '三重県知事 許可（般-6）第40606号', '知事', '一般', '造園・土木', $d('-1 year'), 5, '', '', null, null],
    ];
    $st = $p->prepare('INSERT INTO permit(company,permit_no,authority,kind,gyoshu,permit_date,kessan_month,contact,memo,renewed_at,kessan_done_end,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        // 決算変更届：1つ目の見本は「期限を過ぎた直近の決算期まで提出済み」、ほかは記録なし（期限が来ていない直近の決算期を出す）
        $km = $r[6]; $done = $r[10];
        if ($done !== null) {   // 期限を過ぎた直近の決算期までは出してある
            for ($y = (int)date('Y'); $y >= (int)date('Y') - 2; $y--) { $e = date('Y-m-t', mktime(0, 0, 0, $km, 1, $y)); if (kessan_due($e) < $today) { $done = $e; break; } }
        }
        $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $km, $r[7], $r[8], $r[9], $done, $today, $today]);
    }
    $p->prepare("INSERT OR REPLACE INTO meta(k,v) VALUES('seeded',?)")->execute([$today]);
    $p->commit();
}

function all_rows(): array {
    $rows = db()->query('SELECT * FROM permit')->fetchAll();
    foreach ($rows as &$r) { $r['c'] = calc($r); }
    usort($rows, fn($a, $b) => ($a['c']['next']['left'] ?? 99999) <=> ($b['c']['next']['left'] ?? 99999));
    return $rows;
}

// ---------------- 入口：カレンダー購読・通知（ログイン不要・鍵で守る） ----------------
if (isset($_GET['ics'])) {
    if (!hash_equals(ics_key(), (string)$_GET['ics'])) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/calendar; charset=UTF-8');
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//exbridge//kkensetsu-kanri//JA', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:建設業許可の期限（' . cfg('office') . '）'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach (all_rows() as $r) {
        $ev = [];
        if (!$r['c']['renewed']) { $ev[] = ['renew', $r['c']['apply_by'], '更新申請の期限']; }
        $ev[] = ['exp', $r['c']['exp'], '有効期間の満了日'];
        if ($r['c']['kessan']) { $ev[] = ['kessan', $r['c']['kessan']['due'], '決算変更届の期限']; }
        foreach ($ev as [$k, $dt, $t]) {
            $s = str_replace('-', '', $dt);
            $L = array_merge($L, ['BEGIN:VEVENT', 'UID:kk-' . $r['id'] . '-' . $k . '-' . $s . '@kkensetsu', 'DTSTAMP:' . $stamp, 'DTSTART;VALUE=DATE:' . $s,
                'DTEND;VALUE=DATE:' . date('Ymd', strtotime($dt . ' +1 day')), 'SUMMARY:' . preg_replace('/[,;\r\n]/u', ' ', $r['company'] . ' ' . $t),
                'BEGIN:VALARM', 'TRIGGER:-P14D', 'ACTION:DISPLAY', 'DESCRIPTION:' . preg_replace('/[,;\r\n]/u', ' ', $r['company'] . ' ' . $t), 'END:VALARM', 'END:VEVENT']);
        }
    }
    $L[] = 'END:VCALENDAR';
    echo implode("\r\n", $L) . "\r\n"; exit;
}
if (isset($_GET['cron'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    if ((string)cfg('cron_key') === '' || !hash_equals((string)cfg('cron_key'), (string)$_GET['cron'])) { http_response_code(403); exit("forbidden\n"); }
    $days = array_map('intval', (array)cfg('notify_days')); $lines = [];
    foreach (all_rows() as $r) {
        foreach ($r['c']['items'] as $it) {
            $hit = $it['left'] < 0 || in_array($it['left'], $days, true);
            $key = $r['id'] . ':' . $it['kind'] . ':' . $it['date'] . ':' . ($it['left'] < 0 ? 'late' . date('Y-m-d') : $it['left']);
            if (!$hit) { continue; }
            $q = db()->prepare('SELECT 1 FROM sent WHERE k=?'); $q->execute([$key]);
            if ($q->fetchColumn()) { continue; }
            db()->prepare('INSERT INTO sent(k,at) VALUES(?,?)')->execute([$key, date('c')]);
            $lines[] = ($it['left'] < 0 ? ($it['kind'] === 'renew' && !$r['c']['expired'] ? '【申請期限を過ぎた・満了まであと' . days_to($r['c']['exp']) . '日】' : '【期限切れ】') : '【あと' . $it['left'] . '日】') . $r['company'] . '：' . $it['label'] . ' ' . jd($it['date']) . '（' . wd($it['date']) . '）';
        }
    }
    if (!$lines) { exit("no notice\n"); }
    $to = trim((string)cfg('notify_to'));
    if ($to === '' || cfg('demo')) { echo implode("\n", $lines) . "\n(送信なし: 宛先未設定またはデモ)\n"; exit; }
    $body = "建設業許可の期限のお知らせ（" . cfg('office') . "）\n\n" . implode("\n", $lines) . "\n\n一覧: " . cfg('public_url') . "\n";
    $ok = mb_send_mail($to, '建設業許可の期限のお知らせ（' . count($lines) . '件）', $body, cfg('mail_from') ? 'From: ' . cfg('mail_from') : '');
    echo ($ok ? "sent " : "send failed ") . count($lines) . "\n"; exit;
}

// ---------------- ログイン ----------------
session_name('kkensetsu');
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')); }
$need_login = !cfg('demo');
if ($need_login && (string)cfg('password') === '') { http_response_code(503); exit('kkensetsu_config.php に password を設定してください。'); }
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ' . self_url()); exit; }
if ($need_login && empty($_SESSION['in'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
        usleep(400000);
        if (hash_equals((string)cfg('password'), (string)$_POST['pw'])) { session_regenerate_id(true); $_SESSION['in'] = 1; header('Location: ' . self_url()); exit; }
        $err = '合言葉が違います。';
    }
    page('ログイン', '<div class="panel" style="max-width:420px;margin:40px auto"><h1>' . h(cfg('office')) . '</h1><form method="post"><label for="pw">合言葉</label><input id="pw" name="pw" type="password" autocomplete="current-password" required style="width:100%"><p><button class="btn">入る</button></p>' . ($err ? '<p class="ng">' . h($err) . '</p>' : '') . '</form></div>', false);
    exit;
}

// ---------------- 書き込み ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('画面を開き直してから、もう一度お試しください。'); }
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $now = date('Y-m-d H:i');
    $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? (string)$_POST[$k] : null;
    if ($a === 'save') {
        $pd = $date('permit_date'); $co = trim((string)($_POST['company'] ?? ''));
        if (!$pd || $co === '') { $_SESSION['flash'] = '会社名と許可日は必ず入れてください。'; header('Location: ' . self_url(['p' => 'edit', 'id' => $id ?: null])); exit; }
        $v = [$co, trim((string)($_POST['permit_no'] ?? '')), (string)($_POST['authority'] ?? '知事'), (string)($_POST['kind'] ?? '一般'), trim((string)($_POST['gyoshu'] ?? '')),
              $pd, max(0, min(12, (int)($_POST['kessan_month'] ?? 0))), trim((string)($_POST['contact'] ?? '')), trim((string)($_POST['memo'] ?? '')), $date('renewed_at'), $date('kessan_done_end')];
        if ($id) { db()->prepare('UPDATE permit SET company=?,permit_no=?,authority=?,kind=?,gyoshu=?,permit_date=?,kessan_month=?,contact=?,memo=?,renewed_at=?,kessan_done_end=?,updated_at=? WHERE id=?')->execute(array_merge($v, [$now, $id])); }
        else { db()->prepare('INSERT INTO permit(company,permit_no,authority,kind,gyoshu,permit_date,kessan_month,contact,memo,renewed_at,kessan_done_end,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($v, [$now, $now])); }
        $_SESSION['flash'] = '保存しました：' . $co;
    } elseif ($a === 'del' && $id) {
        db()->prepare('DELETE FROM permit WHERE id=?')->execute([$id]); $_SESSION['flash'] = '削除しました。';
    } elseif ($a === 'renewed' && $id) {
        db()->prepare('UPDATE permit SET renewed_at=?,updated_at=? WHERE id=?')->execute([date('Y-m-d'), $now, $id]); $_SESSION['flash'] = '更新申請を出したと記録しました。新しい許可が下りたら、許可日を書き換えてください。';
    } elseif ($a === 'kessan' && $id && $date('end')) {
        db()->prepare('UPDATE permit SET kessan_done_end=?,updated_at=? WHERE id=?')->execute([$date('end'), $now, $id]); $_SESSION['flash'] = '決算変更届を出したと記録しました。';
    } elseif ($a === 'import' && is_uploaded_file($_FILES['csv']['tmp_name'] ?? '')) {
        $txt = (string)file_get_contents($_FILES['csv']['tmp_name']);
        if (!mb_check_encoding($txt, 'UTF-8')) { $txt = mb_convert_encoding($txt, 'UTF-8', 'SJIS-win'); }
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt); $n = 0; $bad = 0;
        $st = db()->prepare('INSERT INTO permit(company,permit_no,authority,kind,gyoshu,permit_date,kessan_month,contact,memo,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach (preg_split('/\r\n|\n|\r/', $txt) as $i => $line) {
            if (trim($line) === '') { continue; }
            $c = str_getcsv($line);
            if ($i === 0 && !preg_match('/\d{4}/', $line)) { continue; }   // 見出し行
            $pd = str_replace('/', '-', trim($c[5] ?? ''));
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $pd, $m)) { $pd = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); } else { $bad++; continue; }
            $st->execute([trim($c[0] ?? ''), trim($c[1] ?? ''), trim($c[2] ?? '') ?: '知事', trim($c[3] ?? '') ?: '一般', trim($c[4] ?? ''), $pd, (int)($c[6] ?? 0), trim($c[7] ?? ''), trim($c[8] ?? ''), $now, $now]); $n++;
        }
        $_SESSION['flash'] = $n . '件を取り込みました' . ($bad ? '（許可日が読めない' . $bad . '行は飛ばしました）' : '') . '。';
    }
    header('Location: ' . self_url()); exit;
}
if (($_GET['p'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="kensetsu-kyoka.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['会社名', '許可番号', '許可行政庁', '一般・特定', '業種', '許可日', '決算月', '連絡先', 'メモ', '有効期間の満了日', '更新申請の期限', '次の決算変更届の期限']);
    foreach (all_rows() as $r) { fputcsv($o, [$r['company'], $r['permit_no'], $r['authority'], $r['kind'], $r['gyoshu'], $r['permit_date'], $r['kessan_month'], $r['contact'], $r['memo'], $r['c']['exp'], $r['c']['apply_by'], $r['c']['kessan']['due'] ?? '']); }
    exit;
}

// ---------------- 画面 ----------------
function page(string $title, string $body, bool $nav = true): void {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="color-scheme" content="light">';
    echo '<title>' . h($title . '｜' . cfg('office')) . '</title><link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png"><style>
:root{--navy:#1b2a3a;--blue:#0f5f8c;--ink:#1f2933;--sub:#5b6676;--line:#dde3ea;--bg:#f6f8fa;--ok:#047857;--ok-l:#e7f6ef;--ng:#b42318;--ng-l:#fdecea;--amber:#9a6700;--amber-l:#fff6e0;--hard:#d97706}
*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font:15px/1.7 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}a{color:var(--blue)}
.wrap{max-width:1080px;margin:0 auto;padding:0 16px}header.top{border-bottom:1px solid var(--line)}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:56px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800}.brand img{width:30px;height:30px;object-fit:contain}nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.demo{background:var(--amber-l);border-bottom:1px solid #f0d9a0;font-size:13.5px}.demo .wrap{padding-top:6px;padding-bottom:6px}
h1{font-size:22px;color:var(--navy);margin:18px 0 8px}h2{font-size:17px;color:var(--navy);margin:24px 0 8px;border-left:5px solid var(--hard);padding-left:10px}
.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin:12px 0}.flash{background:var(--ok-l);border:1px solid #b7e4cf;border-radius:10px;padding:8px 12px;margin:12px 0}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:10px;margin:12px 0}.big div{border:1px solid var(--line);border-radius:12px;padding:10px 14px}.big .k{display:block;font-size:12.5px;color:var(--sub);font-weight:700}.big .v{display:block;font-size:24px;font-weight:800;color:var(--navy)}.big .v.ng{color:var(--ng)}.big .v.warn{color:var(--amber)}
.ng{color:var(--ng)}.src{font-size:12.5px;color:var(--sub)}.tag{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12.5px;font-weight:800;white-space:nowrap}.t-ng{background:var(--ng-l);color:var(--ng)}.t-warn{background:var(--amber-l);color:var(--amber)}.t-ok{background:var(--ok-l);color:var(--ok)}
.list{display:grid;gap:10px}.item{border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1.6fr) auto;gap:8px 16px;align-items:start}
.item .co{font-weight:800;color:var(--navy)}.item ul{list-style:none;margin:0;padding:0}.item li{margin:2px 0}.act{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}
.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:9px;padding:8px 14px;font-weight:700;text-decoration:none;cursor:pointer;font-size:14px;font-family:inherit}.btn.sub{background:#fff;color:var(--blue);border:2px solid var(--blue);padding:6px 12px}.btn.s{font-size:13px;padding:5px 10px}
form.inline{display:inline}label{display:block;font-weight:700;font-size:13.5px;margin:10px 0 3px}input,select,textarea{font:inherit;font-size:16px;padding:8px 10px;border:2px solid var(--line);border-radius:9px;max-width:100%;background:#fff}
.fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:0 16px}.fg input,.fg select{width:100%}textarea{width:100%;min-height:70px}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}.filters a{font-size:13.5px;font-weight:700;text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:3px 12px;color:var(--sub)}.filters a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
code{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:13px;overflow-wrap:anywhere}
@media (max-width:720px){.item{grid-template-columns:1fr}.act{justify-content:flex-start}}
</style></head><body>';
    if (cfg('demo')) { echo '<div class="demo"><div class="wrap">デモです。見本の許可が入っていて、だれでも書き換えられます。毎日0時を過ぎると見本に戻ります。' . (cfg('buy_url') ? ' <a href="' . h(cfg('buy_url')) . '">導入版（ソースコード付き）</a>' : '') . '</div></div>'; }
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . h(self_url()) . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="30" height="30" alt=""><span>' . h(cfg('office')) . '</span></a>';
    if ($nav) { echo '<nav class="menu"><a href="' . h(self_url()) . '">期限の一覧</a><a href="' . h(self_url(['p' => 'edit'])) . '">許可を登録</a><a href="' . h(self_url(['p' => 'tools'])) . '">取り込み・通知</a>' . (cfg('demo') ? '' : '<a href="' . h(self_url(['logout' => 1])) . '">ログアウト</a>') . '</nav>'; }
    echo '</div></header><main class="wrap">';
    if (!empty($_SESSION['flash'])) { echo '<p class="flash">' . h($_SESSION['flash']) . '</p>'; unset($_SESSION['flash']); }
    echo $body . '<p class="src" style="margin-top:30px">期限の数え方：有効期間は許可日から5年目の前日で満了（建設業法3条3項）・更新申請は満了の30日前まで（施行規則5条。休日なら翌開庁日）・決算変更届は事業年度経過後4か月以内（法11条2項）。受付と必要書類は許可行政庁の手引きで確認してください。</p></main>';
    if (cfg('demo') && in_array($_SERVER['HTTP_HOST'] ?? '', ['kurage.exbridge.jp', 'proto.exbridge.jp'], true)) {
        echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script><script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    }
    echo '</body></html>';
}
function hid(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function tag(int $left, bool $late = false): string {
    if ($late || $left < 0) { return '<span class="tag t-ng">期限切れ</span>'; }
    return '<span class="tag ' . ($left <= 30 ? 't-ng' : ($left <= 90 ? 't-warn' : 't-ok')) . '">あと' . $left . '日</span>';
}

$p = (string)($_GET['p'] ?? '');
if ($p === 'edit') {
    $r = ['id' => 0, 'company' => '', 'permit_no' => '', 'authority' => '知事', 'kind' => '一般', 'gyoshu' => '', 'permit_date' => '', 'kessan_month' => 3, 'contact' => '', 'memo' => '', 'renewed_at' => '', 'kessan_done_end' => ''];
    if (!empty($_GET['id'])) { $q = db()->prepare('SELECT * FROM permit WHERE id=?'); $q->execute([(int)$_GET['id']]); $r = $q->fetch() ?: $r; }
    $opt = fn(array $xs, string $cur) => implode('', array_map(fn($x) => '<option' . ($x === $cur ? ' selected' : '') . '>' . h($x) . '</option>', $xs));
    $km = ''; for ($m = 1; $m <= 12; $m++) { $km .= '<option value="' . $m . '"' . ((int)$r['kessan_month'] === $m ? ' selected' : '') . '>' . $m . '月</option>'; }
    $b = '<h1>' . ($r['id'] ? '許可を直す' : '許可を登録') . '</h1><form method="post" class="panel">' . hid() . '<input type="hidden" name="a" value="save"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><div class="fg">'
       . '<div><label for="company">会社名（必須）</label><input id="company" name="company" required value="' . h($r['company']) . '"></div>'
       . '<div><label for="permit_no">許可番号</label><input id="permit_no" name="permit_no" placeholder="愛知県知事 許可（般-3）第12345号" value="' . h($r['permit_no']) . '"></div>'
       . '<div><label for="permit_date">許可日（必須・許可通知書の日付）</label><input id="permit_date" name="permit_date" type="date" required value="' . h($r['permit_date']) . '"></div>'
       . '<div><label for="kessan_month">決算月</label><select id="kessan_month" name="kessan_month"><option value="0">—</option>' . $km . '</select></div>'
       . '<div><label for="authority">許可行政庁</label><select id="authority" name="authority">' . $opt(['知事', '大臣'], (string)$r['authority']) . '</select></div>'
       . '<div><label for="kind">一般・特定</label><select id="kind" name="kind">' . $opt(['一般', '特定', '一般・特定'], (string)$r['kind']) . '</select></div>'
       . '<div><label for="gyoshu">業種</label><input id="gyoshu" name="gyoshu" value="' . h($r['gyoshu']) . '"></div>'
       . '<div><label for="contact">連絡先・担当</label><input id="contact" name="contact" value="' . h($r['contact']) . '"></div>'
       . '<div><label for="renewed_at">更新申請を出した日</label><input id="renewed_at" name="renewed_at" type="date" value="' . h($r['renewed_at']) . '"></div>'
       . '<div><label for="kessan_done_end">決算変更届を出した決算期（その年度の末日）</label><input id="kessan_done_end" name="kessan_done_end" type="date" value="' . h($r['kessan_done_end']) . '"></div>'
       . '</div><label for="memo">メモ</label><textarea id="memo" name="memo">' . h($r['memo']) . '</textarea><p><button class="btn">保存する</button> <a href="' . h(self_url()) . '">戻る</a></p></form>';
    if ($r['id']) { $b .= '<form method="post" onsubmit="return confirm(\'削除しますか？\')">' . hid() . '<input type="hidden" name="a" value="del"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sub s">この許可を削除する</button></form>'; }
    page($r['id'] ? '許可を直す' : '許可を登録', $b); exit;
}
if ($p === 'tools') {
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . self_url();
    $b = '<h1>取り込み・書き出し・通知</h1>'
       . '<h2>CSV で取り込む</h2><form method="post" enctype="multipart/form-data" class="panel">' . hid() . '<input type="hidden" name="a" value="import"><p>列の順：会社名, 許可番号, 許可行政庁（知事/大臣）, 一般・特定, 業種, 許可日（2021-11-25）, 決算月（数字）, 連絡先, メモ。1行目が見出しなら飛ばします。Excel の CSV（Shift_JIS）も読めます。</p><input type="file" name="csv" accept=".csv,text/csv" required> <button class="btn">取り込む</button></form>'
       . '<h2>CSV で書き出す</h2><p><a class="btn sub" href="' . h(self_url(['p' => 'csv'])) . '">一覧を CSV で書き出す（期限つき）</a></p>'
       . '<h2>カレンダーで購読する</h2><div class="panel"><p>Google カレンダーなどの「URL で追加」に、次の URL を入れると、更新申請の期限・満了日・決算変更届の期限が予定として入り、登録や変更も自動で反映されます（14日前の通知つき）。この URL を知っている人は期限を見られるので、外に出さないでください。</p><p><code>' . h($base . '?ics=' . ics_key()) . '</code></p></div>'
       . '<h2>期限の前にメールで知らせる</h2><div class="panel"><p>期限の ' . h(implode('・', (array)cfg('notify_days'))) . '日前と、期限を過ぎたときに、まとめて1通送ります（同じ知らせは2度送りません）。サーバーの cron で1日1回、次の URL を呼んでください。</p>'
       . ((string)cfg('cron_key') !== '' ? '<p><code>' . h($base . '?cron=' . cfg('cron_key')) . '</code></p>' : '<p class="ng">kkensetsu_config.php の cron_key と notify_to を設定すると使えます。</p>') . '<p class="src">宛先：' . h(cfg('notify_to') ?: '未設定') . (cfg('demo') ? '（デモでは送信しません）' : '') . '</p></div>';
    page('取り込み・通知', $b); exit;
}

// 一覧
$rows = all_rows();
$soon = (int)($_GET['soon'] ?? 0); $q = trim((string)($_GET['q'] ?? ''));
$late = count(array_filter($rows, fn($r) => $r['c']['next'] && $r['c']['next']['left'] < 0));
$in30 = count(array_filter($rows, fn($r) => $r['c']['next'] && $r['c']['next']['left'] >= 0 && $r['c']['next']['left'] <= 30));
$in90 = count(array_filter($rows, fn($r) => $r['c']['next'] && $r['c']['next']['left'] > 30 && $r['c']['next']['left'] <= 90));
if ($soon) { $rows = array_values(array_filter($rows, fn($r) => $r['c']['next'] && $r['c']['next']['left'] <= $soon)); }
if ($q !== '') { $rows = array_values(array_filter($rows, fn($r) => mb_stripos($r['company'] . $r['permit_no'] . $r['gyoshu'] . $r['memo'], $q) !== false)); }
$b = '<h1>期限の一覧</h1><div class="big"><div><span class="k">期限切れ</span><span class="v' . ($late ? ' ng' : '') . '">' . $late . '件</span></div><div><span class="k">30日以内</span><span class="v' . ($in30 ? ' ng' : '') . '">' . $in30 . '件</span></div><div><span class="k">31〜90日</span><span class="v' . ($in90 ? ' warn' : '') . '">' . $in90 . '件</span></div><div><span class="k">登録している許可</span><span class="v">' . (int)db()->query('SELECT COUNT(*) FROM permit')->fetchColumn() . '件</span></div></div>';
$b .= '<div class="filters">';
foreach ([0 => 'すべて', 30 => '30日以内', 90 => '90日以内', 180 => '180日以内'] as $k => $l) { $b .= '<a class="' . ($soon === $k ? 'on' : '') . '" href="' . h(self_url(array_filter(['soon' => $k ?: null, 'q' => $q ?: null]))) . '">' . $l . '</a>'; }
$b .= '<form method="get" style="margin-left:auto">' . ($soon ? '<input type="hidden" name="soon" value="' . $soon . '">' : '') . '<input name="q" value="' . h($q) . '" placeholder="会社名・業種で探す" aria-label="会社名・業種で探す" style="width:13em"> <button class="btn s">探す</button></form></div>';
$b .= '<div class="list">';
foreach ($rows as $r) {
    $c = $r['c']; $li = '';
    foreach ($c['items'] as $it) {
        $t = ($it['kind'] === 'renew' && $it['left'] < 0 && !$c['expired']) ? '<span class="tag t-ng">申請期限を過ぎた・満了まであと' . days_to($c['exp']) . '日</span>' : tag($it['left']);
        $li .= '<li>' . $t . ' ' . h($it['label']) . '：<b>' . jd($it['date']) . '（' . wd($it['date']) . '）</b></li>';
    }
    if ($c['renewed']) { $li .= '<li><span class="tag t-ok">更新申請済み</span> ' . jd((string)$r['renewed_at']) . ' に提出（新しい許可が下りたら許可日を直す）</li>'; }
    $li .= '<li class="src">有効期間の満了日 ' . jd($c['exp']) . ($c['expired'] ? ' <b class="ng">（満了日を過ぎています）</b>' : '') . '</li>';
    $act = '';
    if (!$c['renewed']) { $act .= '<form method="post" class="inline">' . hid() . '<input type="hidden" name="a" value="renewed"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sub s">更新申請を出した</button></form>'; }
    if ($c['kessan']) { $act .= '<form method="post" class="inline">' . hid() . '<input type="hidden" name="a" value="kessan"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="end" value="' . h($c['kessan']['end']) . '"><button class="btn sub s">決算変更届を出した</button></form>'; }
    $act .= '<a class="btn s" href="' . h(self_url(['p' => 'edit', 'id' => $r['id']])) . '">直す</a>';
    $b .= '<div class="item"><div><div class="co">' . h($r['company']) . '</div><div class="src">' . h(trim($r['permit_no'] . '　' . $r['kind'] . '　' . $r['gyoshu'])) . '</div><div class="src">許可日 ' . jd($r['permit_date']) . ($r['kessan_month'] ? '・' . (int)$r['kessan_month'] . '月決算' : '') . ($r['contact'] ? '・' . h($r['contact']) : '') . '</div>' . ($r['memo'] ? '<div class="src">メモ：' . h($r['memo']) . '</div>' : '') . '</div><ul>' . $li . '</ul><div class="act">' . $act . '</div></div>';
}
if (!$rows) { $b .= '<p class="panel">該当する許可はありません。<a href="' . h(self_url(['p' => 'edit'])) . '">許可を登録する</a>か、<a href="' . h(self_url(['p' => 'tools'])) . '">CSV で取り込んで</a>ください。</p>'; }
$b .= '</div>';
page('期限の一覧', $b);
