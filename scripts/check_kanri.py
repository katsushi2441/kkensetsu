#!/usr/bin/env python3
"""管理画面（kkensetsu_kanri.php）を、デモ設定の一時サーバーで一通り操作して確かめる。

  /usr/bin/python3 scripts/check_kanri.py [URL]   # 既定 http://127.0.0.1:38412/index.php（demo=true の設定で起動しておく）
"""
import os, re, sys, tempfile, urllib.request
from playwright.sync_api import sync_playwright

U = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:38412/index.php"
ok = True
def check(name, cond):
    global ok
    ok &= bool(cond); print("OK " if cond else "NG ", name)

with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 390, "height": 900})
    pg.goto(U); check("一覧が出る（見本6件）", pg.locator(".item").count() == 6)
    check("スマホ幅ではみ出さない", pg.evaluate("document.documentElement.scrollWidth") <= 390)
    # 登録
    pg.goto(U + "?p=edit"); pg.fill("#company", "テスト建設"); pg.fill("#permit_date", "2022-01-10"); pg.select_option("#kessan_month", "3"); pg.click("text=保存する")
    check("登録できる", "保存しました" in pg.content() and "テスト建設" in pg.content())
    # 更新申請を出した
    item = pg.locator(".item", has_text="テスト建設"); item.locator("text=更新申請を出した").click()
    check("更新申請済みを記録できる", "更新申請を出したと記録" in pg.content() and "更新申請済み" in pg.locator(".item", has_text="テスト建設").inner_text())
    # 決算変更届を出した
    before = pg.locator(".item", has_text="テスト建設").inner_text()
    pg.locator(".item", has_text="テスト建設").locator("text=決算変更届を出した").click()
    after = pg.locator(".item", has_text="テスト建設").inner_text()
    check("決算変更届を記録すると次の決算期に進む", before != after and "決算変更届を出したと記録" in pg.content())
    # CSV 取り込み
    f = tempfile.NamedTemporaryFile("w", suffix=".csv", delete=False, encoding="utf-8")
    f.write("会社名,許可番号,許可行政庁,一般・特定,業種,許可日,決算月,連絡先,メモ\nCSV工業,,知事,一般,土木,2023/4/1,3,,\n読めない行,,知事,一般,,令和5年,3,,\n"); f.close()
    pg.goto(U + "?p=tools"); pg.set_input_files("input[name=csv]", f.name); pg.click("button:has-text(\"取り込む\")")
    check("CSV を取り込める（読めない行は飛ばす）", "1件を取り込みました" in pg.content() and "1行は飛ばしました" in pg.content())
    os.unlink(f.name)
    # CSV 書き出し・カレンダー購読
    pg.goto(U + "?p=tools"); code = pg.locator("code").first.inner_text()
    ics = urllib.request.urlopen(code.replace("http://127.0.0.1:38412", "http://127.0.0.1:38412")).read().decode()
    check("カレンダー購読の URL が使える", ics.startswith("BEGIN:VCALENDAR") and "CSV工業" in ics)
    bad = urllib.request.Request(U + "?ics=wrong")
    try: urllib.request.urlopen(bad); check("違う鍵では読めない", False)
    except urllib.error.HTTPError as e: check("違う鍵では読めない", e.code == 403)
    with pg.expect_download() as d: pg.click("text=一覧を CSV で書き出す")
    csv = open(d.value.path(), encoding="utf-8-sig").read()
    check("CSV を書き出せる（期限つき）", "更新申請の期限" in csv and "CSV工業" in csv)
    # CSRF なしの書き込みは拒否
    r = pg.request.post(U, form={"a": "del", "id": "1"})
    check("CSRF の無い書き込みは拒否", r.status == 400)
    b.close()
sys.exit(0 if ok else 1)
