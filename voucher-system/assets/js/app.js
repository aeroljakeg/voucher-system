(function () {
    const root = document.documentElement;
    const toggle = document.querySelector('[data-theme-toggle]');
    const storageKey = root.dataset.themeKey || 'voucher-system-theme';
    const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    let savedTheme = null;

    try {
        savedTheme = window.localStorage.getItem(storageKey);
    } catch (error) {
        console.warn('Could not read the saved theme preference.', error);
        savedTheme = null;
    }

    function setTheme(theme) {
        root.dataset.theme = theme;
        if (toggle) {
            toggle.textContent = toggle.dataset.themeIcon === 'true'
                ? (theme === 'dark' ? '☀️' : '🌙')
                : (theme === 'dark' ? 'Light mode' : 'Dark mode');
            toggle.setAttribute('aria-label', 'Switch to ' + (theme === 'dark' ? 'light' : 'dark') + ' mode');
        }
    }

    setTheme(savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : preferredTheme);

    if (toggle) {
        toggle.addEventListener('click', function () {
            const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try {
                window.localStorage.setItem(storageKey, theme);
            } catch (error) {
                console.warn('Could not save the theme preference.', error);
            }
            setTheme(theme);
        });
    }

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            if (!window.confirm(element.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-add-voucher-line]').forEach(function (button) {
        button.addEventListener('click', function () {
            const body = document.querySelector('[data-voucher-lines]');
            const template = document.getElementById('voucher-line-template');
            if (!body || !template) {
                return;
            }

            const index = Number(body.dataset.nextIndex || body.children.length);
            const row = template.content.cloneNode(true);
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/__INDEX__/g, String(index));
            });
            body.appendChild(row);
            body.dataset.nextIndex = String(index + 1);
        });
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-voucher-line]');
        if (!button) {
            return;
        }

        const row = button.closest('[data-voucher-line]');
        const body = row && row.parentElement;
        if (!row || !body) {
            return;
        }

        if (body.querySelectorAll('[data-voucher-line]').length > 2) {
            row.remove();
            return;
        }

        row.querySelectorAll('input').forEach(function (field) {
            field.value = '';
        });
        row.querySelector('select').selectedIndex = 0;
    });
}());
