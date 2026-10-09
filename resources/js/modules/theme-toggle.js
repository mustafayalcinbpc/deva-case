// Açık/koyu tema düğmesi (layouts/partials/theme-toggle). Tema <html data-bs-theme> ile
// uygulanır; ilk boyamadan önce layouts/partials/theme-init bunu kayıtlı seçime ya da işletim
// sisteminin tercihine göre ayarlar. Kullanıcı seçimi localStorage'da saklanır; seçim yoksa
// sistem tercihi değiştiğinde tema da değişir. Geçiş kısa ve yumuşaktır; hareket azaltma
// tercihinde geçiş yapılmaz.

const STORAGE_KEY = 'app-theme';
const TRANSITION_CLASS = 'theme-transition';
const TRANSITION_MS = 260;

const root = document.documentElement;
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const buttons = new Set();
let transitionTimer = null;
let listening = false;

function storedTheme() {
    try {
        const value = localStorage.getItem(STORAGE_KEY);

        return value === 'light' || value === 'dark' ? value : null;
    } catch {
        return null;
    }
}

function store(theme) {
    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // Gizli pencere ya da kapalı depolama: seçim yalnızca bu sayfada geçerli kalır.
    }
}

function currentTheme() {
    return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
}

function render() {
    const dark = currentTheme() === 'dark';
    // Etiket düğmenin yapacağı işi söyler: açık temadayken "Koyu tema".
    const label = dark ? 'Açık tema' : 'Koyu tema';

    buttons.forEach((button) => {
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    });
}

function apply(theme, { animate = true } = {}) {
    if (theme === currentTheme()) {
        return;
    }

    if (animate && !reducedMotion.matches) {
        root.classList.add(TRANSITION_CLASS);
        window.clearTimeout(transitionTimer);
        transitionTimer = window.setTimeout(() => root.classList.remove(TRANSITION_CLASS), TRANSITION_MS);
    }

    root.setAttribute('data-bs-theme', theme);
    render();
}

function listen() {
    if (listening) {
        return;
    }

    listening = true;

    // Kullanıcı seçim yapmadıysa işletim sistemi tercihini izle.
    systemDark.addEventListener('change', (event) => {
        if (storedTheme() === null) {
            apply(event.matches ? 'dark' : 'light');
        }
    });

    // Başka sekmede yapılan seçim bu sekmeye de yansır.
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY) {
            apply(storedTheme() ?? (systemDark.matches ? 'dark' : 'light'));
        }
    });
}

export default function themeToggle(button) {
    buttons.add(button);
    render();
    listen();

    button.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';

        store(next);
        apply(next);
    });
}
