#!/usr/bin/env python3
"""Monthly API statement bill (PDF), same layout as the plugin's voucher / the OpenRouter receipt.

  python3 tools/make_bill.py --amount 123.45 --month 2026-10 [--vat 5] [--no SEF-202610-001] [--out bill.pdf]

--amount is the USD amount BEFORE VAT (what the client's usage comes to). VAT is added on top.
Party details (seller, client, logo) come from tools/bill_party.json.
"""
import argparse, json, html, datetime, pathlib, sys

ap = argparse.ArgumentParser()
ap.add_argument('--amount', type=float, required=True)
ap.add_argument('--month', required=True, help='YYYY-MM')
ap.add_argument('--vat', type=float, default=None)
ap.add_argument('--no', default='')
ap.add_argument('--date', default='', help='voucher date YYYY-MM-DD (default today)')
ap.add_argument('--paid', default='', help='date paid YYYY-MM-DD, if already paid')
ap.add_argument('--party', default=str(pathlib.Path(__file__).with_name('bill_party.json')))
ap.add_argument('--out', default='')
a = ap.parse_args()

P = json.load(open(a.party, encoding='utf-8'))
vat_pct = P.get('vat_pct', 5) if a.vat is None else a.vat
y, m = map(int, a.month.split('-'))
mon = datetime.date(y, m, 1).strftime('%B %Y')
d = datetime.date.fromisoformat(a.date) if a.date else datetime.date.today()
sub = round(a.amount, 2)
vat = round(sub * vat_pct / 100, 2)
tot = round(sub + vat, 2)
no = a.no or 'SEF-%04d%02d-001' % (y, m)
usd = lambda x: '${:,.2f}'.format(x)
e = html.escape
vp = ('%g' % vat_pct)
lines = lambda L: ''.join('<div>%s</div>' % e(x) for x in L)
paid = ''
if a.paid:
    pd_ = datetime.date.fromisoformat(a.paid)
    paid = '<div><b>Date paid</b><span>%s</span></div>' % pd_.strftime('%B %-d, %Y')
head = ('Marked as paid on ' + pd_.strftime('%B %-d, %Y')) if a.paid else ('Amount due: ' + usd(tot))

H = '''<!doctype html><html><head><meta charset="utf-8"><style>
@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;color:#111;font:13px/1.5 Inter,"Segoe UI",Arial,sans-serif;-webkit-print-color-adjust:exact}
.pg{width:210mm;height:297mm;padding:42px 42px 36px;position:relative}
.logo{position:absolute;right:42px;top:42px;width:56px;height:56px;border-radius:6px;background:#1a6b3e;color:#fff;font-weight:800;font-size:20px;display:flex;align-items:center;justify-content:center}
h1{font-size:26px;margin:0 0 14px;font-weight:700}h2{font-size:19px;margin:34px 0 8px;font-weight:700}
.meta div{display:flex;gap:10px;line-height:1.45}.meta b{display:inline-block;min-width:118px;font-weight:600}.meta span{font-weight:500}
.cols{display:flex;gap:60px;margin-top:26px}.cols>div{flex:1;min-width:0}.cols b{display:block;margin-bottom:4px}
table{width:100%;border-collapse:collapse}.items th{font-weight:400;font-size:11px;color:#444;text-align:left;padding:0 0 8px;border-bottom:1px solid #111}
.items th.r,.det th.r{text-align:right}.items td{padding:9px 0;font-size:13px}.r{text-align:right}
.sum{width:55%;margin:18px 0 0 auto}.sum td{padding:3px 0;border-bottom:1px solid #e5e7eb;font-size:13px}.sum tr:last-child td{border-bottom:0}.sum .t td{font-weight:700}
.foot{position:absolute;left:42px;right:42px;bottom:28px;border-top:1px solid #e5e7eb;padding-top:12px;font-size:11px;color:#555;display:flex;justify-content:space-between}
</style></head><body><div class="pg"><div class="logo">@@logo@@</div>
<h1>@@title@@</h1>
<div class="meta"><div><b>Voucher number</b><span>@@no@@</span></div><div><b>Statement</b><span>Monthly API statement — @@mon@@</span></div><div><b>Voucher date</b><span>@@date@@</span></div>@@paid@@</div>
<div class="cols"><div><b>@@fn@@</b>@@fl@@</div><div><b>Bill to</b><div>@@tn@@</div>@@tl@@</div></div>
<h2>@@head@@</h2><div style="margin-bottom:22px;color:#333">Monthly API statement for @@mon@@</div>
<table class="items"><tr><th>Description</th><th class="r" style="width:60px">Qty</th><th class="r" style="width:110px">Unit price</th><th class="r" style="width:110px">Amount</th></tr>
<tr><td>Monthly API Statement — @@mon@@</td><td class="r">1</td><td class="r">@@sub@@</td><td class="r">@@sub@@</td></tr></table>
<table class="sum"><tr><td>Subtotal</td><td class="r">@@sub@@</td></tr><tr><td>VAT (@@vp@@%)</td><td class="r">@@vat@@</td></tr><tr class="t"><td>Total</td><td class="r">@@tot@@</td></tr></table>
<div class="foot"><span>@@svc@@</span><span>Page 1 of 1</span></div></div></body></html>'''
V = dict(
    logo=e(P.get('logo', 'BW')), title='Receipt' if a.paid else 'Voucher', no=e(no), mon=mon, date=d.strftime('%B %-d, %Y'), paid=paid,
    fn=e(P['from_name']), fl=lines(P['from_lines']), tn=e(P['to_name']), tl=lines(P['to_lines']), head=e(head),
    sub=usd(sub), vp=vp, vat=usd(vat), tot=usd(tot), svc=e(P.get('service', '')))
for k, v in V.items():
    H = H.replace('@@%s@@' % k, str(v))

out = pathlib.Path(a.out or 'bill-%s.pdf' % a.month).resolve()
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page()
    pg.set_content(H); pg.wait_for_timeout(300)
    pg.pdf(path=str(out), width='210mm', height='297mm', print_background=True)
    b.close()
print(out, 'subtotal', usd(sub), 'vat', usd(vat), 'total', usd(tot))
