<?php
/**
 * Kurage 建設業許可 更新期限チェック（kkensetsu）― 1ファイルPHP。
 *
 * 建設会社と行政書士が「許可の有効期間はいつ満了するか」「更新の申請はいつまでに出すか」
 * 「決算変更届はいつまでか」を、許可日と決算日から計算する。複数の許可（会社）を1行ずつ入れると、
 * 期限の近い順に並べる。計算はブラウザの中だけで行い、入力はサーバーへ送らない。
 *
 *   /kkensetsu.php/            入口（計算）・47都道府県の一覧
 *   /kkensetsu.php/p/<コード>/  都道府県ごとの更新の窓口・手数料・電子申請（例 /p/23/ 愛知県）
 *   /kkensetsu.php/about       根拠（条文）・計算のしかた・しないこと
 *   /kkensetsu.php/sitemap.xml /robots.txt /llms.txt
 *
 * 根拠（2026-10-06 に e-Gov 法令API の本文で確認）
 *   建設業法 第3条第3項（5年ごとに更新・期間の経過で効力を失う）・第3条第4項（申請中は処分まで従前の許可が有効）
 *   建設業法施行規則 第5条（有効期間満了の日の30日前までに許可申請書を提出）
 *   建設業法 第11条第2項（決算変更届：毎事業年度経過後4月以内）
 *   建設業法施行令 第4条（大臣許可の更新の許可手数料 5万円・収入印紙）
 *   地方公共団体の手数料の標準に関する政令（知事許可の更新の審査 5万円が標準。実際の額は都道府県の条例）
 *   行政機関の休日に関する法律 第2条（期限が行政機関の休日に当たるときは翌日）
 *   国土交通省関東地方整備局「建設業許可申請・変更の手引き」（満了日の数え方と、休日の扱い）
 * 都道府県ごとの窓口・納め方は kkensetsu_prefs.json（各都道府県の公式ページで確認した値・確認日つき）。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

const SITE = 'https://kurage.exbridge.jp';
const BASE = SITE . '/kkensetsu.php';
const NAME = 'Kurage 建設業許可 更新期限チェック';
const CHECKED = '2026-10-06';
const BUY = '';   // 商品ページができたら URL を入れる
const SRC_TEBIKI = 'https://www.ktr.mlit.go.jp/ktr_content/content/000827696.pdf';
const SRC_HO = 'https://laws.e-gov.go.jp/law/324AC0000000100';
const SRC_KISOKU = 'https://laws.e-gov.go.jp/law/324M50004000014';
const SRC_REI = 'https://laws.e-gov.go.jp/law/331CO0000000273';
const SRC_TESURYO = 'https://laws.e-gov.go.jp/law/412CO0000000016';
const SRC_KYUJITSU = 'https://laws.e-gov.go.jp/law/363AC0000000091';
const SRC_SHUKU = 'https://www8.cao.go.jp/chosei/shukujitsu/gaiyou.html';

const PREFS = ['01'=>'北海道','02'=>'青森県','03'=>'岩手県','04'=>'宮城県','05'=>'秋田県','06'=>'山形県','07'=>'福島県','08'=>'茨城県','09'=>'栃木県','10'=>'群馬県','11'=>'埼玉県','12'=>'千葉県','13'=>'東京都','14'=>'神奈川県','15'=>'新潟県','16'=>'富山県','17'=>'石川県','18'=>'福井県','19'=>'山梨県','20'=>'長野県','21'=>'岐阜県','22'=>'静岡県','23'=>'愛知県','24'=>'三重県','25'=>'滋賀県','26'=>'京都府','27'=>'大阪府','28'=>'兵庫県','29'=>'奈良県','30'=>'和歌山県','31'=>'鳥取県','32'=>'島根県','33'=>'岡山県','34'=>'広島県','35'=>'山口県','36'=>'徳島県','37'=>'香川県','38'=>'愛媛県','39'=>'高知県','40'=>'福岡県','41'=>'佐賀県','42'=>'長崎県','43'=>'熊本県','44'=>'大分県','45'=>'宮崎県','46'=>'鹿児島県','47'=>'沖縄県'];

// 国民の祝日（内閣府 syukujitsu.csv・公表されている2027年まで）。2028年以降は土日と年末年始だけで判定する
const HOLIDAYS = ['2026-01-01','2026-01-12','2026-02-11','2026-02-23','2026-03-20','2026-04-29','2026-05-03','2026-05-04','2026-05-05','2026-05-06','2026-07-20','2026-08-11','2026-09-21','2026-09-22','2026-09-23','2026-10-12','2026-11-03','2026-11-23',
                  '2027-01-01','2027-01-11','2027-02-11','2027-02-23','2027-03-21','2027-03-22','2027-04-29','2027-05-03','2027-05-04','2027-05-05','2027-07-19','2027-08-11','2027-09-20','2027-09-23','2027-10-11','2027-11-03','2027-11-23'];
const HOLIDAYS_UNTIL = '2027-12-31';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(string $p): string { return BASE . $p; }
function jd(string $ymd): string { [$y, $m, $d] = array_map('intval', explode('-', $ymd)); return $y . '年' . $m . '月' . $d . '日'; }
function ours(): bool { return ($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp'; }   // 当社の公開先だけ。配布版を置いたサイトには当社の誘導・計測を出さない

/** 都道府県ごとの窓口・手数料の納め方・電子申請（各都道府県の公式ページで確認した値） */
function prefs(): array {
    static $p = null;
    if ($p === null) {
        $p = [];
        $f = __DIR__ . '/kkensetsu_prefs.json';
        foreach ((is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []) as $r) { $p[sprintf('%02d', (int)$r['code'])] = $r; }
    }
    return $p;
}
function pinfo(string $c): array { return prefs()[$c] ?? []; }

