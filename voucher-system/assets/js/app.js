(function () {
    const root = document.documentElement;
    const shell = document.querySelector('.app-shell');
    const toggle = document.querySelector('[data-theme-toggle]');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const themeStorageKey = root.dataset.themeKey || 'voucher-system-theme';
    const sidebarStorageKey = 'voucher-system-sidebar-collapsed';
    const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    let savedTheme = null;
    let sidebarCollapsed = false;

    try {
        savedTheme = window.localStorage.getItem(themeStorageKey);
        sidebarCollapsed = window.localStorage.getItem(sidebarStorageKey) === '1';
    } catch (error) {
        console.warn('Could not read saved preferences.', error);
        savedTheme = null;
        sidebarCollapsed = false;
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

    function setSidebarState(collapsed) {
        if (!shell) {
            return;
        }

        shell.classList.toggle('sidebar-collapsed', collapsed);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarToggle.textContent = collapsed ? '☰' : '✕';
        }

        try {
            window.localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0');
        } catch (error) {
            console.warn('Could not save the sidebar preference.', error);
        }
    }

    setTheme(savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : preferredTheme);
    setSidebarState(sidebarCollapsed);

    if (toggle) {
        toggle.addEventListener('click', function () {
            const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try {
                window.localStorage.setItem(themeStorageKey, theme);
            } catch (error) {
                console.warn('Could not save the theme preference.', error);
            }
            setTheme(theme);
        });
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            setSidebarState(!shell.classList.contains('sidebar-collapsed'));
        });
    }

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            if (!window.confirm(element.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.dataset.passwordToggleAdded === 'true') {
            return;
        }

        input.dataset.passwordToggleAdded = 'true';

        const wrapper = document.createElement('span');
        wrapper.className = 'password-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.textContent = 'Show';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-controls', input.id || '');
        button.setAttribute('aria-pressed', 'false');

        button.addEventListener('click', function () {
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show' : 'Hide';
            button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            button.setAttribute('aria-pressed', String(!showing));
        });

        wrapper.appendChild(button);
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
