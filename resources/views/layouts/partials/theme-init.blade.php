{{--
    Tema ilk boyamadan önce uygulanır (yanıp sönme olmaz): kayıtlı seçim (localStorage
    "app-theme"), yoksa işletim sisteminin tercihi. Düğme: resources/js/modules/theme-toggle.js.
--}}
<script>(function(d){var t;try{t=localStorage.getItem('app-theme')}catch(e){}if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}d.documentElement.setAttribute('data-bs-theme',t)})(document);</script>
