// Bölüm sekmeleri (kayıt detayı, prosedür sayfası): <div data-module="section-tabs"> içinde
// <x-section-tabs.nav> sekme çubuğu ve <x-section-tabs.pane> panelleri (#pane-{bölüm}); her panel
// tek bir bölümü (#{bölüm}) sarar. Adres çapası bir panelin içini gösteriyorsa (#materials, #step-12,
// aksiyonlardan sonraki #now) önce o panelin sekmesi açılır, sonra çapaya kaydırılır. Sayfadaki
// "#…" bağlantıları (ilerleme göstergesi, "Malzemelere git") da aynı yoldan gider; hedefi panelde
// olmayan bağlantıya (#cancel) dokunulmaz. Sekme değişince adres #bölüm olur (sekme düğmesinin
// data-section'ı), yenilemede aynı sekme açılır. JS yoksa sunucunun etkin getirdiği ilk sekme görünür.

export default function (element) {
    const nav = element.querySelector('[role="tablist"]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const targetOf = (hash) => {
        if (!hash || hash === '#') {
            return null;
        }

        try {
            return document.getElementById(decodeURIComponent(hash.slice(1)));
        } catch {
            return null;
        }
    };

    const paneOf = (target) => {
        const pane = target?.closest('.tab-pane');

        return pane && element.contains(pane) ? pane : null;
    };

    // Çapa panelin içindeyse sekmeyi açar ve kaydırır; panelde değilse false döner.
    const reveal = (target, smooth) => {
        const pane = paneOf(target);

        if (!pane) {
            return false;
        }

        const tab = element.querySelector(`[data-bs-target="#${CSS.escape(pane.id)}"]`);

        if (tab && !pane.classList.contains('active')) {
            // Panel "active" sınıfını hemen alır (fade yalnızca saydamlık); kaydırma beklemez.
            window.bootstrap.Tab.getOrCreateInstance(tab).show();
        }

        const behavior = smooth && !reducedMotion.matches ? 'smooth' : 'auto';

        if (target.parentElement === pane) {
            // Bölümün kendisi: sekme çubuğu görünür kalsın; çubuk zaten ekrandaysa kaydırılmaz.
            if (nav && nav.getBoundingClientRect().top < 0) {
                nav.scrollIntoView({ behavior, block: 'start' });
            }
        } else {
            target.scrollIntoView({ behavior, block: 'start' });
        }

        return true;
    };

    // Sekme değişince adres #bölüm olur; adres zaten bu panelin içini gösteriyorsa (#step-12) korunur.
    element.addEventListener('shown.bs.tab', (event) => {
        const pane = targetOf(event.target.dataset.bsTarget);

        if (pane && pane.contains(targetOf(window.location.hash))) {
            return;
        }

        window.history.replaceState(null, '', `#${event.target.dataset.section}`);
    });

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a[href^="#"]');
        const target = targetOf(link?.getAttribute('href'));

        if (!paneOf(target)) {
            return;
        }

        event.preventDefault();

        if (window.location.hash !== link.getAttribute('href')) {
            window.history.pushState(null, '', link.getAttribute('href'));
        }

        reveal(target, true);
    });

    // Geri/ileri ve elle yazılan çapa
    window.addEventListener('hashchange', () => reveal(targetOf(window.location.hash), true));

    reveal(targetOf(window.location.hash), false);
}
