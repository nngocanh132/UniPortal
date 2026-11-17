/**
 * UniPortal – live tuition payment polling.
 * Refreshes invoice status from the backend every 8 seconds. A payment is
 * only shown as successful when the server reports a confirmed transaction.
 */
(function () {
    'use strict';

    var cards = Array.prototype.slice.call(document.querySelectorAll('.pay-invoice'));
    if (!cards.length) return;

    var ENDPOINT = '../../api/payment_status.php';
    var INTERVAL = 8000;
    var busy = false;
    var stopped = false;

    function vnd(n) {
        return Number(n).toLocaleString('vi-VN') + ' ₫';
    }

    function setText(el, text) {
        if (el && el.textContent !== text) el.textContent = text;
    }

    function banner(message, ok) {
        var box = document.getElementById('pay-live-banner');
        if (!box) {
            box = document.createElement('div');
            box.id = 'pay-live-banner';
            box.setAttribute('role', 'status');
            box.style.cssText = 'padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:14px;';
            var host = document.querySelector('.stat-row');
            if (host && host.parentNode) host.parentNode.insertBefore(box, host);
            else document.body.insertBefore(box, document.body.firstChild);
        }
        box.style.background = ok ? '#d1fae5' : '#fffbeb';
        box.style.border = '1px solid ' + (ok ? '#6ee7b7' : '#fde68a');
        box.style.color = ok ? '#065f46' : '#854d0e';
        box.textContent = message;
    }

    function apply(invoice) {
        var card = document.getElementById('invoice-' + invoice.tuition_id);
        if (!card) return false;

        var previous = card.getAttribute('data-status');
        card.setAttribute('data-status', invoice.status);
        card.setAttribute('data-outstanding', String(invoice.outstanding));

        setText(card.querySelector('.js-confirmed'), vnd(invoice.confirmed));
        var out = card.querySelector('.js-outstanding');
        setText(out, vnd(invoice.outstanding));
        if (out) out.style.color = invoice.outstanding > 0 ? '#ef4444' : '#059669';

        var pill = card.querySelector('.js-status');
        if (pill) {
            pill.textContent = invoice.status_label;
            pill.style.background = invoice.status_bg;
            pill.style.color = invoice.status_color;
        }

        if (invoice.request) {
            setText(card.querySelector('.js-qr-amount'), vnd(invoice.request.amount_vnd));
            setText(card.querySelector('.js-reference'), invoice.request.reference);
            if (invoice.request.expires_at) {
                var d = new Date(invoice.request.expires_at.replace(' ', 'T'));
                if (!isNaN(d.getTime())) {
                    setText(card.querySelector('.js-expiry'),
                        d.toLocaleDateString('vi-VN') + ' ' +
                        d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }));
                }
            }
        }

        return previous !== invoice.status;
    }

    function poll() {
        if (busy || stopped || document.hidden) return;
        busy = true;

        fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok || !Array.isArray(data.invoices)) return;

                var changed = false;
                var justPaid = null;
                var needsReview = false;

                data.invoices.forEach(function (inv) {
                    if (apply(inv)) {
                        changed = true;
                        if (inv.status === 'Paid' || inv.status === 'Waived') justPaid = inv;
                        if (inv.status === 'Review') needsReview = true;
                    }
                });

                if (justPaid) {
                    stopped = true;
                    banner('✅ Đã nhận thanh toán cho học kỳ ' + justPaid.semester +
                        '. Hệ thống đang cập nhật hóa đơn…', true);
                    setTimeout(function () { location.reload(); }, 1200);
                } else if (needsReview) {
                    stopped = true;
                    banner('🕵️ Giao dịch của bạn đang được xác minh. Trang sẽ tự cập nhật.', false);
                    setTimeout(function () { location.reload(); }, 1200);
                } else if (changed) {
                    banner('🔄 Trạng thái học phí vừa được cập nhật.', true);
                    setTimeout(function () { location.reload(); }, 1200);
                }
            })
            .catch(function () { /* transient network error – keep polling */ })
            .then(function () { busy = false; });
    }

    setInterval(poll, INTERVAL);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
})();
