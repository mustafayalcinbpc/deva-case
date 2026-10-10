// Gönderimden önce onay ister: <form data-module="confirm-submit" data-confirm="Mesaj">.
// Onay, sayfadaki ortak pencerede (<x-confirm-modal>, #confirm-modal) sorulur. İsteğe bağlı
// data-confirm-title (başlık), data-confirm-accept (onay düğmesi) ve data-confirm-variant
// (onay düğmesinin rengi: primary, danger…). Pencere yoksa tarayıcının onay kutusu kullanılır.
// JS yoksa form doğrudan gönderilir; asıl kontrol sunucudadır.
//
// Onaylanan form requestSubmit ile yeniden gönderilir: tıklanan düğme (name/value) korunur ve
// submit-once gibi diğer modüller gönderimi normal şekilde görür.

const defaults = {
    title: 'Onay',
    message: 'Bu işlemi yapmak istediğinize emin misiniz?',
    accept: 'Devam et',
    variant: 'primary',
};

// Pencere sayfada tektir; açık olan isteğin formu ve onaylanıp onaylanmadığı burada tutulur.
let pending = null;

function setUp(element) {
    if (element.dataset.confirmReady) {
        return;
    }

    element.dataset.confirmReady = 'true';

    element.querySelector('[data-confirm-accept]').addEventListener('click', () => {
        if (pending) {
            pending.accepted = true;
        }

        window.bootstrap.Modal.getOrCreateInstance(element).hide();
    });

    element.addEventListener('shown.bs.modal', () => {
        element.querySelector('[data-confirm-accept]').focus();
    });

    // Pencere kapandıktan sonra gönderilir: geri dönülünce (bfcache) pencere açık kalmaz.
    element.addEventListener('hidden.bs.modal', () => {
        const request = pending;
        pending = null;

        if (!request) {
            return;
        }

        if (!request.accepted) {
            request.submitter?.focus();
            return;
        }

        request.form.dataset.confirmed = 'true';
        request.form.requestSubmit(request.submitter?.form === request.form ? request.submitter : undefined);
    });
}

function open(element, form, submitter) {
    setUp(element);

    const accept = element.querySelector('[data-confirm-accept]');
    element.querySelector('#confirm-modal-title').textContent = form.dataset.confirmTitle || defaults.title;
    element.querySelector('#confirm-modal-message').textContent = form.dataset.confirm || defaults.message;
    accept.textContent = form.dataset.confirmAccept || defaults.accept;
    accept.className = `btn btn-${form.dataset.confirmVariant || defaults.variant}`;

    pending = { form, submitter, accepted: false };
    window.bootstrap.Modal.getOrCreateInstance(element).show();
}

export default function (form) {
    form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const element = document.getElementById('confirm-modal');

        if (!element || !window.bootstrap?.Modal) {
            if (window.confirm(form.dataset.confirm || defaults.message)) {
                form.dataset.confirmed = 'true';
                form.requestSubmit(event.submitter?.form === form ? event.submitter : undefined);
            }

            return;
        }

        if (!pending) {
            open(element, form, event.submitter);
        }
    });
}
