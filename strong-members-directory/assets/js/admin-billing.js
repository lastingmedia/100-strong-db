/* Stripe Billing panel on the member edit screen. All text is inserted with textContent. */
(function () {
	var panel = document.getElementById('smd-billing-panel');
	if (!panel || !window.SMDBilling || !panel.querySelector('.smd-billing-loading')) { return; }

	var memberId = panel.getAttribute('data-member');
	var busy = false;

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function money(cents, currency) {
		try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(cents / 100); }
		catch (e) { return (cents / 100).toFixed(2) + ' ' + (currency || '').toUpperCase(); }
	}

	function date(ts) { return ts ? new Date(ts * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—'; }

	function post(data) {
		var body = new URLSearchParams(data);
		body.set('nonce', SMDBilling.nonce);
		body.set('member_id', memberId);
		return fetch(SMDBilling.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	function notice(message, isError) {
		var old = panel.querySelector('.smd-billing-notice'); if (old) { old.remove(); }
		var n = el('div', { 'class': 'notice inline ' + (isError ? 'notice-error' : 'notice-success') + ' smd-billing-notice' });
		n.appendChild(el('p', {}, message));
		panel.insertBefore(n, panel.firstChild);
	}

	function run(data, confirmText) {
		if (busy) { return; }
		if (confirmText && !window.confirm(confirmText)) { return; }
		busy = true;
		post(data).then(function (res) {
			busy = false;
			if (res && res.success) { load(res.data.message); } else { notice((res && res.data && res.data.message) || 'Something went wrong.', true); }
		}).catch(function () { busy = false; notice('Could not reach the server.', true); });
	}

	function button(label, handler, danger) {
		var b = el('button', { type: 'button', 'class': 'button' + (danger ? ' button-link-delete' : '') }, label);
		b.addEventListener('click', handler);
		return b;
	}

	function render(d, msg) {
		panel.textContent = '';
		if (msg) { notice(msg, false); }
		var sub = d.subscription;

		var summary = el('table', { 'class': 'widefat striped', style: 'max-width:640px;margin-bottom:12px' });
		function row(k, v) { var tr = el('tr'); tr.appendChild(el('th', { style: 'width:190px' }, k)); tr.appendChild(el('td', {}, v)); summary.appendChild(tr); }
		row('Billing state', d.state_label);
		if (sub) {
			var every = sub.interval ? ' / ' + (sub.interval_count > 1 ? sub.interval_count + ' ' : '') + sub.interval : '';
			row('Stripe status', sub.status + (sub.paused ? ' (paused)' : '') + (sub.cancel_at_period_end ? ' (cancels at period end)' : ''));
			row('Plan', money(sub.amount, sub.currency) + every);
			row('Current period ends', date(sub.current_period_end));
		} else { row('Subscription', 'None found'); }
		row('Card on file', d.card ? d.card.brand + ' •••• ' + d.card.last4 + ' (exp ' + d.card.exp_month + '/' + d.card.exp_year + ')' : 'No card on file');
		panel.appendChild(summary);

		var actions = el('p', { style: 'display:flex;gap:8px;flex-wrap:wrap' });
		actions.appendChild(button('Email update-card link', function () { run({ action: 'smd_billing_action', 'do': 'send_card_link' }, 'Email the member a link to update their card?'); }));
		if (sub && sub.status !== 'canceled') {
			actions.appendChild(sub.paused
				? button('Resume billing', function () { run({ action: 'smd_billing_action', 'do': 'resume' }); })
				: button('Pause billing', function () { run({ action: 'smd_billing_action', 'do': 'pause' }, 'Pause billing? No charges will be made until you resume.'); }));
			actions.appendChild(sub.cancel_at_period_end
				? button('Keep subscription', function () { run({ action: 'smd_billing_action', 'do': 'keep' }); })
				: button('Cancel at period end', function () { run({ action: 'smd_billing_action', 'do': 'cancel_at_period_end' }, 'Cancel this subscription at the end of the current period?'); }));
			actions.appendChild(button('Cancel now', function () { run({ action: 'smd_billing_action', 'do': 'cancel_now' }, 'Cancel this subscription immediately? This cannot be undone.'); }, true));
		}
		panel.appendChild(actions);

		panel.appendChild(el('h4', {}, 'Payment history'));
		if (!d.invoices.length) { panel.appendChild(el('p', {}, 'No invoices yet.')); }
		else {
			var t = el('table', { 'class': 'widefat striped' });
			var head = el('tr'); ['Date', 'Invoice', 'Amount', 'Result', ''].forEach(function (h) { head.appendChild(el('th', {}, h)); });
			t.appendChild(el('thead')).appendChild(head);
			var tb = el('tbody');
			d.invoices.forEach(function (inv) {
				var tr = el('tr');
				tr.appendChild(el('td', {}, date(inv.created)));
				tr.appendChild(el('td', {}, inv.number || inv.id));
				tr.appendChild(el('td', {}, money(inv.status === 'paid' ? inv.amount_paid : inv.amount_due, inv.currency)));
				var result = { paid: 'Paid', failed: 'Failed', open: 'Open', void: 'Void', uncollectible: 'Uncollectible', draft: 'Draft' }[inv.status] || inv.status;
				if (inv.status === 'failed') { result += ' (attempt ' + inv.attempts + (inv.failure_message ? ': ' + inv.failure_message : '') + (inv.next_attempt ? '; retry ' + date(inv.next_attempt) : '') + ')'; }
				if (inv.amount_refunded) { result += ' — refunded ' + money(inv.amount_refunded, inv.currency); }
				tr.appendChild(el('td', {}, result));
				var td = el('td', { style: 'white-space:nowrap' });
				if (inv.hosted_url) { var a = el('a', { href: inv.hosted_url, target: '_blank', rel: 'noopener noreferrer' }, 'View'); td.appendChild(a); td.appendChild(document.createTextNode(' · ')); }
				if (inv.status === 'paid') {
					td.appendChild(button('Send receipt', function () { run({ action: 'smd_billing_action', 'do': 'send_receipt', target: inv.id }, 'Email this receipt to the member?'); }));
					if (inv.charge_id && inv.amount_paid > inv.amount_refunded) {
						td.appendChild(document.createTextNode(' '));
						td.appendChild(button('Refund', function () {
							var max = (inv.amount_paid - inv.amount_refunded) / 100;
							var input = window.prompt('Refund amount (max ' + max.toFixed(2) + '). Leave blank for the full amount.', max.toFixed(2));
							if (input === null) { return; }
							var cents = Math.round(parseFloat(input) * 100);
							if (!(cents > 0)) { notice('Enter a valid amount.', true); return; }
							run({ action: 'smd_billing_action', 'do': 'refund', target: inv.charge_id, amount: cents }, 'Refund ' + money(cents, inv.currency) + '? This cannot be undone.');
						}, true));
					}
				}
				tr.appendChild(td);
				tb.appendChild(tr);
			});
			t.appendChild(tb);
			panel.appendChild(t);
		}

		if (d.log && d.log.length) {
			panel.appendChild(el('h4', {}, 'Staff actions'));
			var ul = el('ul', { style: 'margin-left:18px;list-style:disc' });
			d.log.forEach(function (l) { ul.appendChild(el('li', {}, date(l.time) + ' — ' + l.user + ': ' + l.detail)); });
			panel.appendChild(ul);
		}
	}

	function load(msg) {
		post({ action: 'smd_billing_overview' }).then(function (res) {
			if (res && res.success) { render(res.data, msg); }
			else { panel.textContent = ''; notice((res && res.data && res.data.message) || 'Could not load billing details.', true); }
		}).catch(function () { panel.textContent = ''; notice('Could not reach the server.', true); });
	}

	load();
})();
