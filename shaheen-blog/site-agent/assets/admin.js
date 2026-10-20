/* Claude.api Site Agent — admin UI */
(function () {
	'use strict';

	/* ================================================================
	   Shared helpers
	   ================================================================ */

	var T = function (s) { return (CSA.i18n && CSA.i18n[s]) || s; };
	var fmt = function (s) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return s.replace(/%(\d+\$)?d|%(\d+\$)?s/g, function (m, a, b) {
			var n = a || b; return n ? args[parseInt(n, 10) - 1] : args[i++];
		});
	};
	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function md(s) {
		var h = esc(s);
		h = h.replace(/```[\w-]*\n?([\s\S]*?)```/g, function (m, code) { return '<pre>' + code + '</pre>'; });
		h = h.replace(/^#{1,4} (.*)$/gm, '<strong class="csa-h">$1</strong>');
		h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		h = h.replace(/`([^`\n]+)`/g, '<code>$1</code>');
		h = h.replace(/https?:\/\/[^\s<]+/g, function (u) {
			var tail = '';
			while (/[.,)\]!?;:।]$/.test(u)) { tail = u.slice(-1) + tail; u = u.slice(0, -1); }
			return '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>' + tail;
		});
		return h;
	}

	function explain(status, raw) {
		var snippet = raw.replace(/<style[\s\S]*?<\/style>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
		var hint = '';
		if ([502, 503, 504, 524].indexOf(status) > -1) hint = T('The server timed out. Press Retry, or ask for smaller steps.');
		else if (status === 403) hint = T('A security plugin or firewall blocked the request. Whitelist admin-ajax.php for csa_* actions.');
		else if (status === 500) hint = T('PHP error on the server. Check the hosting error log.');
		else if (status === 400 || status === 0) hint = T('Your login session may have expired. Reload this page.');
		return T('Server returned') + ' HTTP ' + status + '. ' + hint + (snippet ? '\n\n' + snippet : '');
	}

	async function post(action, data, form, ctl) {
		var body;
		if (form) {
			body = form;
			body.append('action', action);
			body.append('nonce', CSA.nonce);
		} else {
			body = new URLSearchParams(Object.assign({ action: action, nonce: CSA.nonce }, data || {}));
		}
		var r;
		try { r = await fetch(CSA.ajax, { method: 'POST', body: body, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined }); }
		catch (e) { return { error: T('Network error:') + ' ' + (ctl && ctl.stopped ? 'stopped' : (ctl && ctl.timedOut ? 'no answer for 3 minutes' : e.message)) }; }
		var raw = await r.text(), j;
		try { j = JSON.parse(raw); } catch (e) { return { error: explain(r.status, raw) }; }
		if (!j.success) return { error: String(j.data || 'Error') };
		return { data: j.data };
	}

	/** Shrink an image file in the browser; returns a JPEG data URL. */
	function shrink(file, max, quality) {
		max = max || CSA.imgMax || 1568; quality = quality || 0.85;
		return new Promise(function (resolve, reject) {
			if (!file || !/^image\//.test(file.type)) { reject(new Error('type')); return; }
			var img = new Image(), url = URL.createObjectURL(file);
			img.onload = function () {
				var w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, max / Math.max(w, h));
				var c = document.createElement('canvas');
				c.width = Math.round(w * s); c.height = Math.round(h * s);
				var ctx = c.getContext('2d');
				ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
				ctx.drawImage(img, 0, 0, c.width, c.height);
				URL.revokeObjectURL(url);
				resolve(c.toDataURL('image/jpeg', quality));
			};
			img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('load')); };
			img.src = url;
		});
	}

	function toast(msg, type) {
		var d = document.createElement('div');
		d.className = 'csa-toast ' + (type || '');
		d.textContent = msg;
		document.body.appendChild(d);
		setTimeout(function () { d.classList.add('out'); }, 3500);
		setTimeout(function () { d.remove(); }, 4200);
	}

	function showAlerts(list) { (list || []).forEach(function (a) { toast(a, 'warn'); }); }

	function badge(s) {
		if (s == null || s === '') return '<span class="csa-muted">—</span>';
		s = parseInt(s, 10);
		return '<span class="csa-score ' + (s >= 80 ? 'good' : (s >= 50 ? 'ok' : 'bad')) + '">' + s + '</span>';
	}

	var params = new URLSearchParams(location.search);

	/* ================================================================
	   Chat
	   ================================================================ */

	/* Bijoy (ANSI) text → Unicode: on paste into any agent field, and before sending a chat message */
	var BJ = window.csaBijoy;
	function bijoyFix(text) {
		if (!BJ || !BJ.looksLikeBijoy(text)) return null;
		var u = BJ.convert(text);
		return confirm(T('This looks like Bijoy (ANSI / SutonnyMJ) text. Convert it to Unicode Bangla?') + '\n\n' + u.slice(0, 300)) ? u : null;
	}
	document.addEventListener('paste', function (e) {
		var el = e.target;
		if (!el || !el.closest || !el.closest('.csa-wrap') || !/^(TEXTAREA|INPUT)$/.test(el.tagName) || (el.type && !/^(text|search|textarea)$/.test(el.type))) return;
		var t = (e.clipboardData || window.clipboardData || { getData: function () { return ''; } }).getData('text');
		var u = bijoyFix(t);
		if (u === null) return;
		e.preventDefault();
		if (typeof el.setRangeText === 'function') el.setRangeText(u, el.selectionStart, el.selectionEnd, 'end'); else el.value += u;
		el.dispatchEvent(new Event('input', { bubbles: true }));
		toast(T('Converted from Bijoy to Unicode'));
	});

	/* Auto memory: delete a wrong fact */
	$$('.csa-am-del').forEach(function (b) {
		b.onclick = async function () {
			if (!confirm(T('Delete this fact from auto memory?'))) return;
			var res = await post('csa_automem', { do: 'delete', scope: b.dataset.scope, page_id: b.dataset.page, key: b.dataset.key });
			if (res.error) { toast(res.error, 'err'); return; }
			b.closest('li').remove();
		};
	});

	var app = $('#csa-chat-app');
	if (app) (function () {
		var log = $('#csa-log'), input = $('#csa-input'), send = $('#csa-send'), form = $('#csa-form'),
			list = $('#csa-chats'), count = $('#csa-count'), search = $('#csa-search'),
			titleEl = $('#csa-chat-title'), pinBtn = $('#csa-pin'), renBtn = $('#csa-rename'),
			attachBox = $('#csa-attach'), fileIn = $('#csa-file'),
			memBtn = $('#csa-mem-btn'), memPanel = $('#csa-mem-panel');
		var section = app.dataset.section || '', qkey = section ? 'dchat' : 'chat';
		var KIND = { seo: 'seo', blog: 'blog', images: 'vision' };
		var chatId = parseInt(params.get(qkey) || '0', 10) || 0,
			pageFilter = parseInt(params.get('page_id') || '0', 10) || 0,
			busy = false, pinned = 0, attachments = [], searchTimer = null;

		function setUrl() {
			var u = new URL(location.href);
			if (chatId) u.searchParams.set(qkey, chatId); else u.searchParams.delete(qkey);
			var full = $('#csa-dock-full');
			if (full) full.href = CSA.chatUrl + (chatId ? '&chat=' + chatId : '');
			u.searchParams.delete('prompt');
			history.replaceState(null, '', u);
		}

		function setBusy(b) {
			busy = b; app.classList.toggle('csa-busy', b);
			input.placeholder = b ? T('The agent is working. Type the next job: it will start after this one finishes.') : T('Ask in Bangla or English… (Ctrl+Enter to send)');
		}

		function disableOldPlans() {
			$$('.csa-plan.pending', log).forEach(function (p) { p.classList.remove('pending'); $$('button, input', p).forEach(function (b) { b.disabled = true; }); });
			$$('.csa-rest:not(.used) button', log).forEach(function (b) { b.disabled = true; });
		}

		/* messages typed while the agent works wait here and run after the current job */
		var queue = [];
		function flushQueue() {
			if (busy || !queue.length || $('.csa-plan.pending', log)) return;
			var q = queue.shift();
			q.el.classList.remove('csa-queued');
			var tag = q.el.querySelector('.csa-queue-tag'); if (tag) tag.remove();
			disableOldPlans();
			run(q.t, q.imgs);
		}

		function add(item) {
			var d = document.createElement('div');
			switch (item.r) {
				case 'user':
					d.className = 'csa-msg csa-user';
					var h = '';
					(item.imgs || []).forEach(function (u) { h += '<a href="' + esc(u) + '" target="_blank"><img class="csa-msg-img" src="' + esc(u) + '" alt=""></a>'; });
					d.innerHTML = h + (item.t ? '<div>' + esc(item.t) + '</div>' : '');
					break;
				case 'bot': d.className = 'csa-msg csa-bot'; d.innerHTML = md(item.t); break;
				case 'tool': d.className = 'csa-tool'; d.textContent = '• ' + item.t; break;
				case 'act':
					d.className = 'csa-act';
					d.innerHTML = '✓ ' + esc(item.t) + (item.l ? ' <a href="#" class="csa-prev-link">' + esc(T('Preview')) + '</a>' : '');
					if (item.l) {
						d.querySelector('.csa-prev-link').onclick = function (e) { e.preventDefault(); openPreview(item.l); };
						if (!$('#csa-preview').hidden) openPreview(item.l);
					}
					break;
				case 'sys': d.className = 'csa-sys'; d.textContent = item.t; break;
				case 'alert': d.className = 'csa-msg csa-alert'; d.textContent = '⚠ ' + item.t; break;
				case 'wait': d.className = 'csa-msg csa-bot csa-wait'; d.innerHTML = '<span class="csa-dots"><i></i><i></i><i></i></span> ' + esc(item.t); break;
				case 'err': d.className = 'csa-msg csa-bot csa-err'; d.textContent = item.t; break;
				case 'pause':
					d.className = 'csa-pause';
					d.innerHTML = '⏸ ' + esc(item.t) + ' <button type="button" class="button button-primary">' + esc(T('Continue')) + '</button>';
					d.querySelector('button').onclick = function () { if (busy) return; d.remove(); run('', [], null, true); };
					break;
				case 'rest':
					d.className = 'csa-rest';
					if (item.next) {
						d.innerHTML = '<div class="csa-plan-h">⏭ ' + esc(T('Next job')) + '</div><p><strong>' + esc(T('Step')) + ' ' + item.next + ':</strong> ' + md(item.next_t || '') + '</p>'
							+ ((item.steps || []).length > 1 ? '<p class="csa-muted">' + esc(T('Still open after that')) + ': ' + (item.steps || []).filter(function (s) { return s.n !== item.next; }).map(function (s) { return s.n; }).join(', ') + '</p>' : '')
							+ '<div class="csa-plan-btns"><button type="button" class="button button-primary" data-d="next">▶ ' + esc(T('Do this step now')) + '</button>'
							+ ' <button type="button" class="button" data-d="skip">⏭ ' + esc(T('Skip this step')) + '</button>'
							+ ((item.steps || []).length > 1 ? ' <button type="button" class="button" data-d="rest">' + esc(T('Do all remaining')) + '</button>' : '') + '</div>';
						$$('.csa-plan-btns button', d).forEach(function (b) { b.onclick = function () { decide(b.dataset.d, d); }; });
						break;
					}
					d.innerHTML = '<div class="csa-plan-h">⏳ ' + esc(T('Left for later')) + '</div><ol>'
						+ (item.steps || []).map(function (s) { return '<li value="' + s.n + '">' + md(s.t) + (s.later ? '' : ' <span class="csa-muted">(' + esc(T('not finished')) + ')</span>') + '</li>'; }).join('')
						+ '</ol><button type="button" class="button button-primary">▶ ' + esc(T('Do these now')) + '</button>';
					d.querySelector('button').onclick = function () { decide('rest', d); };
					break;
				case 'ask':
					d.className = 'csa-plan csa-ask' + (item.pending ? ' pending' : '');
					renderAsk(d, item);
					break;
				case 'jobform':
					d.className = 'csa-plan csa-jobform';
					renderJobForm(d);
					break;
				case 'slots':
					d.className = 'csa-plan csa-slots';
					renderSlots(d, item);
					break;
				case 'plan':
					d.className = 'csa-plan' + (item.pending ? ' pending' : '');
					var steps = (item.steps || []).map(function (s, i) {
						return item.pending
							? '<li><label class="csa-step-pick"><input type="checkbox" value="' + (i + 1) + '" checked> <span>' + md(s) + '</span></label></li>'
							: '<li>' + md(s) + '</li>';
					}).join('');
					d.innerHTML = '<div class="csa-plan-h">📋 ' + esc(T('Plan')) + (item.pending ? ' <span class="csa-muted">· ' + esc(T('Waiting for your approval')) + '</span>' : '') + '</div>'
						+ '<p>' + md(item.s || '') + '</p><ol>' + steps + '</ol>'
						+ (item.pending && (item.steps || []).length > 1 ? '<p class="csa-muted csa-pick-hint">' + esc(CSA.jobMode === 'all' ? T('Untick steps you want to leave for later. The agent will do the ticked steps one after another without stopping.') : T('Untick steps you want to leave for later. The agent does ONE step, reports it, then asks before the next.')) + '</p>' : '')
						+ (item.pending ? '<div class="csa-plan-btns"><button type="button" class="button button-primary" data-d="approve">✅ ' + esc(T('Approve & build')) + '</button> <button type="button" class="button" data-d="change">✏️ ' + esc(T('Change plan')) + '</button> <button type="button" class="button" data-d="cancel">✖ ' + esc(T('Cancel')) + '</button></div>' : '');
					$$('.csa-plan-btns button', d).forEach(function (b) { b.onclick = function () { decide(b.dataset.d, d); }; });
					break;
			}
			log.appendChild(d);
			log.scrollTop = log.scrollHeight;
			return d;
		}

		function welcome() {
			log.innerHTML = '<div class="csa-empty"><div class="csa-empty-icon">💬</div><strong>' + esc(T('What should we work on?')) + '</strong><p>' + esc(T('Design or fix pages, check SEO, write blog posts, or tell me something to remember about the site. I always show a plan first and build only after you approve.')) + '</p></div>';
		}

		function setHead(title, isPinned) {
			titleEl.textContent = title || T('New chat');
			pinned = isPinned ? 1 : 0;
			pinBtn.hidden = renBtn.hidden = memBtn.hidden = !chatId;
			if (!chatId) memPanel.hidden = true;
			pinBtn.textContent = pinned ? '📌 ' + T('Unpin') : '📌 ' + T('Pin');
		}

		async function loadList() {
			var res = await post('csa_chats', { q: search.value.trim(), page_id: pageFilter, section: section });
			if (res.error) { list.innerHTML = '<p class="csa-muted">' + esc(res.error) + '</p>'; return; }
			count.innerHTML = esc(res.data.total + ' / ' + res.data.limit + ' ' + T('chats'))
				+ (pageFilter ? ' · <a href="#" id="csa-clear-filter">#' + pageFilter + ' ×</a>' : '');
			var cf = $('#csa-clear-filter');
			if (cf) cf.onclick = function (e) { e.preventDefault(); pageFilter = 0; loadList(); };
			if (!res.data.chats.length) { list.innerHTML = '<p class="csa-muted">' + esc(T('No saved chats yet.')) + '</p>'; return; }
			list.innerHTML = '';
			res.data.chats.forEach(function (c) {
				var row = document.createElement('div');
				row.className = 'csa-chat-item' + (parseInt(c.id, 10) === chatId ? ' active' : '');
				var tag = !section && c.section ? '<span class="csa-sec-tag">' + esc(T(({ seo: 'SEO', blog: 'Blog', images: 'Images' })[c.section] || c.section)) + '</span> ' : '';
				row.innerHTML = '<div class="csa-chat-title">' + (parseInt(c.pinned, 10) ? '📌 ' : '') + tag + esc(c.title || 'Untitled') + '</div><div class="csa-chat-date">' + esc(c.updated.slice(0, 16)) + '</div><button type="button" class="csa-chat-del" title="Delete">×</button>';
				row.onclick = function () { if (!busy) openChat(parseInt(c.id, 10)); };
				row.querySelector('.csa-chat-del').onclick = async function (e) {
					e.stopPropagation();
					if (busy || !confirm(T('Delete this chat?'))) return;
					await post('csa_chat_update', { id: c.id, do: 'delete' });
					if (parseInt(c.id, 10) === chatId) newChat(); else loadList();
				};
				list.appendChild(row);
			});
		}

		async function openChat(id) {
			queue = [];
			var res = await post('csa_chat_load', { id: id });
			if (res.error) { chatId = 0; setUrl(); setHead(); welcome(); loadList(); return; }
			chatId = id; setUrl();
			log.innerHTML = '';
			res.data.items.forEach(add);
			if (!res.data.items.length) welcome();
			setHead(res.data.title, parseInt(res.data.pinned, 10));
			setAuto(res.data.kind || KIND[section] || '');
			loadList();
		}

		function newChat() { queue = []; chatId = 0; setUrl(); setHead(); welcome(); clearAttachments(); loadList(); setAuto(KIND[section] || ''); input.focus(); }

		function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

		/** One agent step with a clock, a Stop button after 45 s and a 3-minute cut-off, so the chat can never wait for hours. */
		async function timedStep(args, wait) {
			var ctl = new AbortController(), t0 = Date.now(), base = wait.textContent, stopBtn = null;
			ctl.stopped = false; ctl.timedOut = false;
			var tm = setTimeout(function () { ctl.timedOut = true; ctl.abort(); }, 180000);
			var tick = setInterval(function () {
				if (!wait.isConnected) return;
				var sec = Math.round((Date.now() - t0) / 1000);
				wait.textContent = base.replace(/ · \d+s$/, '') + ' · ' + sec + 's';
				if (sec >= 45 && !stopBtn) {
					stopBtn = document.createElement('button'); stopBtn.type = 'button'; stopBtn.className = 'button button-small'; stopBtn.textContent = '⏹ ' + T('Stop');
					stopBtn.style.marginLeft = '10px';
					stopBtn.onclick = function () { ctl.stopped = true; ctl.abort(); };
					wait.appendChild(stopBtn);
				}
			}, 1000);
			var res = await post('csa_step', args, null, ctl);
			clearTimeout(tm); clearInterval(tick);
			if (ctl.stopped) res.stopped = true;
			return res;
		}

		async function run(message, images, rid, cont, extra) {
			setBusy(true);
			if (message) disableOldPlans();
			var pending = message, imgs = images || [], finished = false, prog = '';
			if (pending || imgs.length) rid = rid || (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
			for (var step = 1; step <= 60; step++) {
				var wait = add({ r: 'wait', t: (prog ? prog : T('Working…')) + (step > 1 ? ' (' + T('Step') + ' ' + step + ')' : '') });
				var args = { section: section, chat_id: chatId, message: pending || '', images: JSON.stringify(imgs), rid: pending || imgs.length ? rid : '', model: modelKey, cont: cont && step === 1 ? 1 : '' };
				if (extra && step === 1) Object.assign(args, extra);
				var res = await timedStep(args, wait), tries = 0;
				// Timeouts, busy server and network blips are retried automatically (the server never repeats a finished step).
				while (res.error && !res.stopped && tries < 3 && /HTTP (500|502|503|504|524)|Network error|Still working|did not answer in time/i.test(res.error)) {
					tries++;
					wait.remove();
					wait = add({ r: 'wait', t: T('Server is slow, trying again…') + ' (' + tries + '/3)' });
					await sleep(10000 * tries);
					res = await timedStep(args, wait);
				}
				wait.remove();
				if (res.error) {
					var box = add({ r: 'err', t: res.error });
					var rm = pending, ri = imgs, rr = rid;
					var b = document.createElement('button');
					b.type = 'button'; b.className = 'button'; b.textContent = T('Retry');
					b.onclick = function () { if (busy) return; box.remove(); run(rm, ri, rr); };
					box.appendChild(document.createElement('br')); box.appendChild(b);
					finished = true;
					break;
				}
				pending = ''; imgs = [];
				var isNew = !chatId;
				chatId = res.data.chat_id; setUrl();
				if (isNew || res.data.handoff) { setHead(res.data.title, 0); loadList(); }
				res.data.items.forEach(add);
				if (res.data.kind) setAuto(res.data.kind, res.data.model);
				if (res.data.progress) prog = res.data.progress; else if (res.data.done) prog = '';
				if (res.data.done) { finished = true; break; }
			}
			if (!finished) {
				var more = add({ r: 'pause', t: T('This is a long job. Press Continue and I will carry on from where I stopped.') });
			}
			setBusy(false); input.focus(); loadList();
			flushQueue();
		}

		/* ---- Guided page job: pick a page, answer the agent's questions ---- */
		function sendFromCard(text, shown) {
			if (busy) return;
			var imgs = attachments.map(function (a) { return a.file; });
			add({ r: 'user', t: shown, imgs: attachments.map(function (a) { return a.url; }) });
			clearAttachments();
			return imgs;
		}

		function renderAsk(d, item) {
			var qs = item.questions || [];
			var live = !!item.pending;
			var h = '<div class="csa-plan-h">❓ ' + esc(T('A few questions before I plan')) + '</div>'
				+ (item.intro ? '<p class="csa-muted">' + esc(item.intro) + '</p>' : '');
			qs.forEach(function (q, i) {
				h += '<div class="csa-q" data-i="' + i + '"><div><strong>' + (i + 1) + '. ' + esc(q.q) + '</strong></div>';
				(q.options || []).forEach(function (o) {
					h += '<label class="csa-opt"><input type="' + (q.multi ? 'checkbox' : 'radio') + '" name="csa-q' + i + '" value="' + esc(o) + '"' + (live ? '' : ' disabled') + '> ' + esc(o) + '</label>';
				});
				h += '<input type="text" class="widefat csa-q-text" placeholder="' + esc(T('Or write your own answer')) + '"' + (live ? '' : ' disabled') + '></div>';
			});
			if (live) {
				h += '<p class="csa-muted">📎 ' + esc(T('Have photos for this page? Attach them with the paperclip below, then press Send answers. Tell me where each one should go (for example: after the principal’s message), or leave it to me.')) + '</p>'
					+ '<div class="csa-plan-btns"><button type="button" class="button button-primary" data-a="send">▶ ' + esc(T('Send answers')) + '</button> <button type="button" class="button" data-a="skip">⏭ ' + esc(T('Skip questions, just plan')) + '</button></div>';
			}
			d.innerHTML = h;
			if (!live) return;
			function go(skip) {
				if (busy) return;
				var lines = [];
				if (!skip) {
					$$('.csa-q', d).forEach(function (box, i) {
						var picked = $$('input[type=radio]:checked, input[type=checkbox]:checked', box).map(function (x) { return x.value; });
						var t = $('.csa-q-text', box).value.trim();
						if (t) picked.push(t);
						if (picked.length) lines.push((i + 1) + '. ' + qs[i].q + ' → ' + picked.join('; '));
					});
				}
				var text = CSA_ANS + '\n' + (lines.length ? lines.join('\n') : '(skipped)');
				$$('button, input', d).forEach(function (x) { x.disabled = true; });
				d.classList.remove('pending');
				var imgs = sendFromCard(text, '✍️ ' + (lines.length ? lines.join('\n') : T('Skipped the questions')));
				run(text, imgs);
			}
			$('[data-a=send]', d).onclick = function () { go(false); };
			$('[data-a=skip]', d).onclick = function () { go(true); };
		}

		var CSA_ANS = '[[ANSWERS]]';

		function renderJobForm(d) {
			var G = [['all', T('Everything that would improve this page')], ['design', T('Page design and layout')], ['mobile', T('Mobile view')], ['fonts', T('Fonts, sizes and padding')],
				['humanise', T('Make the text natural and pleasant (humanise)')], ['seo', T('SEO: title, description, headings, alt text, schema')], ['images', T('Photos: what is needed, alt text, placing them')], ['links', T('Internal links')]];
			d.innerHTML = '<div class="csa-plan-h">🎯 ' + esc(T('Page job')) + '</div>'
				+ '<p class="csa-muted">' + esc(T('Choose the page or post. I will study it for free, ask you a few questions, make one plan, and then do all the work.')) + '</p>'
				+ '<input type="search" class="widefat csa-jf-q" placeholder="' + esc(T('Search pages and posts…')) + '">'
				+ '<select size="6" class="widefat csa-jf-list"></select>'
				+ '<div class="csa-jf-goals">' + G.map(function (g) { return '<label class="csa-opt"><input type="checkbox" value="' + g[0] + '"' + (g[0] === 'all' ? ' checked' : '') + '> ' + esc(g[1]) + '</label>'; }).join('') + '</div>'
				+ '<textarea class="widefat csa-jf-note" rows="2" placeholder="' + esc(T('Anything I must know? (optional)')) + '"></textarea>'
				+ '<div class="csa-plan-btns"><button type="button" class="button button-primary" data-a="go" disabled>🚀 ' + esc(T('Start job')) + '</button> <button type="button" class="button" data-a="x">' + esc(T('Cancel')) + '</button></div>';
			var q = $('.csa-jf-q', d), list = $('.csa-jf-list', d), go = $('[data-a=go]', d), t;
			async function load() {
				var r = await post('csa_job_pages', { q: q.value.trim(), pid: window.csaPreJob || '' });
				if (r.error) { list.innerHTML = '<option disabled>' + esc(r.error) + '</option>'; return; }
				var pre = window.csaPreJob; window.csaPreJob = 0;
				list.innerHTML = r.data.map(function (p) { return '<option value="' + p.id + '">' + esc(p.title) + ' — ' + esc(p.type) + (p.status !== 'publish' ? ' (' + esc(p.status) + ')' : '') + ' #' + p.id + '</option>'; }).join('');
				if (pre && list.options.length) { list.selectedIndex = 0; go.disabled = false; }
			}
			q.oninput = function () { clearTimeout(t); t = setTimeout(load, 300); };
			list.onchange = function () { go.disabled = !list.value; };
			$$('.csa-jf-goals input', d).forEach(function (c) {
				c.onchange = function () {
					if (c.value === 'all' && c.checked) { $$('.csa-jf-goals input', d).forEach(function (o) { if (o !== c) o.checked = false; }); }
					else if (c.checked) { $('.csa-jf-goals input[value=all]', d).checked = false; }
				};
			});
			$('[data-a=x]', d).onclick = function () { d.remove(); };
			go.onclick = function () {
				if (busy || !list.value) return;
				var goals = $$('.csa-jf-goals input:checked', d).map(function (c) { return c.value; });
				if (!goals.length) goals = ['all'];
				var title = list.options[list.selectedIndex].text.replace(/ #\d+$/, '');
				var msg = '🎯 ' + title + ' (#' + list.value + ')';
				var job = { id: parseInt(list.value, 10), goals: goals, note: $('.csa-jf-note', d).value.trim() };
				d.remove();
				var imgs = sendFromCard(msg, msg);
				run(msg, imgs, null, false, { job: JSON.stringify(job) });
			};
			load();
		}

		function showJobForm() {
			if (busy) return;
			if ($('.csa-empty', log)) log.innerHTML = '';
			if ($('.csa-jobform', log)) return;
			add({ r: 'jobform' });
		}
		var jobChip = $('#csa-job-chip');
		if (jobChip) jobChip.onclick = showJobForm;
		if (params.get('job')) { window.csaPreJob = parseInt(params.get('job'), 10) || 0; setTimeout(showJobForm, 300); }

		function renderSlots(d, item) {
			var st = { pending: T('Waiting for photo'), skipped: T('Skipped – image upload required'), uploaded: T('Uploaded'), inserted: T('Placed on page') };
			var rows = (item.slots || []).map(function (s) {
				var thumb = s.thumb ? '<img src="' + esc(s.thumb) + '" alt="" class="csa-cs-thumb">' : '<span class="csa-cs-thumb csa-cs-none">🖼</span>';
				var btns = '';
				if (s.status === 'pending' || s.status === 'skipped') {
					btns = '<label class="button button-small button-primary">⬆ ' + esc(T('Upload')) + '<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-slot="' + esc(s.slot) + '"></label> '
						+ (s.status === 'pending' ? '<button type="button" class="button button-small" data-skip="' + esc(s.slot) + '">⏭ ' + esc(T('Skip')) + '</button>' : '<button type="button" class="button button-small" data-unskip="' + esc(s.slot) + '">↩ ' + esc(T('Undo skip')) + '</button>');
				}
				return '<li class="csa-cs csa-cs-' + esc(s.status) + '">' + thumb + '<div class="csa-cs-body"><strong>' + esc(s.description) + '</strong>'
					+ '<div class="csa-muted">' + esc(s.position || '') + (s.size ? ' · ' + esc(s.size) : '') + '</div>'
					+ '<div class="csa-muted">alt: ' + esc(s.alt) + ' · ' + esc(s.filename) + '</div>'
					+ '<div class="csa-cs-st">' + esc(st[s.status] || s.status) + '</div>' + btns + '</div></li>';
			}).join('');
			var up = (item.slots || []).some(function (s) { return s.status === 'uploaded'; });
			var pend = (item.slots || []).some(function (s) { return s.status === 'pending'; });
			d.innerHTML = '<div class="csa-plan-h">🖼 ' + esc(T('Images for')) + ' “' + esc(item.title) + '”</div>'
				+ '<p class="csa-muted">' + esc(T('Upload each photo. I will set the SEO file name, alt text and caption myself. You can skip any photo; it will be flagged “image upload required” on the SEO page.')) + '</p>'
				+ '<ul class="csa-cs-list">' + rows + '</ul>'
				+ '<div class="csa-plan-btns">' + (up ? '<button type="button" class="button button-primary" data-act="place">✨ ' + esc(T('Place images on page (best positions)')) + '</button> ' : '')
				+ (pend ? '<button type="button" class="button" data-act="skipall">⏭ ' + esc(T('Skip all, upload later')) + '</button>' : '') + '</div>';
			function refresh(it) { if (it) { d.innerHTML = ''; renderSlots(d, it); } }
			$$('input[type=file]', d).forEach(function (inp) {
				inp.onchange = async function () {
					var f = inp.files[0]; if (!f) return;
					var lab = inp.parentNode; lab.firstChild.textContent = '… ';
					var fd = new FormData(); fd.append('do', 'upload'); fd.append('post_id', item.pid); fd.append('slot', inp.dataset.slot); fd.append('file', f);
					var res = await post('csa_slots', null, fd);
					if (res.error) { toast(res.error, 'err'); lab.firstChild.textContent = '⬆ '; return; }
					refresh(res.data.item);
				};
			});
			async function act(a, slot) {
				var res = await post('csa_slots', { do: a, post_id: item.pid, slot: slot || '' });
				if (res.error) { toast(res.error, 'err'); return; }
				refresh(res.data.item);
			}
			$$('[data-skip]', d).forEach(function (b) { b.onclick = function () { act('skip', b.dataset.skip); }; });
			$$('[data-unskip]', d).forEach(function (b) { b.onclick = function () { act('unskip', b.dataset.unskip); }; });
			var sa = $('[data-act=skipall]', d); if (sa) sa.onclick = function () { act('skipall'); };
			var pl = $('[data-act=place]', d);
			if (pl) pl.onclick = async function () {
				if (busy) return;
				var res = await post('csa_plan', { chat_id: chatId, decision: 'images', post_id: item.pid });
				if (res.error) { add({ r: 'err', t: res.error }); return; }
				$$('button', d).forEach(function (x) { x.disabled = true; });
				add({ r: 'sys', t: '✅ ' + T('Placing the images…') });
				run('', []);
			};
		}

		async function decide(d, card) {
			if (busy) return;
			if (d === 'change') {
				input.focus();
				input.placeholder = T('Type what should change, then send.');
				return;
			}
			var args = { chat_id: chatId, decision: d }, label = T('Plan approved');
			if (d === 'approve' && card) {
				var boxes = $$('.csa-step-pick input', card);
				if (boxes.length) {
					var pick = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
					if (!pick.length) { toast(T('Tick at least one step to build.'), 'err'); return; }
					args.pick = pick.join(',');
					if (pick.length < boxes.length) label = T('Plan approved (some steps left for later)');
				}
			}
			if (d === 'rest') label = T('Remaining steps approved');
			if (d === 'next') label = T('Next step approved');
			var res = await post('csa_plan', args);
			if (res.error) { add({ r: 'err', t: res.error }); return; }
			disableOldPlans();
			if ((d === 'rest' || d === 'next' || d === 'skip') && card) { $$('button', card).forEach(function (x) { x.disabled = true; }); card.classList.add('used'); }
			if (d === 'skip') { (res.data.items || []).forEach(add); flushQueue(); return; }
			add({ r: 'sys', t: d === 'cancel' ? '✖ ' + T('Plan cancelled') : '✅ ' + label });
			if (res.data.run) run('', []); else flushQueue();
		}

		/* attachments */
		function renderAttachments() {
			attachBox.innerHTML = '';
			attachments.forEach(function (a, i) {
				var el = document.createElement('div');
				el.className = 'csa-att';
				el.innerHTML = '<img src="' + esc(a.url) + '" alt=""><button type="button">×</button>';
				el.querySelector('button').onclick = function () { attachments.splice(i, 1); renderAttachments(); };
				attachBox.appendChild(el);
			});
		}
		function clearAttachments() { attachments = []; renderAttachments(); }
		async function addFiles(files) {
			for (var i = 0; i < files.length; i++) {
				var f = files[i];
				if (!/^image\//.test(f.type)) continue;
				var ph = document.createElement('div'); ph.className = 'csa-att csa-att-wait'; ph.textContent = '…'; attachBox.appendChild(ph);
				try {
					var data = await shrink(f);
					var res = await post('csa_chat_upload', { data: data });
					ph.remove();
					if (res.error) { toast(res.error, 'err'); continue; }
					attachments.push(res.data);
					renderAttachments();
				} catch (e) { ph.remove(); toast(T('Image too large or unsupported.'), 'err'); }
			}
		}
		fileIn.onchange = function () { addFiles(fileIn.files); fileIn.value = ''; };
		input.addEventListener('paste', function (e) {
			var items = (e.clipboardData || {}).items || [], files = [];
			for (var i = 0; i < items.length; i++) if (items[i].kind === 'file') files.push(items[i].getAsFile());
			if (files.length) { e.preventDefault(); addFiles(files); }
		});
		['dragover', 'drop'].forEach(function (ev) {
			app.addEventListener(ev, function (e) {
				if (!e.dataTransfer || !Array.prototype.some.call(e.dataTransfer.types || [], function (t) { return t === 'Files'; })) return;
				e.preventDefault();
				if (ev === 'drop') addFiles(e.dataTransfer.files);
			});
		});

		/* voice */
		var mic = $('#csa-mic'), micLang = $('#csa-mic-lang'), rec = null;
		micLang.value = CSA.uiLang === 'bn' ? 'bn-BD' : 'en-US';
		mic.onclick = function () {
			var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
			if (!SR) { toast(T('Voice input is not supported in this browser. Use Chrome.'), 'err'); return; }
			if (rec) { rec.stop(); return; }
			rec = new SR(); rec.lang = micLang.value; rec.interimResults = false; rec.continuous = true;
			mic.classList.add('on'); toast(T('Listening… speak now'));
			rec.onresult = function (e) {
				for (var i = e.resultIndex; i < e.results.length; i++) if (e.results[i].isFinal) input.value += (input.value && !/\s$/.test(input.value) ? ' ' : '') + e.results[i][0].transcript;
			};
			rec.onend = function () { mic.classList.remove('on'); rec = null; };
			rec.onerror = function () { mic.classList.remove('on'); rec = null; };
			rec.start();
		};

		/* saved prompts */
		var savedBox = $('#csa-saved-chips'), prompts = CSA.prompts || [];
		function renderPrompts() {
			savedBox.innerHTML = '';
			prompts.forEach(function (p) {
				var c = document.createElement('span');
				c.className = 'csa-chip csa-chip-saved';
				c.innerHTML = '⭐ ' + esc(p.name) + ' <b title="Delete">×</b>';
				c.onclick = function (e) {
					if (e.target.tagName === 'B') {
						if (!confirm(T('Delete this saved prompt?'))) return;
						post('csa_prompt', { do: 'delete', id: p.id }).then(function (r) { if (r.data) { prompts = r.data; renderPrompts(); } });
						return;
					}
					input.value = p.text; input.focus();
				};
				savedBox.appendChild(c);
			});
		}
		renderPrompts();
		$('#csa-save-prompt').onclick = async function () {
			var text = input.value.trim();
			if (!text) { input.focus(); return; }
			var name = prompt(T('Prompt name'), text.slice(0, 30));
			if (!name) return;
			var r = await post('csa_prompt', { name: name, text: text });
			if (r.error) { toast(r.error, 'err'); return; }
			prompts = r.data; renderPrompts(); toast(T('Saved prompt'));
		};

		/* preview */
		var prev = $('#csa-preview'), frame = $('#csa-frame'), purl = $('#csa-preview-url'), pWidth = 390;
		function openPreview(url) {
			if (section) { window.open(url || purl.value, '_blank'); return; } // no room for a preview inside the docked chat
			prev.hidden = false; app.classList.add('with-preview');
			if (url) purl.value = url;
			frame.style.width = pWidth + 'px';
			frame.src = purl.value;
			$('#csa-preview-open').href = purl.value;
		}
		$('#csa-preview-toggle').onclick = function () {
			if (prev.hidden) openPreview(); else { prev.hidden = true; app.classList.remove('with-preview'); frame.src = 'about:blank'; }
		};
		$$('.csa-preview-bar [data-w]').forEach(function (b) { b.onclick = function () { pWidth = parseInt(b.dataset.w, 10); openPreview(); }; });
		purl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); openPreview(); } });

		/* model picker (remembered in this browser) */
		var modelSel = $('#csa-model'), modelKey = '';
		try { modelKey = localStorage.getItem('csa_model') || ''; } catch (e) {}
		/* "Auto" picks the model by the kind of work (page design, SEO, blog, images) */
		function setAuto(kind, used) {
			var o = modelSel.querySelector('option[value=""]');
			if (!o) return;
			var km = CSA.kindModels || {}, names = { chat: T('page work'), seo: 'SEO', blog: T('blog'), vision: T('images') };
			o.textContent = T('Model') + ': ' + T('Auto') + (kind ? ' → ' + (used || km[kind] || '') + ' (' + (names[kind] || kind) + ')' : ' (' + T('chooses by task') + ')');
		}
		modelSel.innerHTML = '<option value="">' + esc(T('Model') + ': ' + T('Auto')) + '</option>'
			+ (CSA.models || []).filter(function (m) { return m.ok; }).map(function (m) {
				return '<option value="' + esc(m.key) + '">' + esc(m.label + ' · ' + m.price + (m.vision ? ' · 🖼' : '')) + '</option>';
			}).join('');
		if (!modelSel.querySelector('option[value="' + modelKey + '"]')) modelKey = '';
		modelSel.value = modelKey;
		modelSel.onchange = function () { modelKey = modelSel.value; try { localStorage.setItem('csa_model', modelKey); } catch (e) {} };
		setAuto(KIND[section] || '');

		/* docked chat inside SEO / Blog / Images */
		var dock = $('#csa-dock'), dockBtn = $('#csa-dock-btn');
		function openDock() { if (dock) { dock.hidden = false; if (dockBtn) dockBtn.hidden = true; input.focus(); } }
		if (dock) {
			dockBtn.onclick = openDock;
			$('#csa-dock-close').onclick = function () { dock.hidden = true; dockBtn.hidden = false; };
			if (chatId) openDock();
			// "Fix with agent" links on this screen open the docked chat instead of leaving the page.
			document.addEventListener('click', function (e) {
				var a = e.target.closest && e.target.closest('a[href*="page=csa-chat"][href*="prompt="]');
				if (!a || busy) return;
				e.preventDefault();
				var pr = new URL(a.href, location.href).searchParams.get('prompt') || '';
				openDock(); newChat(); input.value = pr; input.focus();
			});
		}

		/* head actions */
		pinBtn.onclick = async function () { await post('csa_chat_update', { id: chatId, do: 'pin' }); setHead(titleEl.textContent, !pinned); loadList(); };
		renBtn.onclick = async function () {
			var t = prompt(T('Rename chat'), titleEl.textContent);
			if (!t) return;
			await post('csa_chat_update', { id: chatId, do: 'rename', title: t });
			setHead(t, pinned); loadList();
		};

		/* chat memory panel */
		function showMem(d) {
			$('#csa-mem-task').value = d.task || '';
			$('#csa-mem-notes').value = d.notes || '';
			$('#csa-mem-sum').textContent = d.summary || T('Nothing collected yet.');
			$('#csa-mem-size').textContent = d.size ? '(' + d.size + ')' : '';
		}
		async function memCall(what, extra) {
			var res = await post('csa_chat_memory', Object.assign({ id: chatId, do: what }, extra || {}));
			if (res.error) { alert(res.error); return; }
			showMem(res.data);
		}
		memBtn.onclick = function () {
			memPanel.hidden = !memPanel.hidden;
			if (!memPanel.hidden) memCall('get');
		};
		$('#csa-mem-close').onclick = function () { memPanel.hidden = true; };
		$('#csa-mem-save').onclick = function () { memCall('save', { task: $('#csa-mem-task').value, notes: $('#csa-mem-notes').value }); };
		$('#csa-mem-clear').onclick = function () { if (confirm(T('Clear what the agent collected in this chat? The task stays.'))) memCall('clear'); };

		search.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 300); });
		$$('.csa-new').forEach(function (b) { b.onclick = function () { if (!busy) newChat(); }; });
		$$('#csa-chips > .csa-chip:not(#csa-job-chip)').forEach(function (c) {
			c.onclick = function () { input.value = c.getAttribute('data-p'); input.focus(); input.setSelectionRange(input.value.length, input.value.length); };
		});
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) form.requestSubmit(); });
		form.onsubmit = function (e) {
			e.preventDefault();
			var t = input.value.trim();
			if (!t && !attachments.length) return;
			var fixed = bijoyFix(t);
			if (fixed !== null) { t = fixed; toast(T('Converted from Bijoy to Unicode')); }
			if ($('.csa-empty', log)) log.innerHTML = '';
			var imgs = attachments.map(function (a) { return a.file; });
			var bubble = add({ r: 'user', t: t, imgs: attachments.map(function (a) { return a.url; }) });
			input.value = '';
			clearAttachments();
			if (busy || $('.csa-plan.pending', log) && queue.length) {
				// Agent is busy: keep this for after the current job.
				bubble.classList.add('csa-queued');
				bubble.insertAdjacentHTML('beforeend', '<div class="csa-queue-tag">⏳ ' + esc(T('Queued: runs after the current job')) + '</div>');
				queue.push({ t: t, imgs: imgs, el: bubble });
				return;
			}
			input.placeholder = T('Ask in Bangla or English… (Ctrl+Enter to send)');
			disableOldPlans();
			run(t, imgs);
		};

		var pre = params.get('prompt');
		if (chatId) openChat(chatId); else { setHead(); welcome(); loadList(); }
		if (pre) { input.value = pre; input.focus(); }
	})();

	/* ================================================================
	   Blog Studio
	   ================================================================ */

	var blogList = $('#csa-blog-list');
	if (blogList) (function () {
		var cats = JSON.parse(blogList.dataset.cats || '[]'), running = {};

		function catSelect(sel) {
			return '<select class="csa-f-cat"><option value="0">—</option>' + cats.map(function (c) { return '<option value="' + c.id + '"' + (parseInt(sel, 10) === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>';
		}

		function outlineText(outline) {
			return (outline || []).map(function (s) { return '## ' + s.h2 + '\n' + (s.points || []).map(function (p) { return '- ' + p; }).join('\n'); }).join('\n\n');
		}

		function card(j) {
			var s = j.settings || {}, p = j.plan || {}, el = document.createElement('div');
			el.className = 'csa-card csa-job st-' + j.status;
			el.dataset.id = j.id;
			var head = '<div class="csa-job-head"><div><strong>' + esc(j.keyword) + '</strong> <span class="csa-tag csa-st-' + esc(j.status) + '">' + esc(T(j.status)) + '</span><div class="csa-muted">' + esc([s.type, s.lang, s.length, s.tone].join(' · ')) + (j.description ? ' — ' + esc(j.description) : '') + '</div></div><button type="button" class="button-link csa-job-del" title="Delete">×</button></div>';
			var body = '';
			if (j.status === 'new') {
				body = '<p><button type="button" class="button button-primary csa-do" data-do="plan">' + esc(T('Create plan')) + '</button></p>';
			} else if (j.status === 'planned') {
				var titles = (p.titles || []).map(function (t) { return '<option value="' + esc(t) + '">'; }).join('');
				body = '<div class="csa-plan-edit">'
					+ '<label>' + esc(T('Title')) + '<input type="text" class="csa-f-title" list="csa-titles-' + j.id + '" value="' + esc(p.title) + '"><datalist id="csa-titles-' + j.id + '">' + titles + '</datalist></label>'
					+ '<label>' + esc(T('SEO title')) + '<input type="text" class="csa-f-mt" value="' + esc(p.meta_title) + '"></label>'
					+ '<label>' + esc(T('Meta description')) + '<input type="text" class="csa-f-md" value="' + esc(p.meta_description) + '"></label>'
					+ (s.type === 'post' ? '<label>' + esc(T('Category')) + catSelect(s.category) + '</label>' : '')
					+ '<label class="csa-full">' + esc(T('Outline (## heading, - point)')) + '<textarea class="csa-f-outline" rows="10">' + esc(outlineText(p.outline)) + '</textarea></label>'
					+ '<div class="csa-full csa-plan-meta">'
					+ '<p><b>' + esc(T('Keywords')) + ':</b> ' + esc([p.primary_keyword].concat(p.secondary_keywords || []).join(', ')) + '</p>'
					+ '<p><b>' + esc(T('Image slots')) + ':</b> ' + esc((p.image_slots || []).map(function (x) { return x.slot + ' (' + x.description + ')'; }).join('; ')) + '</p>'
					+ '<p><b>' + esc(T('Internal links')) + ':</b> ' + esc((p.internal_links || []).map(function (x) { return x.anchor; }).join(', ')) + '</p>'
					+ '<p><b>' + esc(T('FAQ')) + ':</b> ' + esc((p.faq || []).join(' | ')) + '</p></div></div>'
					+ '<p><button type="button" class="button csa-do" data-do="save_plan">' + esc(T('Save changes')) + '</button> <button type="button" class="button button-primary csa-do" data-do="approve">✅ ' + esc(T('Approve & write')) + '</button> <button type="button" class="button csa-do" data-do="plan">' + esc(T('Re-plan')) + '</button></p>';
			} else if (j.status === 'approved' || j.status === 'writing') {
				body = '<p><b>' + esc(p.title) + '</b></p><div class="csa-progress"><i style="width:' + Math.round(j.parts_done / ((p.outline || []).length + 2) * 100) + '%"></i></div><p><button type="button" class="button button-primary csa-do" data-do="write">' + esc(T('Continue writing')) + '</button> <span class="csa-job-status csa-muted"></span></p>';
			} else if (j.status === 'built') {
				var sched = j.post_status === 'future' ? '<p>🗓 ' + esc(T('Scheduled for')) + ' <b>' + esc(j.post_date) + '</b></p>' : '';
				body = '<p><b>' + esc(p.title) + '</b> <span class="csa-tag">' + esc(j.post_status) + '</span></p>' + sched
					+ '<p><a class="button button-primary" href="' + esc(j.edit) + '">' + esc(T('Edit draft')) + '</a> <a class="button" target="_blank" href="' + esc(j.preview) + '">' + esc(T('Preview')) + '</a> <a class="button" href="' + esc(CSA.imagesUrl) + '">🖼 ' + esc(T('Upload images')) + '</a></p>'
					+ (j.post_status === 'publish' ? '' : '<p class="csa-row"><input type="datetime-local" class="csa-f-when"> <button type="button" class="button csa-do" data-do="next_slot">' + esc(T('Next free slot')) + '</button> <button type="button" class="button button-primary csa-do" data-do="schedule">🗓 ' + esc(T('Schedule')) + '</button></p>');
			}
			el.innerHTML = head + body + '<div class="csa-job-msg"></div>';
			return el;
		}

		async function load() {
			var r = await post('csa_blog', { do: 'list' });
			if (r.error) { blogList.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
			blogList.innerHTML = r.data.length ? '' : '<p class="csa-muted">' + esc(T('Add keywords')) + '…</p>';
			r.data.forEach(function (j) { blogList.appendChild(card(j)); });
		}

		function replace(el, j) { var n = card(j); el.replaceWith(n); return n; }

		async function writeAll(el, id) {
			if (running[id]) return;
			running[id] = true;
			var msg = function (t) { var m = $('.csa-job-status', el) || $('.csa-job-msg', el); if (m) m.textContent = t; };
			var r = await post('csa_blog', { do: 'list' });
			var job = (r.data || []).filter(function (x) { return x.id === id; })[0];
			if (!job) { running[id] = false; return; }
			el = replace(el, job);
			var total = (job.plan.outline || []).length + 2;
			for (var part = job.parts_done; part < total; part++) {
				msg(fmt(T('Writing part %1$d of %2$d…'), part + 1, total));
				var w = await post('csa_blog', { do: 'write', id: id, part: part });
				if (w.error) { msg(w.error); running[id] = false; return; }
				showAlerts(w.data.alerts);
				var bar = $('.csa-progress i', el); if (bar) bar.style.width = Math.round((part + 1) / total * 100) + '%';
			}
			msg(T('Finishing draft…'));
			var f = await post('csa_blog', { do: 'finish', id: id });
			running[id] = false;
			if (f.error) { msg(f.error); return; }
			replace(el, f.data);
		}

		blogList.addEventListener('click', async function (e) {
			var el = e.target.closest('.csa-job');
			if (!el) return;
			var id = parseInt(el.dataset.id, 10), msg = $('.csa-job-msg', el);
			if (e.target.closest('.csa-job-del')) {
				if (!confirm(T('Delete this item?'))) return;
				await post('csa_blog', { do: 'delete', id: id }); el.remove(); return;
			}
			var b = e.target.closest('.csa-do');
			if (!b) return;
			var d = b.dataset.do;
			b.disabled = true;
			var r;
			if (d === 'plan') {
				msg.textContent = T('Planning…');
				r = await post('csa_blog', { do: 'plan', id: id });
				if (!r.error) { showAlerts(r.data.alerts); replace(el, r.data.job); return; }
			} else if (d === 'save_plan' || d === 'approve') {
				var cat = $('.csa-f-cat', el);
				r = await post('csa_blog', { do: 'save_plan', id: id, title: $('.csa-f-title', el).value, meta_title: $('.csa-f-mt', el).value, meta_description: $('.csa-f-md', el).value, outline: $('.csa-f-outline', el).value, category: cat ? cat.value : 0 });
				if (!r.error && d === 'approve') {
					r = await post('csa_blog', { do: 'approve', id: id });
					if (!r.error) { writeAll(el, id); return; }
				} else if (!r.error) { el = replace(el, r.data); toast(T('Save changes') + ' ✓'); return; }
			} else if (d === 'write') {
				writeAll(el, id); return;
			} else if (d === 'next_slot') {
				r = await post('csa_blog', { do: 'next_slot' });
				if (!r.error) { $('.csa-f-when', el).value = r.data.when.replace(' ', 'T'); b.disabled = false; return; }
			} else if (d === 'schedule') {
				var when = $('.csa-f-when', el).value;
				if (!when) { b.disabled = false; return; }
				r = await post('csa_blog', { do: 'schedule', id: id, when: when.replace('T', ' ') });
				if (!r.error) { replace(el, r.data); toast(T('Scheduled for') + ' ' + r.data.post_date); return; }
			}
			b.disabled = false;
			if (r && r.error) msg.textContent = r.error;
		});

		$('#csa-blog-add').onclick = async function () {
			var items = $('#csa-blog-items').value.trim();
			if (!items) return;
			var settings = { type: $('#csa-blog-type').value, lang: $('#csa-blog-lang').value, length: $('#csa-blog-length').value, tone: $('#csa-blog-tone').value, audience: $('#csa-blog-audience').value, category: $('#csa-blog-cat').value };
			var r = await post('csa_blog', { do: 'add', items: items, settings: JSON.stringify(settings) });
			if (r.error) { toast(r.error, 'err'); return; }
			$('#csa-blog-items').value = '';
			toast(T('Added to Blog Studio queue.'));
			load();
		};
		load();
	})();

	/* Add-to-queue helper for Ideas and Keywords */
	async function addToQueue(keyword, description, lang, btn) {
		btn.disabled = true;
		var r = await post('csa_blog', { do: 'add', items: keyword + ' | ' + (description || ''), settings: JSON.stringify({ type: 'post', lang: /bangla|bn/i.test(lang || '') ? 'Bangla' : 'English', length: 'medium' }) });
		if (r.error) { toast(r.error, 'err'); btn.disabled = false; return; }
		btn.textContent = '✓'; toast(T('Added to Blog Studio queue.'));
	}

	var ideasGo = $('#csa-ideas-go');
	if (ideasGo) ideasGo.onclick = async function () {
		var box = $('#csa-ideas');
		ideasGo.disabled = true; box.innerHTML = '<p class="csa-muted">' + esc(T('Getting ideas…')) + '</p>';
		var r = await post('csa_blog', { do: 'ideas', focus: $('#csa-ideas-focus').value });
		ideasGo.disabled = false;
		if (r.error) { box.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
		showAlerts(r.data.alerts);
		box.innerHTML = '<table class="widefat striped"><tbody>' + r.data.items.map(function (i, k) {
			return '<tr><td><strong>' + esc(i.keyword) + '</strong><br><span class="csa-muted">' + esc(i.description) + '</span><br><em class="csa-muted">' + esc(i.why || '') + '</em></td><td style="width:140px"><button type="button" class="button csa-idea-add" data-k="' + k + '">+ ' + esc(T('Add to queue')) + '</button></td></tr>';
		}).join('') + '</tbody></table>';
		$$('.csa-idea-add', box).forEach(function (b) { var i = r.data.items[b.dataset.k]; b.onclick = function () { addToQueue(i.keyword, i.description, i.language, b); }; });
	};

	/* ================================================================
	   SEO
	   ================================================================ */

	var seoTable = $('.csa-seo-table');
	if (seoTable) (function () {
		function fact(label, val, warn) {
			return '<div class="csa-fact' + (warn ? ' warn' : '') + '"><span>' + esc(label) + '</span><strong>' + esc(val === '' || val == null ? '—' : val) + '</strong></div>';
		}
		function render(id, saved) {
			var r = saved.report || {}, f = saved.facts || {}, psi = saved.psi || {};
			var cell = $('.csa-seo-detail[data-for="' + id + '"] td', seoTable);
			var html = '<div class="csa-report"><p class="csa-summary">' + badge(saved.score) + ' ' + esc(r.summary || '') + '</p><div class="csa-facts">'
				+ fact(T('Title tag') + ' (' + (f.title_length || 0) + ')', f.title_tag, !f.title_tag || f.title_length > 65 || f.title_length < 25)
				+ fact(T('Meta description') + ' (' + (f.meta_desc_length || 0) + ')', f.meta_description, !f.meta_description || f.meta_desc_length > 160 || f.meta_desc_length < 70)
				+ fact(T('H1 count'), f.h1_count, f.h1_count !== 1)
				+ fact(T('Words'), f.word_count, f.word_count < 300)
				+ fact(T('Images missing alt'), (f.images_missing_alt || 0) + ' / ' + (f.images || 0), f.images_missing_alt > 0)
				+ fact(T('Internal links'), f.internal_links, !f.internal_links)
				+ fact(T('Schema blocks'), f.schema_blocks, !f.schema_blocks)
				+ fact(T('Load time'), f.response_ms ? f.response_ms + ' ms' : '', f.response_ms > 2500)
				+ '</div>';
			['mobile', 'desktop'].forEach(function (k) {
				var p = psi && psi[k];
				if (!p) return;
				html += '<div class="csa-psi"><b>' + (k === 'mobile' ? '📱' : '🖥') + ' PageSpeed ' + badge(p.score) + '</b> LCP ' + esc(p.lcp) + ' · CLS ' + esc(p.cls) + ' · TBT ' + esc(p.tbt)
					+ (p.opps && p.opps.length ? '<br><span class="csa-muted">' + esc(p.opps.map(function (o) { return o.title + (o.saving ? ' (' + o.saving + ')' : ''); }).join(' · ')) + '</span>' : '') + '</div>';
			});
			if (r.issues && r.issues.length) {
				html += '<ol class="csa-issues">' + r.issues.map(function (i) {
					return '<li><span class="csa-sev ' + esc(i.severity) + '">' + esc(i.severity) + '</span> <strong>' + esc(i.issue) + '</strong><br><span class="csa-muted">' + esc(i.fix) + '</span></li>';
				}).join('') + '</ol>';
			}
			var sug = [['title', T('Suggested SEO title'), r.suggested_title], ['description', T('Suggested meta description'), r.suggested_meta_description], ['keyword', T('Suggested focus keyword'), r.suggested_focus_keyword]];
			html += '<div class="csa-sugs">' + sug.filter(function (s) { return s[2]; }).map(function (s) {
				return '<div class="csa-sug"><span class="csa-muted">' + esc(s[1]) + '</span><div class="csa-sug-row"><input type="text" class="large-text" value="' + esc(s[2]) + '" data-field="' + s[0] + '">' + (CSA.seoPlugin ? '<button type="button" class="button csa-apply" data-field="' + s[0] + '">' + esc(T('Apply')) + '</button>' : '') + '</div></div>';
			}).join('') + '</div>';
			if (!CSA.seoPlugin) html += '<p class="csa-muted">' + esc(T('Install Yoast SEO or Rank Math to apply these with one click.')) + '</p>';
			var prompt = 'Check the SEO of page ID ' + id + ' and fix the high-priority issues from its latest audit.';
			html += '<p><a class="button button-primary" href="' + esc(CSA.chatUrl + '&prompt=' + encodeURIComponent(prompt)) + '">' + esc(T('Fix with agent')) + '</a> <span class="csa-muted">' + esc(T('Source:') + ' ' + (f.source || '') + ' · ' + T('Checked') + ' ' + saved.time) + '</span></p></div>';
			cell.innerHTML = html;
			cell.parentNode.hidden = false;
			$$('.csa-apply', cell).forEach(function (b) {
				b.onclick = async function () {
					var inp = $('input[data-field="' + b.dataset.field + '"]', cell);
					b.disabled = true; b.textContent = T('Saving…');
					var res = await post('csa_seo_apply', { id: id, field: b.dataset.field, value: inp.value });
					b.textContent = res.error ? T('Failed') : T('Applied ✓');
					if (res.error) { toast(res.error, 'err'); b.disabled = false; }
				};
			});
		}

		async function analyse(row, quiet, image) {
			var id = row.dataset.id, btn = $('.csa-seo-run', row);
			btn.disabled = true; btn.textContent = T('Analysing…');
			var data = { id: id };
			if (quiet) data.bulk = 1;
			if (image) data.image = image;
			var res = await post('csa_seo_analyze', data);
			btn.disabled = false; btn.textContent = T('Analyse');
			if (res.error) {
				if (quiet) $('.csa-score-cell', row).innerHTML = '<span class="csa-sev high" title="' + esc(res.error) + '">' + esc(T('error')) + '</span>';
				else toast(res.error, 'err');
				return false;
			}
			showAlerts(res.data.alerts);
			row.dataset.checked = '1';
			$('.csa-score-cell', row).innerHTML = badge(res.data.score);
			$('.csa-time-cell', row).textContent = res.data.time.slice(5, 16);
			$('.csa-seo-view', row).disabled = false;
			if (!quiet) { var g = await post('csa_seo_get', { id: id }); render(id, g.data || res.data); }
			return true;
		}

		seoTable.addEventListener('click', async function (e) {
			var row = e.target.closest('.csa-seo-row');
			if (!row) return;
			var id = row.dataset.id;
			if (e.target.classList.contains('csa-seo-run')) analyse(row, false);
			if (e.target.classList.contains('csa-seo-view')) {
				var detail = $('.csa-seo-detail[data-for="' + id + '"]', seoTable);
				if (!detail.hidden) { detail.hidden = true; return; }
				var res = await post('csa_seo_get', { id: id });
				if (res.error) toast(res.error, 'err'); else render(id, res.data);
			}
			if (e.target.classList.contains('csa-psi-run')) {
				var b = e.target, s = b.dataset.s, old = b.textContent;
				b.disabled = true; b.textContent = T('Testing…');
				var r = await post('csa_psi', { id: id, strategy: s });
				b.disabled = false; b.textContent = old;
				if (r.error) { toast(r.error, 'err'); return; }
				var g = await post('csa_seo_get', { id: id });
				var cellP = $('.csa-psi-cell', row), psi = (g.data && g.data.psi) || {}, h = '';
				if (psi.mobile) h += '📱 ' + badge(psi.mobile.score);
				if (psi.desktop) h += ' 🖥 ' + badge(psi.desktop.score);
				cellP.innerHTML = h || ('📱 ' + badge(r.data.score));
				if (g.data) render(id, g.data);
			}
		});
		seoTable.addEventListener('change', async function (e) {
			if (!e.target.classList.contains('csa-seo-shot') || !e.target.files[0]) return;
			var row = e.target.closest('.csa-seo-row');
			try { var img = await shrink(e.target.files[0], CSA.imgMax || 1568, 0.82); analyse(row, false, img); }
			catch (x) { toast(T('Image too large or unsupported.'), 'err'); }
			e.target.value = '';
		});

		async function bulk(onlyUnchecked) {
			var rows = $$('.csa-seo-row', seoTable).filter(function (r) { return !onlyUnchecked || !r.dataset.checked; });
			if (!rows.length) { toast(T('Nothing to analyse.')); return; }
			if (!confirm(fmt(T('Analyse %d page(s)? Each one uses AI allowance.'), rows.length))) return;
			var prog = $('#csa-seo-progress');
			for (var i = 0; i < rows.length; i++) {
				prog.textContent = fmt(T('Analysing %1$d of %2$d…'), i + 1, rows.length);
				await analyse(rows[i], true);
			}
			prog.textContent = T('Done.');
		}
		$('#csa-seo-unchecked').onclick = function () { bulk(true); };
		$('#csa-seo-all').onclick = function () { bulk(false); };
	})();

	var techBtn = $('#csa-tech-run');
	if (techBtn) techBtn.onclick = async function () {
		var box = $('#csa-tech');
		techBtn.disabled = true; box.innerHTML = '<p class="csa-muted">' + esc(T('Running checks…')) + '</p>';
		var r = await post('csa_tech');
		techBtn.disabled = false;
		if (r.error) { box.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
		box.innerHTML = '<table class="widefat striped"><tbody>' + r.data.map(function (c) {
			var icon = c.status === 'ok' ? '✅' : (c.status === 'warn' ? '⚠️' : '❌');
			return '<tr><td style="width:30px">' + icon + '</td><td style="width:260px"><strong>' + esc(c.label) + '</strong></td><td class="csa-break">' + esc(c.detail) + '</td></tr>';
		}).join('') + '</tbody></table>';
	};

	var linksBtn = $('#csa-links-run');
	if (linksBtn) linksBtn.onclick = async function () {
		var prog = $('#csa-links-progress'), box = $('#csa-links'), broken = [];
		linksBtn.disabled = true; box.innerHTML = '';
		var off = 0, r;
		do {
			prog.textContent = T('Collecting links from pages…') + ' ' + off;
			r = await post('csa_links', { do: 'collect', offset: off });
			if (r.error) { prog.textContent = r.error; linksBtn.disabled = false; return; }
			off = r.data.next;
		} while (!r.data.done);
		off = 0;
		do {
			r = await post('csa_links', { do: 'check', offset: off });
			if (r.error) { prog.textContent = r.error; linksBtn.disabled = false; return; }
			off = r.data.next;
			prog.textContent = T('Checking links…') + ' ' + off + ' / ' + r.data.total;
			broken = broken.concat(r.data.broken);
		} while (!r.data.done);
		linksBtn.disabled = false;
		prog.textContent = T('Done.');
		if (!broken.length) { box.innerHTML = '<p>✅ ' + esc(T('No broken links found.')) + '</p>'; return; }
		box.innerHTML = '<table class="widefat striped"><thead><tr><th>URL</th><th style="width:90px"></th><th>' + esc(T('Found on')) + '</th></tr></thead><tbody>' + broken.map(function (b) {
			return '<tr><td class="csa-break">' + esc(b.url) + '</td><td>' + esc(b.code) + '</td><td>' + b.pages.map(function (p) { return '<a href="' + esc(p.edit) + '">' + esc(p.title) + '</a>'; }).join(', ') + '</td></tr>';
		}).join('') + '</tbody></table>';
	};

	$$('.csa-local input').forEach(function (c) {
		c.onchange = function () { post('csa_local', { key: c.dataset.key, on: c.checked ? 1 : '' }); };
	});

	var kwGo = $('#csa-kw-go');
	if (kwGo) kwGo.onclick = async function () {
		var box = $('#csa-kw'), topic = $('#csa-kw-topic').value.trim();
		if (!topic) return;
		kwGo.disabled = true; box.innerHTML = '<p class="csa-muted">' + esc(T('Getting keywords…')) + '</p>';
		var r = await post('csa_keywords', { topic: topic, lang: $('#csa-kw-lang').value });
		kwGo.disabled = false;
		if (r.error) { box.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
		showAlerts(r.data.alerts);
		box.innerHTML = '<table class="widefat striped"><thead><tr><th>Keyword</th><th>Intent</th><th>Difficulty</th><th>Target</th><th></th></tr></thead><tbody>' + r.data.items.map(function (k, i) {
			return '<tr><td><strong>' + esc(k.keyword) + '</strong> <span class="csa-tag">' + esc(k.language) + '</span><br><span class="csa-muted">' + esc(k.why || '') + '</span></td><td>' + esc(k.intent) + '</td><td>' + esc(k.difficulty) + '</td><td>' + esc(k.target) + (k.title_idea ? '<br><em class="csa-muted">' + esc(k.title_idea) + '</em>' : '') + '</td><td style="width:130px"><button type="button" class="button button-small csa-kw-add" data-k="' + i + '">+ ' + esc(T('Add to queue')) + '</button></td></tr>';
		}).join('') + '</tbody></table>';
		$$('.csa-kw-add', box).forEach(function (b) { var k = r.data.items[b.dataset.k]; b.onclick = function () { addToQueue(k.keyword, k.title_idea || '', k.language, b); }; });
	};

	/* ================================================================
	   Images
	   ================================================================ */

	$$('.csa-slot-post').forEach(function (box) {
		var pid = box.dataset.post;
		box.addEventListener('change', async function (e) {
			if (!e.target.classList.contains('csa-slot-file') || !e.target.files[0]) return;
			var slotEl = e.target.closest('.csa-slot'), fd = new FormData();
			fd.append('do', 'upload'); fd.append('post_id', pid); fd.append('slot', slotEl.dataset.slot); fd.append('file', e.target.files[0]);
			var tag = $('.csa-tag', slotEl); tag.textContent = T('Uploading image…');
			var r = await post('csa_slots', null, fd);
			e.target.value = '';
			if (r.error) { tag.textContent = T('Failed'); toast(r.error, 'err'); return; }
			tag.textContent = r.data.slot.status === 'inserted' ? T('Inserted ✓') : T('Uploaded ✓');
			tag.className = 'csa-tag csa-st-' + r.data.slot.status;
			if (r.data.thumb) $('.csa-slot-img', slotEl).innerHTML = '<img src="' + esc(r.data.thumb) + '" alt="">';
		});
		box.addEventListener('click', async function (e) {
			if (e.target.classList.contains('csa-slot-remove')) {
				if (!confirm(T('Remove this slot?'))) return;
				var s = e.target.closest('.csa-slot');
				await post('csa_slots', { do: 'remove', post_id: pid, slot: s.dataset.slot });
				s.remove();
			}
			if (e.target.classList.contains('csa-slot-insert')) {
				var b = e.target; b.disabled = true;
				var r = await post('csa_slots', { do: 'insert', post_id: pid });
				b.disabled = false;
				if (r.error) { toast(r.error, 'err'); return; }
				if (r.data.inserted) toast(fmt(T('Inserted %d image(s).'), r.data.inserted));
				else if (!r.data.left) toast(T('Nothing to insert yet. Upload images first.'));
				if (r.data.left) toast(T('Some images have no marker in this page. Use "Insert with agent" for them.'), 'warn');
				if (r.data.inserted) setTimeout(function () { location.reload(); }, 1200);
			}
		});
	});

	var altList = $('#csa-alt-list');
	if (altList) (function () {
		var paged = 1;
		async function load() {
			altList.innerHTML = '<p class="csa-muted">' + esc(T('Loading…')) + '</p>';
			var r = await post('csa_media', { do: 'missing', paged: paged });
			if (r.error) { altList.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
			if (!r.data.items.length) { altList.innerHTML = '<p>✅ ' + esc(T('No images without alt text.')) + '</p>'; $('#csa-alt-pager').innerHTML = ''; return; }
			altList.innerHTML = r.data.items.map(function (i) {
				return '<div class="csa-media" data-id="' + i.id + '"><input type="checkbox" class="csa-pick"><img src="' + esc(i.thumb) + '" alt=""><div class="csa-media-body"><div class="csa-muted">' + esc(i.title) + (i.parent ? ' · 📄 ' + esc(i.parent) : ' · ' + esc(T('not used on any page'))) + '</div><input type="text" class="csa-alt-in" placeholder="alt…"><div><button type="button" class="button button-small csa-alt-gen">✨ ' + esc(T('Generate alt')) + '</button> <button type="button" class="button button-small csa-alt-save">' + esc(T('Save')) + '</button></div></div></div>';
			}).join('');
			var pg = '';
			for (var p = 1; p <= Math.min(r.data.pages, 20); p++) pg += '<button type="button" class="button button-small' + (p === paged ? ' button-primary' : '') + '" data-p="' + p + '">' + p + '</button> ';
			$('#csa-alt-pager').innerHTML = r.data.pages > 1 ? pg + ' <span class="csa-muted">' + r.data.total + '</span>' : '';
		}
		async function gen(item) {
			var b = $('.csa-alt-gen', item);
			b.disabled = true;
			var r = await post('csa_media', { do: 'alt', id: item.dataset.id });
			b.disabled = false;
			if (r.error) { toast(r.error, 'err'); return false; }
			showAlerts(r.data.alerts);
			$('.csa-alt-in', item).value = r.data.alt;
			item.classList.add('done');
			return true;
		}
		altList.addEventListener('click', async function (e) {
			var item = e.target.closest('.csa-media');
			if (!item) return;
			if (e.target.classList.contains('csa-alt-gen')) gen(item);
			if (e.target.classList.contains('csa-alt-save')) {
				var r = await post('csa_media', { do: 'save_alt', id: item.dataset.id, alt: $('.csa-alt-in', item).value });
				if (r.error) toast(r.error, 'err'); else { item.classList.add('done'); toast(T('Save') + ' ✓'); }
			}
		});
		$('#csa-alt-pager').addEventListener('click', function (e) { if (e.target.dataset.p) { paged = parseInt(e.target.dataset.p, 10); load(); } });
		$('#csa-alt-sel').onchange = function () { var on = this.checked; $$('.csa-pick', altList).forEach(function (c) { c.checked = on; }); };
		$('#csa-alt-all').onclick = async function () {
			var picked = $$('.csa-media', altList).filter(function (m) { return $('.csa-pick', m).checked; });
			var items = (picked.length ? picked : $$('.csa-media', altList)).filter(function (m) { return !m.classList.contains('done'); }), prog = $('#csa-alt-progress');
			for (var i = 0; i < items.length; i++) {
				prog.textContent = (i + 1) + ' / ' + items.length;
				if (!(await gen(items[i]))) break;
			}
			prog.textContent = T('Done.');
		};
		$('#csa-alt-saveall').onclick = async function () {
			var prog = $('#csa-alt-progress'), n = 0;
			for (const m of $$('.csa-media', altList)) {
				var v = $('.csa-alt-in', m).value.trim();
				if (!v || m.dataset.saved === v) continue;
				var r = await post('csa_media', { do: 'save_alt', id: m.dataset.id, alt: v });
				if (!r.error) { m.dataset.saved = v; m.classList.add('done'); n++; prog.textContent = n; }
			}
			prog.textContent = T('Done.') + ' ' + n;
		};
		load();
	})();

	var bigList = $('#csa-big-list');
	if (bigList) (async function () {
		bigList.innerHTML = '<p class="csa-muted">' + esc(T('Loading…')) + '</p>';
		var r = await post('csa_media', { do: 'big' });
		if (r.error) { bigList.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
		if (!r.data.length) { bigList.innerHTML = '<p>✅ ' + esc(T('No large images found.')) + '</p>'; return; }
		bigList.innerHTML = '<table class="widefat striped"><tbody>' + r.data.map(function (i) {
			return '<tr data-id="' + i.id + '"><td style="width:30px"><input type="checkbox" class="csa-pick"></td><td style="width:70px"><img src="' + esc(i.thumb) + '" alt="" width="60"></td><td>' + esc(i.title) + '<br><span class="csa-muted">' + i.w + '×' + i.h + '</span></td><td class="csa-kb" style="width:90px">' + i.kb + ' KB</td><td style="width:120px"><button type="button" class="button button-small csa-compress">' + esc(T('Compress')) + '</button></td></tr>';
		}).join('') + '</tbody></table>';
		async function squeeze(tr) {
			var b = $('.csa-compress', tr);
			if (b.textContent === '✓') return true;
			b.disabled = true; b.textContent = '…';
			var res = await post('csa_media', { do: 'compress', id: tr.dataset.id });
			if (res.error) { toast(res.error, 'err'); b.disabled = false; b.textContent = T('Compress'); return false; }
			$('.csa-kb', tr).textContent = res.data.kb + ' KB'; b.textContent = '✓';
			return true;
		}
		bigList.addEventListener('click', function (e) {
			if (e.target.classList.contains('csa-compress')) squeeze(e.target.closest('tr'));
		});
		$('#csa-big-sel').onchange = function () { var on = this.checked; $$('.csa-pick', bigList).forEach(function (c) { c.checked = on; }); };
		$('#csa-big-all').onclick = async function () {
			var rows = $$('tr', bigList).filter(function (t) { return $('.csa-pick', t).checked; }), prog = $('#csa-big-progress');
			if (!rows.length) { toast(T('Select images first.'), 'err'); return; }
			for (var i = 0; i < rows.length; i++) {
				prog.textContent = (i + 1) + ' / ' + rows.length;
				if (!(await squeeze(rows[i]))) break;
			}
			prog.textContent = T('Done.');
		};
	})();


	/* ================================================================
	   Jobs board: scan -> numbered jobs -> run "1,2,5"
	   ================================================================ */

	var jbApp = $('#csa-jobs-app');
	if (jbApp) (function () {
		var qIn = $('#csa-jb-q'), sel = $('#csa-jb-page'), head = $('#csa-jb-head'), listEl = $('#csa-jb-list'), runBox = $('#csa-jb-run'), prog = $('#csa-jb-prog');
		var jobs = [], pid = 0, running = false;
		var ICON = { css: '🎨', seo_meta: '🔎', schema: '🧩', alt: '🖼', agent: '💬', owner: '👤' };
		var HOW = { css: T('Free, no AI'), seo_meta: T('1 small AI call'), schema: T('1 small AI call'), alt: T('1 AI call per image'), agent: T('Needs the chat agent'), owner: T('You do this') };
		var runnable = function (j) { return ['css', 'seo_meta', 'schema', 'alt'].indexOf(j.kind) >= 0 && j.status !== 'done'; };

		async function loadPages() {
			var r = await post('csa_job_pages', { q: qIn.value.trim(), pid: params.get('page_id') || '' });
			if (r.error) { sel.innerHTML = '<option>' + esc(r.error) + '</option>'; return; }
			sel.innerHTML = r.data.map(function (p) { return '<option value="' + p.id + '">' + esc(p.title) + ' — ' + esc(p.type) + ' #' + p.id + '</option>'; }).join('');
		}
		var t; qIn.oninput = function () { clearTimeout(t); t = setTimeout(loadPages, 300); };

		function badge(score, label) {
			if (score === null || score === undefined) return '<span class="csa-score">—</span> ' + esc(label);
			return csaScore(score) + ' ' + esc(label);
		}
		function csaScore(s) { s = parseInt(s, 10); return '<span class="csa-score ' + (s >= 80 ? 'good' : (s >= 50 ? 'ok' : 'bad')) + '">' + s + '</span>'; }

		function draw(meta) {
			if (meta) {
				var pl = meta.plugin || {};
				head.innerHTML = '<h2>' + esc(meta.title) + '</h2><p>'
					+ '<strong>' + esc(pl.plugin || '') + ':</strong> ' + badge(pl.seo, T('SEO score')) + (pl.read !== null && pl.read !== undefined ? ' · ' + csaScore(pl.read) + ' ' + esc(T('Readability')) : '')
					+ ' &nbsp;|&nbsp; <strong>' + esc(T('This plugin\'s checklist')) + ':</strong> <span id="csa-jb-own">' + csaScore(meta.own) + '</span> / 100'
					+ '</p>' + (pl.note ? '<p class="csa-muted">' + esc(pl.note) + '</p>' : '') + '<p class="csa-muted">' + esc(meta.source) + '</p>';
			}
			if (!jobs.length) { listEl.innerHTML = '<p>✅ ' + esc(T('No jobs: nothing to fix found.')) + '</p>'; runBox.hidden = true; return; }
			runBox.hidden = false;
			listEl.innerHTML = '<table class="widefat striped csa-jb-table"><thead><tr><th></th><th>#</th><th>' + esc(T('Job')) + '</th><th>' + esc(T('How')) + '</th><th>' + esc(T('Status')) + '</th></tr></thead><tbody>'
				+ jobs.map(function (j) {
					var st = j.status === 'done' ? '✅ ' + esc(T('done')) : (j.status === 'fail' ? '❌ ' + esc(T('failed')) : (j.status === 'agent' ? '💬 ' + esc(T('chat agent')) : esc(T('to do'))));
					return '<tr data-n="' + j.n + '" class="csa-jb-' + j.status + '"><td>' + (runnable(j) ? '<input type="checkbox" class="csa-jb-pick" value="' + j.n + '">' : '') + '</td><td><strong>' + j.n + '</strong></td>'
						+ '<td>' + (ICON[j.kind] || '') + ' <strong>' + esc(j.title) + '</strong>' + (j.pts ? ' <span class="csa-muted">+' + j.pts + ' ' + esc(T('points')) + '</span>' : '') + '<br><span class="csa-muted">' + esc(j.detail || '') + '</span>'
						+ (j.result ? '<br><span class="csa-jb-res">' + esc(j.result) + '</span>' : '') + '</td><td>' + esc(HOW[j.kind] || '') + (j.kind === 'agent' && j.status !== 'done' ? '<br><button type="button" class="button button-small" data-agent="' + j.n + '">💬 ' + esc(T('Do with agent')) + '</button>' : '') + '</td><td>' + st + '</td></tr>';
				}).join('') + '</tbody></table>';
		}

		$('#csa-jb-scan').onclick = async function () {
			pid = parseInt(sel.value, 10); if (!pid) return;
			head.innerHTML = '<p class="csa-muted">' + esc(T('Scanning…')) + '</p>'; listEl.innerHTML = '';
			var r = await post('csa_jobs_scan', { id: pid });
			if (r.error) { head.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
			jobs = r.data.jobs; jbApp.dataset.chatpage = r.data.chat; draw(r.data);
		};
		$('#csa-jb-all').onchange = function () { var on = this.checked; $$('.csa-jb-pick', listEl).forEach(function (c) { c.checked = on; }); };

		function parseNums(str) {
			var out = [];
			String(str || '').split(/[,\s]+/).forEach(function (p) {
				var m = p.match(/^(\d+)-(\d+)$/);
				if (m) { for (var i = +m[1]; i <= +m[2]; i++) out.push(i); } else if (/^\d+$/.test(p)) out.push(+p);
			});
			return out;
		}
		async function runNums(nums) {
			if (running) return;
			nums = nums.filter(function (n) { return jobs.some(function (j) { return j.n === n; }); });
			if (!nums.length) { toast(T('Tick jobs or type their numbers first.'), 'err'); return; }
			running = true;
			for (var i = 0; i < nums.length; i++) {
				prog.textContent = T('Running') + ' ' + (i + 1) + ' / ' + nums.length + ' (#' + nums[i] + ')…';
				var r = await post('csa_jobs_run', { id: pid, n: nums[i] });
				if (r.error) { toast(r.error, 'err'); break; }
				var k = jobs.findIndex(function (j) { return j.n === nums[i]; });
				jobs[k] = r.data.job; if (r.data.own !== null) { var o = $('#csa-jb-own'); if (o) o.innerHTML = csaScore(r.data.own); }
				showAlerts(r.data.alerts); draw();
				if (r.data.job.status === 'fail') { toast(T('Stopped: this job failed.'), 'err'); break; }
			}
			prog.textContent = T('Done.'); running = false; overview();
		}
		var nextOpen = function () { return jobs.filter(runnable).map(function (j) { return j.n; }); };
		$('#csa-jb-go').onclick = function () {
			var nums = parseNums($('#csa-jb-nums').value);
			if (!nums.length) nums = $$('.csa-jb-pick:checked', listEl).map(function (c) { return +c.value; });
			runNums(nums);
		};
		$('#csa-jb-next').onclick = function () { runNums(nextOpen().slice(0, 1)); };
		$('#csa-jb-order').onclick = function () { runNums(nextOpen()); };
		listEl.addEventListener('click', function (e) {
			var n = e.target.dataset && e.target.dataset.agent; if (!n) return;
			var j = jobs.find(function (x) { return String(x.n) === n; });
			location.href = jbApp.dataset.chat + '&prompt=' + encodeURIComponent('Page #' + pid + ' (' + $('h2', head).textContent + '): do job ' + n + ' of its saved checklist: ' + j.title + '. ' + (j.detail || ''));
		});
		$('#csa-jb-chat').onclick = function () {
			var txt = $('#csa-jb-ask').value.trim(); if (!txt) return;
			var pg = parseInt(sel.value, 10);
			location.href = jbApp.dataset.chat + '&prompt=' + encodeURIComponent((pg ? 'Page #' + pg + ' (' + sel.options[sel.selectedIndex].text.replace(/ #\d+$/, '') + '): ' : '') + txt);
		};
		$('#csa-jb-site').onclick = async function () {
			var box = $('#csa-jb-sitelist'); box.innerHTML = '<p class="csa-muted">' + esc(T('Checking…')) + '</p>';
			var r = await post('csa_jobs_site', {});
			if (r.error) { box.innerHTML = '<p class="csa-err">' + esc(r.error) + '</p>'; return; }
			if (!r.data.length) { box.innerHTML = '<p>✅ ' + esc(T('No layout problems found.')) + '</p>'; return; }
			box.innerHTML = '<table class="widefat striped"><thead><tr><th>' + esc(T('Page')) + '</th><th>' + esc(T('Serious')) + '</th><th>' + esc(T('All')) + '</th><th>' + esc(T('Example')) + '</th><th></th></tr></thead><tbody>' + r.data.map(function (p) {
				return '<tr><td>' + esc(p.title) + ' <span class="csa-muted">#' + p.id + '</span></td><td>' + p.high + '</td><td>' + p.total + '</td><td class="csa-muted">' + esc(p.first) + '</td><td><button type="button" class="button button-small" data-open="' + p.id + '">' + esc(T('Scan this page')) + '</button></td></tr>';
			}).join('') + '</tbody></table>';
			box.onclick = function (e) {
				var id = e.target.dataset && e.target.dataset.open; if (!id) return;
				sel.innerHTML = '<option value="' + id + '">#' + id + '</option>'; sel.value = id; $('#csa-jb-scan').click(); window.scrollTo(0, 0);
			};
		};
		async function overview() {
			var o = $('#csa-jb-over'); if (!o) return;
			var r = await post('csa_jobs_overview', {});
			if (r.error || !r.data.length) { o.innerHTML = '<p class="csa-muted">' + esc(r.error || T('No page scanned yet.')) + '</p>'; return; }
			o.innerHTML = '<table class="widefat striped"><thead><tr><th>' + esc(T('Page')) + '</th><th>' + esc(T('To do')) + '</th><th>' + esc(T('Done')) + '</th><th>' + esc(T('Chat agent')) + '</th><th>' + esc(T('Scanned')) + '</th><th></th></tr></thead><tbody>' + r.data.map(function (p) {
				return '<tr><td>' + esc(p.title) + ' <span class="csa-muted">' + esc(p.type) + ' #' + p.id + '</span></td><td>' + p.c.todo + '</td><td>' + p.c.done + '</td><td>' + (p.c.agent + p.c.fail) + '</td><td class="csa-muted">' + esc(p.when) + '</td><td><button type="button" class="button button-small" data-load="' + p.id + '">' + esc(T('Open')) + '</button></td></tr>';
			}).join('') + '</tbody></table>';
			o.onclick = async function (e) {
				var id = e.target.dataset && e.target.dataset.load; if (!id) return;
				var rr = await post('csa_jobs_load', { id: id }); if (rr.error) { toast(rr.error, 'err'); return; }
				pid = parseInt(id, 10); jobs = rr.data.jobs; sel.innerHTML = '<option value="' + id + '">' + esc(rr.data.title) + ' #' + id + '</option>'; sel.value = id; draw(rr.data); jbApp.scrollIntoView();
			};
		}
		overview();
		loadPages();
	})();

	/* ================================================================
	   Settings: model presets
	   ================================================================ */

	$$('.csa-preset').forEach(function (b) {
		b.onclick = function () {
			var p = JSON.parse(b.dataset.preset);
			$$('.csa-task-select').forEach(function (s) { if (p[s.dataset.task]) s.value = p[s.dataset.task]; });
			$$('.csa-preset').forEach(function (x) { x.classList.remove('button-primary'); });
			b.classList.add('button-primary');
			toast(p.label + ' ✓ — ' + T('Save Changes'));
		};
	});

	/* ================================================================
	   Post format preview
	   ================================================================ */

	var fmtPrev = $('#csa-fmt-preview .csa-article');
	if (fmtPrev) $$('.csa-format-form input[type="color"]').forEach(function (inp) {
		inp.addEventListener('input', function () { fmtPrev.style.setProperty(inp.dataset.var, inp.value); });
	});

	/* ================================================================
	   Activity: undo
	   ================================================================ */

	$$('.csa-undo').forEach(function (b) {
		b.onclick = async function () {
			if (!confirm(T('Undo this change?'))) return;
			b.disabled = true;
			var r = await post('csa_undo', { id: b.dataset.id });
			if (r.error) { toast(r.error, 'err'); b.disabled = false; return; }
			b.replaceWith(document.createTextNode(T('Undone ✓')));
		};
	});
})();
