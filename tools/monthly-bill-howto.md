# মাসিক বিল (Monthly API Statement) — Claude দিয়ে বানানোর নিয়ম

- Maruf শুধু **USD পরিমাণ (VAT ছাড়া) ও মাস** দেয়। Claude `tools/make_bill.py` (repo: marufmmmm/shaheen-blog-wizard) দিয়ে PDF বানায়।
- ডিজাইন: OpenRouter রিসিটের মতো (Voucher number, Statement, Voucher date, From / Bill to, Amount due, Description/Qty/Unit price/Amount, Subtotal, VAT 5%, Total)।
- কমান্ড: `python3 tools/make_bill.py --amount 123.45 --month 2026-10 --no SEF-202610-001 --out bill.pdf` (পরিশোধ হলে `--paid YYYY-MM-DD`)।
- বিক্রেতা/ক্লায়েন্টের নাম-ঠিকানা: `tools/bill_party.json`। ক্লায়েন্টের নাম-ঠিকানা এখনো বসানো হয়নি।
- VAT ডিফল্ট ৫%। ক্লায়েন্টকে আসল খরচ বা মুনাফা কখনো দেখানো যাবে না, শুধু বিলের পরিমাণ।
- Plugin (v2.72.0+) নিজেও মাস শেষে একই ডিজাইনে ভাউচার ও ইমেইল বানায়: Blog Write .ai → USD Billing।
