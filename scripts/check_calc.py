#!/usr/bin/env python3
"""kkensetsu の期限計算を、実際のブラウザで既知の答えと突き合わせる。

  /usr/bin/python3 scripts/check_calc.py [ベースURL]   # 既定 http://127.0.0.1:38411/kkensetsu.php
"""
import sys
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:38411/kkensetsu.php"
# (入力行, 満了日, 更新申請の期限) — 期限は満了の30日前、休日なら翌開庁日
CASES = [
    ("A 2021-11-25 3", "2026年11月24日", "2026年10月26日"),   # 30日前=10/25(日) → 10/26(月)
    ("B R3.10.6 3", "2026年10月5日", "2026年9月7日"),        # 30日前=9/5(土) → 9/7(月)
    ("C 2022-12-02 6", "2027年12月1日", "2027年11月1日"),      # 30日前=11/1(月)
    ("D 2024-02-29 3", "2029年2月27日", "2029年1月29日"),      # 応当日なし→2/28の前日。30日前=1/28(日)→1/29
    ("E 2022-02-12 3", "2027年2月11日", "2027年1月12日"),      # 30日前=1/12(火)
    ("F 2022-05-01 3", "2027年4月30日", "2027年3月31日"),      # 30日前=3/31(水)
]
ok = True
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 390, "height": 900})
    pg.goto(BASE + "/", wait_until="domcontentloaded")
    pg.fill("#rows", "\n".join(c[0] for c in CASES))
    pg.click("#go")
    rows = pg.eval_on_selector_all("#out table tr", "rs => rs.slice(1).map(r => [...r.cells].map(c => c.innerText))")
    for line, exp, due in CASES:
        name = line.split()[0]
        r = next((x for x in rows if x[0].startswith(name)), None)
        got = (r[3].split("（")[0], r[1].split("（")[0]) if r else None
        good = got == (exp, due)
        ok &= good
        print("OK " if good else "NG ", line, "→", got, "" if good else f"（期待 {exp} / {due}）")
    print("横幅", pg.evaluate("document.documentElement.scrollWidth"))
    b.close()
sys.exit(0 if ok else 1)
