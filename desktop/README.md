# Shaheen Browser (desktop)
Chrome-engine browser with a "📥 সংগ্রহ করুন" button that sends the current page (title, link, text, pictures, video links) to the Blog Write .ai plugin.
Run: `npm install && npm start`. Windows build: `npx @electron/packager . "Shaheen Browser" --platform=win32 --arch=x64 --out=dist --overwrite`.
Setup in the app: ⚙ সেটিং → site address + key (WordPress admin → Blog Write .ai → Research → 🔑).
Logins (Facebook, Google) stay in the app's own profile on that computer; the plugin never sees passwords.
