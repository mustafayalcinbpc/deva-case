// Canlı süre sayacı. Sunucu açık çalışma dilimi için şu verileri yazar:
//   data-started-at   açık dilimin başlangıcı (ISO 8601)
//   data-base-seconds kapanmış dilimlerden gelen süre (sn)
//   data-rate         saniyede artış: süre için 1, efor için dilimdeki kişi sayısı
//   data-server-now   sayfanın üretildiği an (ISO 8601); tarayıcı saatindeki kayma düzeltilir
// Değer = base + rate × (şimdi − başlangıç). JS yoksa sunucunun yazdığı değer olduğu gibi kalır.
// Biçim <x-duration> bileşeniyle aynıdır.

const pad = (value) => String(value).padStart(2, '0');

export function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    if (hours > 0) {
        return `${hours} sa ${pad(minutes)} dk`;
    }

    if (minutes > 0) {
        return `${minutes} dk ${pad(rest)} sn`;
    }

    return `${rest} sn`;
}

export default function (element) {
    const startedAt = Date.parse(element.dataset.startedAt ?? '');

    if (Number.isNaN(startedAt)) {
        return;
    }

    const base = Number(element.dataset.baseSeconds) || 0;
    const rate = Number(element.dataset.rate) || 1;
    const serverNow = Date.parse(element.dataset.serverNow ?? '');
    const offset = Number.isNaN(serverNow) ? 0 : serverNow - Date.now();
    const target = element.querySelector('.app-duration') ?? element;

    const render = () => {
        const elapsed = Math.max(0, Math.floor((Date.now() + offset - startedAt) / 1000));
        const seconds = base + elapsed * rate;

        target.textContent = formatDuration(seconds);
        target.title = `${seconds} sn`;
    };

    render();
    window.setInterval(render, 1000);
}
