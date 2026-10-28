/**
 * 首页订阅卡片的交互：AJAX 提交，不刷新页面。
 *
 * 几个刻意的选择：
 * - 事件委托到 document：主题 PJAX 换页只替换 #page，卡片会被重建，
 *   委托后不需要重新绑定；
 * - 幂等守卫：脚本可能因 PJAX/重复加载执行多次，用 window 上的标记只装一次；
 * - 失败兜底：fetch 出错或网络异常时，自动退回原生表单提交（整页刷新），
 *   保证订阅不会因为前端问题走不通；
 * - 提示写进卡片的 .psn-notice，并用 aria-live 让读屏软件也能读到。
 */
(function () {
    'use strict';

    if (window.__iroPsnCard) {
        return;
    }
    window.__iroPsnCard = 1;

    var SUBMITTING = 'is-loading';

    function cardOf(el) {
        return el && el.closest ? el.closest('.psn-card') : null;
    }

    function noticeOf(card) {
        return card ? card.querySelector('.psn-notice') : null;
    }

    function showNotice(card, text, tone) {
        var notice = noticeOf(card);
        if (!notice) {
            return;
        }
        notice.textContent = text;
        notice.className = 'psn-notice';
        if (tone) {
            notice.classList.add('is-' + tone);
        }
        notice.hidden = false;
    }

    function setBusy(card, busy) {
        if (!card) {
            return;
        }
        var button = card.querySelector('.psn-button');
        // 只替换文字节点，按钮里的图标要留着。
        var label = button ? button.querySelector('.psn-button__text') : null;
        var inputs = card.querySelectorAll('.psn-input');
        if (button) {
            button.disabled = busy;
            button.classList.toggle(SUBMITTING, busy);
            if (label) {
                if (busy) {
                    button.dataset.label = label.textContent;
                    label.textContent = '提交中…';
                } else if (button.dataset.label) {
                    label.textContent = button.dataset.label;
                }
            }
        }
        Array.prototype.forEach.call(inputs, function (input) {
            input.disabled = busy;
        });
    }

    function clearInvalid(card) {
        if (!card) {
            return;
        }
        Array.prototype.forEach.call(card.querySelectorAll('.psn-input.is-invalid'), function (input) {
            input.classList.remove('is-invalid');
        });
    }

    /**
     * 网络异常或服务端抛错时的兜底：走原生表单提交。
     * 浏览器会整页刷新并带 psn_status 回到卡片，订阅流程仍可完成。
     */
    function fallbackSubmit(form) {
        if (!form) {
            return;
        }
        HTMLFormElement.prototype.submit.call(form);
    }

    function submit(form) {
        var card = cardOf(form);
        var email = form.querySelector('input[name="psn_email"]');
        var ajaxUrl = card ? card.getAttribute('data-ajax') : '';

        if (!ajaxUrl || !window.fetch || !window.FormData) {
            fallbackSubmit(form);
            return;
        }

        if (!email || !email.value.trim()) {
            email.classList.add('is-invalid');
            showNotice(card, '请输入邮箱地址。', 'error');
            email.focus();
            return;
        }

        clearInvalid(card);

        // 关键顺序：先把表单数据取出来，再禁用输入框。
        // disabled 的控件不会被 FormData 收集，先禁用会丢掉 psn_email / psn_name。
        var body = new FormData(form);
        // 表单里有个 psn_subscribe=1 的隐藏字段，用于 JS 失效时走原生 POST 兜底。
        // AJAX 路径不需要它（它会让 init 钩子误判成原生提交并 302），这里移除。
        body.delete('psn_subscribe');
        body.append('action', 'psn_subscribe');

        setBusy(card, true);

        fetch(ajaxUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data) {
                    throw new Error('bad payload');
                }
                var data = payload.data;
                setBusy(card, false);
                showNotice(card, data.message || '提交成功。', data.ok ? (data.status === 'confirmed' ? 'success' : 'info') : 'error');
                if (data.status === 'invalid_email' && email) {
                    email.classList.add('is-invalid');
                    email.focus();
                }
                if (data.ok) {
                    // 订阅成功后清空输入，避免用户重复点提交
                    if (email) {
                        email.value = '';
                    }
                    var name = form.querySelector('input[name="psn_name"]');
                    if (name) {
                        name.value = '';
                    }
                    // 邮件还没发出去的提示要留在原地，不要自动藏掉
                    if (card) {
                        card.classList.add('is-done');
                    }
                }
            })
            .catch(function () {
                setBusy(card, false);
                showNotice(card, '网络异常，正在改用普通提交…', 'error');
                fallbackSubmit(form);
            });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.classList || !form.classList.contains('psn-form')) {
            return;
        }
        event.preventDefault();
        submit(form);
    });

    // 提交时按了回车也会走到 submit 事件，这里不用额外处理 keydown
})();
