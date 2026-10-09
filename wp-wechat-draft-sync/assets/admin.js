(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var list = document.getElementById('wwds-accounts');
        var button = document.getElementById('wwds-add-account');
        var template = document.getElementById('tmpl-wwds-account');
        if (!list || !button || !template) return;

        button.addEventListener('click', function () {
            var stamp = Date.now().toString();
            var html = template.innerHTML.split('__INDEX__').join(stamp).split('__ID__').join('account_' + stamp);
            list.insertAdjacentHTML('beforeend', html);
            var cards = list.querySelectorAll('.wwds-account');
            if (cards.length) cards[cards.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        list.addEventListener('click', function (event) {
            var remove = event.target.closest('.wwds-remove-account');
            if (remove && window.confirm('确定删除这个公众号配置吗？保存设置后生效。')) {
                remove.closest('.wwds-account').remove();
            }
        });

        list.addEventListener('input', function (event) {
            if (!event.target.matches('input[name$="[name]"]')) return;
            var card = event.target.closest('.wwds-account');
            var title = card && card.querySelector('.wwds-account-head h3');
            if (title) title.textContent = event.target.value || '新公众号';
        });
    });
})();

