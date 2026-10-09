// Formu bir kez gönderir: gönderimden sonra gönder butonları kilitlenir. Dokunmatik ekranda çift
// dokunma aynı aksiyonu iki kez göndermesin (ikincisi kural ihlali mesajı olarak dönerdi).
// Başka bir modül gönderimi iptal ettiyse (ör. confirm-submit) butonlar açık kalır. Sayfaya
// geri dönülünce (bfcache) butonlar yeniden açılır.

export default function (form) {
    const buttons = () => [
        ...form.querySelectorAll('button[type="submit"], button:not([type])'),
        ...(form.id ? document.querySelectorAll(`button[form="${CSS.escape(form.id)}"]`) : []),
    ];

    form.addEventListener('submit', (event) => {
        // Diğer dinleyiciler çalıştıktan sonra karar verilir.
        window.setTimeout(() => {
            if (event.defaultPrevented) {
                return;
            }

            buttons().forEach((button) => {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            });
        }, 0);
    });

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }

        buttons().forEach((button) => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        });
    });
}
