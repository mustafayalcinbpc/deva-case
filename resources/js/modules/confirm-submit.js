// Gönderimden önce onay ister: <form data-module="confirm-submit" data-confirm="Mesaj">.
// JS yoksa form doğrudan gönderilir; asıl kontrol sunucudadır.

export default function (form) {
    const message = form.dataset.confirm || 'Bu işlemi yapmak istediğinize emin misiniz?';

    form.addEventListener('submit', (event) => {
        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });
}