// ---------------- 画面の骨格 ----------------
function page(string $title, string $desc, string $url, string $body, array $ld = [], array $crumbs = []): void {
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => NAME, 'url' => BASE . '/',
             'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => NAME, 'item' => BASE . '/']];
        foreach ($crumbs as $i => [$n, $l]) { $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $n, 'item' => $l]; }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/kkensetsu.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="color-scheme" content="light">';
    echo '<link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--navy:#1b2a3a;--blue:#0f5f8c;--blue-l:#e8f2f8;--ink:#1f2933;--sub:#5b6676;--line:#dde3ea;--bg:#f6f8fa;--ok:#047857;--ok-l:#e7f6ef;--ng:#b42318;--ng-l:#fdecea;--amber:#9a6700;--amber-l:#fff6e0;--hard:#d97706}
*{box-sizing:border-box}html{color-scheme:light}body{margin:0;background:#fff;color:var(--ink);font:15.5px/1.75 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}
a{color:var(--blue);overflow-wrap:anywhere}.wrap{max-width:980px;margin:0 auto;padding:0 16px;min-width:0}
header.top{border-bottom:1px solid var(--line);background:#fff}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:58px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800;font-size:16px;line-height:1.3}.brand img{width:34px;height:34px;object-fit:contain;flex:none}.brand small{display:block;color:var(--hard);font-size:11.5px;font-weight:700}
nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.promo{margin-left:auto;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:13px;font-weight:800}.promo a{color:#b45309;text-decoration:none}
main{padding:10px 0 40px}h1{font-size:clamp(22px,4vw,30px);line-height:1.4;color:var(--navy);margin:18px 0 8px;text-wrap:balance}
h2{font-size:19px;color:var(--navy);margin:30px 0 10px;border-left:5px solid var(--hard);padding-left:10px}h3{font-size:16px;color:var(--navy);margin:18px 0 6px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:16px;margin:12px 0;min-width:0}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:10px;margin:12px 0}.big div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;min-width:0}
.big .k{display:block;font-size:12.5px;color:var(--sub);font-weight:700}.big .v{display:block;font-size:22px;font-weight:800;color:var(--navy);line-height:1.35;font-variant-numeric:tabular-nums}.big .s{display:block;font-size:12.5px;color:var(--sub)}
.ok{color:var(--ok)}.ng{color:var(--ng)}.warn{color:var(--amber)}.big .v.ng{color:var(--ng)}.big .v.warn{color:var(--amber)}.box-ok{background:var(--ok-l);border:1px solid #b7e4cf;border-radius:10px;padding:10px 14px}.box-ng{background:var(--ng-l);border:1px solid #f3c2bd;border-radius:10px;padding:10px 14px}
.note{background:var(--amber-l);border-left:4px solid var(--amber);border-radius:8px;padding:10px 12px;font-size:14px;margin:10px 0}.src{font-size:12.5px;color:var(--sub)}
label{font-weight:700;font-size:14px;display:block;margin:10px 0 4px}select,input,textarea{font:inherit;font-size:16px;padding:9px 11px;border:2px solid var(--line);border-radius:10px;max-width:100%;background:#fff}
textarea{width:100%;min-height:150px;font-variant-numeric:tabular-nums}.row{display:flex;flex-wrap:wrap;gap:10px 18px}.row>div{min-width:0}
.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:700;text-decoration:none;cursor:pointer;font-size:15px}.btn.sub{background:#fff;color:var(--blue);border:2px solid var(--blue)}
.tbl{overflow-x:auto;-webkit-overflow-scrolling:touch}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}th{color:var(--sub);white-space:nowrap}
td.n{white-space:nowrap;font-variant-numeric:tabular-nums}.tbl table{min-width:620px}
.tag{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12.5px;font-weight:800;white-space:nowrap}.t-ng{background:var(--ng-l);color:var(--ng)}.t-warn{background:var(--amber-l);color:var(--amber)}.t-ok{background:var(--ok-l);color:var(--ok)}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:10px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;min-width:0}.card b{color:var(--navy)}.card p{margin:4px 0 8px;font-size:14px;color:var(--sub)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr));gap:8px}.grid a{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;text-decoration:none;color:var(--ink)}.grid a b{color:var(--navy)}.grid a small{display:block;color:var(--sub);font-size:12px}
dl.kv{display:grid;grid-template-columns:minmax(0,9em) minmax(0,1fr);gap:6px 14px;margin:8px 0}dl.kv dt{color:var(--sub);font-weight:700;font-size:14px}dl.kv dd{margin:0;min-width:0}
details{border-bottom:1px solid var(--line);padding:8px 0}summary{cursor:pointer;font-weight:700}
#out{margin-top:14px}#out .err{color:var(--ng);font-weight:700}
footer{margin-top:40px;border-top:1px solid var(--line);padding:18px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
.xshare{display:inline-flex;align-items:center;gap:8px;background:#0f1419;color:#fff;text-decoration:none;font-weight:800;font-size:14px;border-radius:999px;padding:8px 16px}
@media (max-width:480px){dl.kv{grid-template-columns:1fr}dl.kv dd{margin-bottom:6px}}
@media (max-width:600px){.res table{min-width:0}.res table,.res tbody,.res tr,.res td{display:block}.res tr:first-child{display:none}.res tr{border-bottom:1px solid var(--line);padding:10px 0}.res td{border:0;padding:2px 0;white-space:normal}.res td[data-k]{display:grid;grid-template-columns:7.5em minmax(0,1fr);gap:8px}.res td[data-k]::before{content:attr(data-k);color:var(--sub);font-size:13px;font-weight:700}}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="34" height="34" alt="株式会社エクスブリッジ"><span>' . h(NAME) . '<small>有効期間・更新申請・決算変更届の期限</small></span></a>';
    echo '<nav class="menu"><a href="' . u('/') . '">計算する</a><a href="' . u('/#list') . '">都道府県の窓口</a><a href="' . u('/about') . '">根拠と計算</a></nav>';
    if (ours()) {
        echo '<!--kurage-only--><span class="promo"><a href="https://exbridge.jp/ai-it-komon.html?ref=kkensetsu-head-komon" target="_blank" rel="noopener">AI-IT顧問</a><a href="https://kurage.exbridge.jp/vibe-customize.html?ref=kkensetsu-head-vibe" target="_blank" rel="noopener">バイブカスタマイズ</a><a href="https://kurage.exbridge.jp/reseller.html?ref=kkensetsu-head-reseller" target="_blank" rel="noopener">販売代理店募集</a></span><!--/kurage-only-->';
    }
    echo '</div></header><main class="wrap">' . $body . '</main>';
    echo '<footer><div class="wrap">';
    echo '<p>根拠：<a href="' . SRC_HO . '" rel="noopener">建設業法</a> 第3条第3項・第11条第2項、<a href="' . SRC_KISOKU . '" rel="noopener">建設業法施行規則</a> 第5条、<a href="' . SRC_KYUJITSU . '" rel="noopener">行政機関の休日に関する法律</a> 第2条、国土交通省関東地方整備局「<a href="' . SRC_TEBIKI . '" rel="noopener">建設業許可申請・変更の手引き</a>」（' . jd(CHECKED) . '確認）。</p>';
    echo '<p>このページは期限の目安です。申請の受付・必要書類は許可行政庁（都道府県・地方整備局）の手引きで確認してください。入力した許可日・決算日はブラウザの中だけで計算し、送信・保存しません。当社は申請書の作成・申請の代行はしません（行政書士の業務です）。</p>';
    echo '<p><a href="https://exbridge.jp/?ref=kkensetsu">株式会社エクスブリッジ</a>（名古屋・業務システム開発）・<a href="' . SITE . '/kseidocal.php/?ref=kkensetsu">経営者の制度カレンダー</a>・<a href="' . SITE . '/ktoriteki.php/?ref=kkensetsu">Kurage 取適法チェック</a>・<a href="' . SITE . '/kchingin.php/?ref=kkensetsu">最低賃金・賃上げ助成金チェック</a>・<a href="' . SITE . '/khojokin.php/?ref=kkensetsu">Kurage 補助金ナビ</a></p>';
    if (ours() && BUY !== '') echo '<p><a href="' . h(BUY) . '&amp;ref=kkensetsu" rel="noopener">このページの一式を自社サイトに置く（商品ページ・ソースコード付き）</a></p>';
    echo '</div></footer>';
    if (ours()) {
        echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '&ref=' . rawurlencode((string)($_GET['ref'] ?? '')) . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
        echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    }
    echo '</body></html>';
}

// ---------------- 計算画面 ----------------
/** 入力例：期限の状態がそろって見えるよう、今日から数えた許可日で作る（人が書き換えるまで） */
function sample_rows(): array {
    $t = strtotime(date('Y-m-d'));
    $mk = fn(string $name, string $rel, string $kessan) => $name . ' ' . date('Y-m-d', strtotime($rel, $t)) . ' ' . $kessan;
    return [
        $mk('（例）丸山建設', '-5 years +50 days', '3'),
        $mk('（例）東工務店', '-5 years +20 days', '6'),
        $mk('（例）中央設備', '-3 years', '9'),
        $mk('（例）北電工', '-5 years +200 days', '12'),
    ];
}

function calc_form(): string {
    return '<div class="panel" id="calc">'
        . '<label for="rows">許可ごとに1行（会社名・許可日・決算月）。例：「丸山建設 2021-11-25 3」。会社名は省けます</label>'
        . '<textarea id="rows" spellcheck="false">' . h(implode("\n", sample_rows())) . '</textarea>'
        . '<div class="row"><div><label for="soon">「近い」とする日数</label><input id="soon" type="number" min="1" max="365" value="90" style="width:6em"> 日以内</div></div>'
        . '<p style="margin-top:12px"><button class="btn" id="go" type="button">期限を計算する</button> <button class="btn sub" id="ics" type="button">期限をカレンダーに入れる（.ics）</button></p>'
        . '<p class="src">許可日は許可通知書（許可証明書）にある日付。決算月は事業年度の最後の月（3月決算なら 3）。日付は 2021-11-25・2021/11/25・R3.11.25 のどれでも入ります。入力はこの画面の中だけで計算し、送信しません。</p>'
        . '<div id="out" aria-live="polite"></div></div>';
}

function calc_js(): string {
    $data = json_encode(['hol' => HOLIDAYS, 'holUntil' => HOLIDAYS_UNTIL, 'today' => date('Y-m-d')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return <<<JS
<script>
(function(){
var D={$data},HOL={};D.hol.forEach(function(d){HOL[d]=1});
function p2(n){return (n<10?'0':'')+n}
function ymd(d){return d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate())}
function mk(y,m,d){return new Date(y,m-1,d)}
function jd(s){var a=s.split('-');return (+a[0])+'年'+(+a[1])+'月'+(+a[2])+'日'}
function wd(s){var a=s.split('-');return '日月火水木金土'.charAt(mk(+a[0],+a[1],+a[2]).getDay())}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function days(a,b){var x=a.split('-'),y=b.split('-');return Math.round((mk(+y[0],+y[1],+y[2])-mk(+x[0],+x[1],+x[2]))/86400000)}
/* 行政機関の休日（行政機関の休日に関する法律 第1条）：土日・国民の祝日・12月29日〜1月3日 */
function closed(d){var w=d.getDay(),m=d.getMonth()+1,dd=d.getDate();return w===0||w===6||HOL[ymd(d)]||(m===12&&dd>=29)||(m===1&&dd<=3)}
function nextOpen(s){var a=s.split('-'),d=mk(+a[0],+a[1],+a[2]),moved=false;while(closed(d)){d.setDate(d.getDate()+1);moved=true}return {d:ymd(d),moved:moved}}
function lastDay(y,m){return new Date(y,m,0).getDate()}
/* 和暦・区切りのゆれを吸収して YYYY-MM-DD に */
function pdate(t){
  t=t.replace(/[０-９]/g,function(c){return String.fromCharCode(c.charCodeAt(0)-65248)}).replace(/[．。]/g,'.');
  var m=t.match(/(?:令和|R)\s*(\d{1,2})[.\/年-](\d{1,2})[.\/月-](\d{1,2})/i);if(m)return [2018+(+m[1]),+m[2],+m[3]];
  m=t.match(/(?:平成|H)\s*(\d{1,2})[.\/年-](\d{1,2})[.\/月-](\d{1,2})/i);if(m)return [1988+(+m[1]),+m[2],+m[3]];
  m=t.match(/(\d{4})[.\/年-](\d{1,2})[.\/月-](\d{1,2})/);if(m)return [+m[1],+m[2],+m[3]];
  return null}
function parse(text){var out=[],bad=[];text.split(/\\r?\\n/).forEach(function(l,i){
  var s=l.trim();if(!s)return;var dt=pdate(s);if(!dt){bad.push(i+1);return}
  var y=dt[0],mo=dt[1],da=dt[2];if(mo<1||mo>12||da<1||da>lastDay(y,mo)){bad.push(i+1);return}
  var rest=s.replace(/(?:令和|平成|R|H)?\s*\d{1,4}[.\/年-]\d{1,2}[.\/月-]\d{1,2}日?/i,' ').trim(),km=rest.match(/(\d{1,2})\s*月?\s*(?:決算)?\s*$/),k=null;
  if(km&&+km[1]>=1&&+km[1]<=12){k=+km[1];rest=rest.slice(0,km.index).trim()}
  out.push({name:rest||('許可'+(out.length+1)),y:y,m:mo,d:da,k:k})});return {rows:out,bad:bad}}
/* 有効期間：許可日から5年目を経過する日の前日で満了（休日でも動かない）。2月29日の許可は応当日が無いので月末を応当日として前日 */
function expiry(r){var y=r.y+5,d=Math.min(r.d,lastDay(y,r.m)),e=mk(y,r.m,d);e.setDate(e.getDate()-1);return ymd(e)}
/* 更新申請：満了日の30日前まで（施行規則5条）。その日が休日なら直後の開庁日 */
function applyBy(exp){var a=exp.split('-'),d=mk(+a[0],+a[1],+a[2]);d.setDate(d.getDate()-30);return nextOpen(ymd(d))}
/* 決算変更届：事業年度経過後4月以内（法11条2項）。月末決算として、4か月後の月末。休日なら翌開庁日 */
function kessan(k,from){var a=from.split('-'),y=+a[0],out=[];for(var yy=y-1;yy<=y+1;yy++){var m=k+4,Y=yy;if(m>12){m-=12;Y++}out.push({end:ymd(mk(yy,k,lastDay(yy,k))),due:nextOpen(ymd(mk(Y,m,lastDay(Y,m))))})}
  return out.filter(function(x){return x.due.d>=from})[0]}
function rowsOf(){
  var P=parse(document.getElementById('rows').value),T=D.today,soon=+document.getElementById('soon').value||90,items=[];
  P.rows.forEach(function(r){
    var exp=expiry(r),ab=applyBy(exp),st,cls,left=days(T,ab.d),el=days(T,exp);
    if(el<0){st='有効期間が過ぎています';cls='t-ng'}
    else if(left<0){st='30日前を過ぎています';cls='t-ng'}
    else if(left<=soon){st='あと'+left+'日';cls='t-warn'}
    else{st='あと'+left+'日';cls='t-ok'}
    var ks=r.k?kessan(r.k,T):null;
    items.push({r:r,exp:exp,ab:ab,st:st,cls:cls,left:left,el:el,ks:ks,kleft:ks?days(T,ks.due.d):null});
  });
  items.sort(function(a,b){var x=Math.min(a.left,a.kleft===null?1e9:a.kleft),y=Math.min(b.left,b.kleft===null?1e9:b.kleft);return x-y});
  return {items:items,bad:P.bad,soon:soon}}
function run(){
  var R=rowsOf(),o=document.getElementById('out'),h='';
  if(!R.items.length){o.innerHTML='<p class="err">許可日を1行に1件ずつ入れてください（例：丸山建設 2021-11-25 3）。</p>';return}
  var late=R.items.filter(function(x){return x.cls==='t-ng'}).length,warn=R.items.filter(function(x){return x.cls==='t-warn'}).length,
      kw=R.items.filter(function(x){return x.kleft!==null&&x.kleft<=R.soon}).length;
  h+='<div class="big"><div><span class="k">更新申請の期限を過ぎた・有効期間切れ</span><span class="v'+(late?' ng':'')+'">'+late+'件</span><span class="s">'+R.items.length+'件中</span></div>'
    +'<div><span class="k">更新申請の期限が'+R.soon+'日以内</span><span class="v'+(warn?' warn':'')+'">'+warn+'件</span><span class="s">早めに書類の準備を</span></div>'
    +'<div><span class="k">決算変更届の期限が'+R.soon+'日以内</span><span class="v">'+kw+'件</span><span class="s">決算月を入れた許可だけ</span></div></div>';
  h+='<div class="tbl res"><table><tr><th>許可</th><th>更新申請の期限</th><th>状態</th><th>有効期間の満了日</th><th>次の決算変更届</th></tr>'
    +R.items.map(function(x){return '<tr><td><b>'+esc(x.r.name)+'</b><br><span class="src">許可日 '+jd(ymd(mk(x.r.y,x.r.m,x.r.d)))+'</span></td>'
      +'<td class="n" data-k="更新申請の期限"><span><b>'+jd(x.ab.d)+'（'+wd(x.ab.d)+'）</b>'+(x.ab.moved?'<br><span class="src">30日前が休日のため翌開庁日</span>':'')+'</span></td>'
      +'<td data-k="状態"><span><span class="tag '+x.cls+'">'+x.st+'</span></span></td>'
      +'<td class="n" data-k="満了日"><span>'+jd(x.exp)+'（'+wd(x.exp)+'）</span></td>'
      +'<td class="n" data-k="決算変更届"><span>'+(x.ks?jd(x.ks.due.d)+'<br><span class="src">'+(+x.ks.end.split('-')[1])+'月決算（'+x.ks.end.split('-')[0]+'年）の分・あと'+x.kleft+'日</span>':'<span class="src">決算月なし</span>')+'</span></td></tr>'}).join('')+'</table></div>';
  if(R.bad.length)h+='<p class="err">'+R.bad.join('・')+'行目は日付が読めませんでした。</p>';
  if(R.items.some(function(x){return x.ab.d>D.holUntil||(x.ks&&x.ks.due.d>D.holUntil)}))h+='<p class="src">国民の祝日は、内閣府が公表している'+D.holUntil.slice(0,4)+'年までを反映しています。それより先の期限は土日と年末年始（12月29日〜1月3日）だけで判定しているので、祝日に当たる場合は1日ずれます。</p>';
  if(late)h+='<p class="box-ng">「30日前を過ぎています」の許可も、<b>有効期間の満了日までに更新の申請が受け付けられれば</b>、審査中は従前の許可が有効です（建設業法第3条第4項）。受け付けてもらえるかは許可行政庁で違うので、すぐ窓口に相談してください。満了日を過ぎると許可は効力を失い、500万円以上の工事（建築一式は1,500万円以上など）を請け負えなくなります。</p>';
  h+='<h3>期限の管理を、事務所のシステムにする</h3><p>この画面は入力した分をその場で計算するだけで、保存も通知もしません。顧客の建設会社ごとに許可・決算・技術者の変更をまとめて持ち、期限の前に知らせる仕組みは、当社が事務所のサーバーに置く形で作ります（月額なし）。</p>'
    +'<p><a class="btn sub" href="https://exbridge.jp/ai-it-komon.html?ref=kkensetsu-calc" target="_blank" rel="noopener">期限管理の仕組みを相談する（AI-IT顧問）</a></p>';
  o.innerHTML=h;
}
function ics(){
  var R=rowsOf();if(!R.items.length){run();return}
  var L=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//exbridge//kkensetsu//JA','CALSCALE:GREGORIAN'],n=0,stamp=D.today.replace(/-/g,'')+'T000000Z';
  function ev(d,t){var s=d.replace(/-/g,''),a=d.split('-'),e=mk(+a[0],+a[1],+a[2]);e.setDate(e.getDate()+1);
    L.push('BEGIN:VEVENT','UID:kkensetsu-'+s+'-'+(n++)+'@kurage.exbridge.jp','DTSTAMP:'+stamp,'DTSTART;VALUE=DATE:'+s,'DTEND;VALUE=DATE:'+ymd(e).replace(/-/g,''),'SUMMARY:'+t.replace(/[,;]/g,' '),
      'BEGIN:VALARM','TRIGGER:-P14D','ACTION:DISPLAY','DESCRIPTION:'+t.replace(/[,;]/g,' '),'END:VALARM','END:VEVENT')}
  R.items.forEach(function(x){ev(x.ab.d,x.r.name+' 建設業許可の更新申請の期限');ev(x.exp,x.r.name+' 建設業許可の有効期間の満了日');if(x.ks)ev(x.ks.due.d,x.r.name+' 決算変更届の期限')});
  L.push('END:VCALENDAR');
  var b=new Blob([L.join('\\r\\n')],{type:'text/calendar'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='kensetsu-kigen.ics';document.body.appendChild(a);a.click();a.remove();
}
document.getElementById('go').addEventListener('click',run);
document.getElementById('ics').addEventListener('click',ics);
run();
})();
</script>
JS;
}

function faq(array $qa): array {
    $html = '';
    $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach ($qa as [$q, $a]) {
        $html .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>';
        $ld['mainEntity'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return [$html, $ld];
}

function xshare(string $text, string $path): string {
    $q = http_build_query(['text' => $text, 'url' => BASE . $path . '?ref=x-share-kkensetsu', 'hashtags' => '建設業許可']);
    return '<p><a class="xshare" href="https://x.com/intent/post?' . h($q) . '" target="_blank" rel="noopener">𝕏 このページをXで共有</a></p>';
}

function pref_grid(): string {
    $g = '';
    foreach (PREFS as $c => $n) {
        $c = sprintf('%02d', $c); $i = pinfo($c);
        $g .= '<a href="' . u('/p/' . $c . '/') . '"><b>' . h($n) . '</b><small>' . h($i['madoguchi'] ?? '更新の窓口・手数料') . '</small></a>';
    }
    return '<div class="grid">' . $g . '</div>';
}

// ---------------- ルーティング ----------------
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && !str_ends_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/') && !str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '?')) {
    header('Location: ' . BASE . '/', true, 302); exit;   // 301 は使わない
}

if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: " . u('/sitemap.xml') . "\n"; exit; }

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (array_merge(['/', '/about'], array_map(fn($c) => '/p/' . sprintf('%02d', $c) . '/', array_keys(PREFS))) as $p) { echo '<url><loc>' . h(u($p)) . '</loc><lastmod>' . CHECKED . '</lastmod></url>'; }
    echo '</urlset>'; exit;
}

if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    $l = ['# ' . NAME, '', '> 建設業許可の有効期間の満了日、更新申請の期限（満了の30日前・休日なら翌開庁日）、決算変更届の期限（事業年度経過後4か月以内）を、許可日と決算月から計算するサイト。複数の許可を1行ずつ入れると期限の近い順に並べ、.ics でカレンダーに入れられる。47都道府県の更新の窓口・手数料の納め方・電子申請の可否つき。株式会社エクスブリッジ製。', '',
          '## 期限の決まり（条文）',
          '- 有効期間：5年。許可日から5年目を経過する日の前日で満了し、満了日が休日でも動かない（建設業法第3条第3項・国交省関東地方整備局の手引き）',
          '- 更新申請：有効期間満了の日の30日前までに許可申請書を提出（建設業法施行規則第5条）。その日が行政庁の休日なら直後の開庁日まで',
          '- 申請中の効力：満了日までに更新を申請し、処分が満了日に間に合わないときは、処分まで従前の許可が有効（建設業法第3条第4項）',
          '- 決算変更届：毎事業年度経過後4月以内に提出（建設業法第11条第2項）',
          '- 手数料：大臣許可の更新は5万円（収入印紙・建設業法施行令第4条）。知事許可の更新は5万円が標準（地方公共団体の手数料の標準に関する政令）で、実際の額と納め方は都道府県の条例による',
          '', '## ページ', '- 計算: ' . u('/'), '- 都道府県ごと: ' . u('/p/23/') . '（愛知県）など47ページ', '- 根拠と計算: ' . u('/about')];
    echo implode("\n", $l) . "\n"; exit;
}

$seg = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));

// 都道府県ページ
if (($seg[0] ?? '') === 'p' && isset($seg[1]) && isset(PREFS[$seg[1]])) {
    $c = sprintf('%02d', (int)$seg[1]); $n = PREFS[$c]; $i = pinfo($c); $url = u('/p/' . $c . '/');
    $title = $n . 'の建設業許可 更新｜期限の計算と窓口・手数料';
    $desc = $n . '知事の建設業許可の更新：有効期間の満了日と更新申請の期限（満了の30日前）を許可日から計算。' . ($i['madoguchi'] ?? '') . 'など' . $n . 'の窓口、手数料の納め方、電子申請（JCIP）の可否と公式の手引きへのリンク。';
    $body = '<h1>' . h($n) . 'の建設業許可の更新：期限と窓口</h1>';
    $body .= '<p class="lead">' . h($n) . '知事の許可は5年ごとに更新が必要です。有効期間は許可日から5年目の前日で満了し、更新の申請は<b>満了日の30日前まで</b>に出します。許可日を入れると期限を計算します。</p>';
    $kv = '<dl class="kv">';
    $kv .= '<dt>更新の申請先</dt><dd>' . ($i['madoguchi'] ?? null ? h($i['madoguchi']) : '<span class="src">公式の手引きで確認してください</span>') . '</dd>';
    $kv .= '<dt>更新の手数料</dt><dd>5万円（標準額。' . (($i['fee_payment'] ?? null) ? '納め方：' . h($i['fee_payment']) : '納め方は公式の手引きで確認') . '）</dd>';
    $j = $i['jcip'] ?? null;
    $kv .= '<dt>電子申請（JCIP）</dt><dd>' . ($j === true ? '<span class="ok">受け付けています</span>' : ($j === false ? '受け付けていません（書面）' : '<span class="src">確認できませんでした</span>')) . '</dd>';
    $kv .= '<dt>公式のページ</dt><dd>' . (($i['url'] ?? null) ? '<a href="' . h($i['url']) . '" rel="noopener">' . h($n) . ' 建設業許可</a>' : '<span class="src">—</span>') . (($i['tebiki_url'] ?? null) ? '・<a href="' . h($i['tebiki_url']) . '" rel="noopener">申請の手引き</a>' : '') . '</dd>';
    if ($i['note'] ?? null) { $kv .= '<dt>補足</dt><dd>' . h($i['note']) . '</dd>'; }
    $kv .= '</dl>';
    $body .= '<div class="panel">' . $kv . '<p class="src">' . h($n) . 'の公式ページで' . (isset($i['checked_at']) ? jd($i['checked_at']) : jd(CHECKED)) . 'に確認した内容です。窓口や様式は変わることがあるので、申請の前に公式の手引きを見てください。</p></div>';
    $body .= xshare($n . 'の建設業許可、更新申請の期限は「満了日の30日前」。許可日を入れると満了日・更新期限・決算変更届の期限が出ます', '/p/' . $c . '/');
    $body .= '<h2>許可日から期限を計算する</h2>' . calc_form();
    [$fh, $fl] = faq([
        [$n . 'の建設業許可の更新はいつまでに申請しますか？', '有効期間が満了する日の30日前までです（建設業法施行規則第5条）。その日が土日・祝日・年末年始なら直後の開庁日までです。有効期間は許可日から5年目を経過する日の前日で満了します。'],
        [$n . 'の建設業許可の更新手数料はいくらですか？', '知事許可の更新は5万円が標準です（地方公共団体の手数料の標準に関する政令）。' . (($i['fee_payment'] ?? null) ? $n . 'では' . $i['fee_payment'] . 'で納めます。' : '納め方は' . $n . 'の手引きで確認してください。')],
        ['30日前を過ぎてしまったら、どうなりますか？', '有効期間の満了日までに更新の申請が受け付けられれば、審査が満了日に間に合わなくても、処分までは従前の許可が有効です（建設業法第3条第4項）。受け付けの扱いは許可行政庁で違うので、すぐ窓口に相談してください。満了日を過ぎると許可は効力を失います。'],
        ['決算変更届の期限はいつですか？', '毎事業年度が終わってから4か月以内です（建設業法第11条第2項）。3月決算なら7月31日です。'],
    ]);
    $body .= '<h2>よくある質問</h2>' . $fh;
    $near = ''; $ci = (int)$c;
    foreach ([$ci - 1, $ci + 1] as $x) { if ($x >= 1 && $x <= 47) { $k = sprintf('%02d', $x); $near .= '<a href="' . u('/p/' . $k . '/') . '"><b>' . h(PREFS[$k]) . '</b><small>' . h(pinfo($k)['madoguchi'] ?? '更新の窓口・手数料') . '</small></a>'; } }
    $body .= '<h2>ほかの都道府県</h2><div class="grid">' . $near . '<a href="' . u('/#list') . '"><b>47都道府県の一覧</b><small>更新の窓口</small></a></div>';
    $body .= calc_js();
    page($title, $desc, $url, $body, [$fl], [[$n, $url]]);
    exit;
}

if (($seg[0] ?? '') === 'about') {
    $url = u('/about');
    $body = '<h1>根拠と計算のしかた</h1>'
        . '<h2>期限の根拠（条文）</h2><ul>'
        . '<li><b>有効期間は5年</b>：「五年ごとにその更新を受けなければ、その期間の経過によつて、その効力を失う」（<a href="' . SRC_HO . '" rel="noopener">建設業法</a> 第3条第3項）。満了日は「許可日から5年目を経過する日の前日」で、「当該期間の末日が日曜等の休日であってもその日をもって満了」します（国土交通省関東地方整備局「<a href="' . SRC_TEBIKI . '" rel="noopener">建設業許可申請・変更の手引き</a>」）。</li>'
        . '<li><b>更新の申請は満了日の30日前まで</b>：「有効期間満了の日の三十日前までに許可申請書を提出しなければならない」（<a href="' . SRC_KISOKU . '" rel="noopener">建設業法施行規則</a> 第5条）。この日が行政庁の休日なら直後の開庁日まで（同手引き・<a href="' . SRC_KYUJITSU . '" rel="noopener">行政機関の休日に関する法律</a> 第2条）。</li>'
        . '<li><b>申請中の効力</b>：満了日までに処分がされないときは、従前の許可は満了後も処分までは効力を有します（建設業法第3条第4項）。</li>'
        . '<li><b>決算変更届は4か月以内</b>：「毎事業年度経過後四月以内に、国土交通大臣又は都道府県知事に提出しなければならない」（建設業法第11条第2項）。</li>'
        . '<li><b>手数料</b>：大臣許可の更新は5万円を収入印紙で（<a href="' . SRC_REI . '" rel="noopener">建設業法施行令</a> 第4条）。知事許可の更新の審査は5万円が標準（<a href="' . SRC_TESURYO . '" rel="noopener">地方公共団体の手数料の標準に関する政令</a>）で、実際の額と納め方は都道府県の条例・手引きによります。</li></ul>'
        . '<h2>計算のしかた</h2><ul><li>満了日＝許可日の5年後の同じ日の前日（2月29日の許可は、5年後の2月の末日を応当日として、その前日）。</li><li>更新申請の期限＝満了日の30日前。土日・国民の祝日・12月29日〜1月3日に当たるときは、その後の最初の開庁日。祝日は内閣府の公表分（' . substr(HOLIDAYS_UNTIL, 0, 4) . '年まで）を使い、それより先は土日と年末年始だけで判定します。</li><li>決算変更届の期限＝決算月の末日の4か月後の月末（月末決算として計算）。休日なら翌開庁日。</li><li>許可を一本化（有効期間の調整）している場合は、一本化した後の許可日を入れてください。</li></ul>'
        . '<h2>都道府県ごとの情報</h2><p>窓口・手数料の納め方・電子申請（国土交通省「建設業許可・経営事項審査電子申請システム（JCIP）」）の受付は、各都道府県の公式ページで確認した内容で、ページごとに確認日を書いています。</p>'
        . '<h2>このサイトがしないこと</h2><p>申請書の作成・提出の代行はしません（行政書士の業務です）。計算は目安で、受付や必要書類は許可行政庁の手引きによります。入力した許可日・決算月はブラウザの中だけで計算し、サーバーへ送信・保存しません。</p>';
    page('根拠と計算のしかた｜' . NAME, '建設業許可の有効期間（5年・満了日は5年目の前日）、更新申請の期限（満了の30日前・休日は翌開庁日）、決算変更届（4か月以内）、手数料の根拠条文と、期限の計算方法。', $url, $body, [], [['根拠と計算', $url]]);
    exit;
}

if ($seg) { http_response_code(404); page('見つかりません｜' . NAME, '', BASE . '/', '<h1>見つかりません</h1><p><a class="btn" href="' . u('/') . '">入口へ戻る</a></p>'); exit; }

// トップ
$title = '建設業許可の更新期限を計算｜満了日・30日前・決算変更届';
$desc = '建設業許可は5年ごとに更新。許可日を入れると、有効期間の満了日、更新申請の期限（満了の30日前・休日は翌開庁日）、決算変更届の期限（4か月以内）を計算。複数の許可を期限の近い順に並べ、カレンダーにも入れられます。47都道府県の窓口つき。';
$body = '<h1>建設業許可の更新期限を、許可日から計算する</h1>';
$body .= '<p class="lead">建設業許可は5年ごとに更新が必要で、満了日を過ぎると許可は効力を失います。許可日と決算月を入れると、<b>有効期間の満了日・更新申請の期限（満了の30日前）・次の決算変更届の期限</b>が出ます。顧客の許可をまとめて入れれば、期限の近い順に並びます。</p>';
$body .= '<div class="big"><div><span class="k">有効期間</span><span class="v">5年</span><span class="s">許可日から5年目の前日で満了</span></div><div><span class="k">更新申請の期限</span><span class="v">満了の30日前</span><span class="s">休日なら直後の開庁日</span></div><div><span class="k">決算変更届</span><span class="v">4か月以内</span><span class="s">3月決算なら7月31日</span></div></div>';
$body .= calc_form();
$body .= xshare('建設業許可の更新期限、許可日を入れると満了日・30日前・決算変更届の期限が出ます。複数の許可を期限の近い順に並べられます', '/');
$body .= '<h2 id="list">都道府県ごとの更新の窓口・手数料・電子申請</h2>' . pref_grid();
$body .= '<p class="src">大臣許可（2以上の都道府県に営業所がある場合）は、主たる営業所を管轄する地方整備局等に申請します。</p>';
[$fh, $fl] = faq([
    ['建設業許可の有効期間はいつまでですか？', '5年です。許可日から5年目を経過する日の前日で満了し、満了日が日曜などの休日でも動きません（建設業法第3条第3項・国土交通省の手引き）。'],
    ['更新の申請はいつまでに出しますか？', '有効期間満了の日の30日前までです（建設業法施行規則第5条）。その日が土日・祝日・年末年始なら直後の開庁日までです。'],
    ['更新を忘れて満了日を過ぎたら、どうなりますか？', '許可は効力を失い、軽微な工事（1件500万円未満、建築一式は1,500万円未満など。建設業法施行令第1条の2）しか請け負えなくなります。改めて新規の許可を取ることになります。満了日までに更新の申請が受け付けられていれば、審査中は従前の許可が有効です（第3条第4項）。'],
    ['決算変更届はいつまでに出しますか？', '毎事業年度が終わってから4か月以内です（建設業法第11条第2項）。3月決算なら7月31日です。'],
    ['入力した内容はどこかに送られますか？', '送られません。計算はブラウザの中だけで行い、サーバーへ送信も保存もしません。'],
]);
$body .= '<h2>よくある質問</h2>' . $fh;
$body .= calc_js();
page($title, $desc, BASE . '/', $body, [$fl]);
