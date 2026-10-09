// Yayın formu (K-15): tarih girilince "İleri bir tarihte" seçilir, "Hemen" seçilince tarih
// temizlenir. JS yoksa sunucu "Hemen" ile birlikte gönderilen tarihi reddeder.

export default function (form) {
    const input = form.querySelector('input[name="publish_at"]');
    const now = form.querySelector('input[name="when"][value="now"]');
    const scheduled = form.querySelector('input[name="when"][value="scheduled"]');

    if (!input || !now || !scheduled) {
        return;
    }

    input.addEventListener('input', () => {
        if (input.value !== '') {
            scheduled.checked = true;
        }
    });

    now.addEventListener('change', () => {
        if (now.checked) {
            input.value = '';
        }
    });

    scheduled.addEventListener('change', () => {
        if (scheduled.checked) {
            input.focus();
        }
    });
}
