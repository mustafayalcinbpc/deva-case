import * as bootstrap from 'bootstrap';
import 'admin-lte';
import 'bootstrap-icons/font/bootstrap-icons.css';

window.bootstrap = bootstrap;

// Sayfaya özel davranışlar: data-module="ad" taşıyan eleman için resources/js/modules/ad.js
// dosyasının varsayılan export'u o elemanla çağrılır. Birden fazla modül boşlukla ayrılır.
const modules = import.meta.glob('./modules/*.js');

document.querySelectorAll('[data-module]').forEach((element) => {
    element.dataset.module.split(/\s+/).filter(Boolean).forEach(async (name) => {
        const load = modules[`./modules/${name}.js`];

        if (!load) {
            console.warn(`Bilinmeyen modül: ${name}`);
            return;
        }

        const { default: init } = await load();
        init(element);
    });
});
