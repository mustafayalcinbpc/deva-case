# Kayıt detayı: sekmeler ve ilerleme göstergesi

İstek (9 Ekim 2026): `/cleanings/{id}` sayfasında Şimdi, Adımlar, Özet, Malzemeler ve Olay geçmişi
başlıkları sekmeye dönüşsün; her sekmede yalnızca kendi bölümü görünsün. Sağ sütunda fazların ve
adımların ilerlemesi kargo takip ekranlarındaki gibi noktadan noktaya gösterilsin; tamamlanan,
devam eden ve gelecek fazlar/adımlar renkleriyle ayrılsın. Sayfa operatör ve yönetici için aynı
şablondur; değişiklik iki role de uygulanır.

> Not (10 Ekim 2026): sekme modülü prosedür sayfasında da kullanılmak üzere `section-tabs.js`
> adını aldı; çubuk ve paneller `<x-section-tabs.nav>` / `<x-section-tabs.pane>` bileşenleri,
> ortak stiller `theme/_components.scss` (`.section-tabs__*`).

## Yerleşim

```
┌ başlık: kayıt no, durum, konum ────────────────────────────────────────────┐
├ sol sütun (2fr) ───────────────────────────────┬ sağ sütun (1fr) ───────────┤
│ [Şimdi] [Adımlar] [Özet] [Malzemeler] [Olay g.] │ İlerleme (#progress)       │
│ ┌ etkin sekmenin bölümü ──────────────────────┐ │  ● Kayıt açıldı            │
│ │ yalnızca o bölüm                            │ │  ● 1. faz  ✓               │
│ └─────────────────────────────────────────────┘ │  ◉ 2. faz  şu an           │
│                                                 │  ○ 3. faz                  │
│                                                 │  ○ Tamamlanacak            │
│                                                 │ İptal (#cancel, izin varsa)│
└─────────────────────────────────────────────────┴────────────────────────────┘
```

Dar ekranda tek sütun: önce sekmeler, sonra ilerleme ve iptal.

## Fazlar

| Faz | Kim | Dosyalar |
|-----|-----|----------|
| 0. Temel | ana oturum | `cleanings/show.blade.php` (sekme iskeleti, sağ sütun), bu doküman |
| A. Sekmeler | ajan A | `resources/js/modules/detail-tabs.js` (yeni), `theme/pages/_cleaning-detail.scss`, `tests/Feature/Screens/CleaningDetailTest.php` |
| B. İlerleme | ajan B | `cleanings/show/progress.blade.php` (yeni), `theme/pages/_cleaning-progress.scss` (yeni), `resources/scss/app.scss` (import), `theme/_tokens.scss` (gerekirse `--app-progress-*`), `tests/Feature/Screens/CleaningProgressTest.php` (yeni) |
| C. Entegrasyon | ana oturum | tam test takımı, build, README/notlar |

A ve B birbirinin dosyasına dokunmaz. Testler ayrı veritabanında koşar:
A `DB_DATABASE=temizlik_test_tabs`, B `DB_DATABASE=temizlik_test_progress`.

## Sözleşmeler

**Sekme iskeleti (faz 0, show.blade.php):**
- Sekme düğmeleri `#tab-{bölüm}`, panelleri `#pane-{bölüm}`; bölüm: `now`, `checklist`, `summary`,
  `materials`, `history`. Bölümlerin kendi id'leri (`#now`, `#checklist` …) değişmez.
- Sarmalayıcı `data-module="detail-tabs"` taşır. Bootstrap 5 tab işaretlemesi; sunucu "Şimdi"yi
  etkin getirir.

**Sekme davranışı (faz A):**
- Sayfa açılışında ve `hashchange`'te: adresteki çapa bir panelin içindeyse (`#materials`,
  `#step-12`, `#phase-3`, `#now` …) o panelin sekmesi açılır ve çapaya kaydırılır.
- Sayfadaki herhangi bir `a[href^="#"]` bağlantısı (ilerleme göstergesindeki adım bağlantıları,
  "Malzemelere git" vb.) hedefi bir paneldeyse önce sekmeyi açar, sonra hedefe kaydırır.
  Hedef panelde değilse (`#cancel`) tarayıcının varsayılan davranışı sürer.
- Sekme değişince adres `#{bölüm}` olur (`history.replaceState`); yenilemede aynı sekme açılır.
- Aksiyonlardan sonraki yönlendirme `#now` ile gelir (CleaningStepController) ve Şimdi sekmesini açar.
- Panel içindeki kartların başlığı görsel olarak gizlenir (sekme adı aynı bilgiyi verir), ekran
  okuyucuya açık kalır. Eski `:target` tabanlı sekme vurgusu kaldırılır.

**İlerleme göstergesi (faz B):**
- `@include('cleanings.show.progress')`; kullanılabilir değişkenler: `$cleaning` (fazlar ve
  adımlar yüklü, `procedurePhase`/`procedureStep` ile), `$current`, `$phaseRows`, `$stepRows`.
  Controller'a dokunmaz; ek sorgu yapmaz.
- Kök: `<section id="progress" class="card progress-tracker">`, başlık "İlerleme".
- Başlıkta toplam: "x / y adım tamamlandı" ve ince ilerleme çubuğu.
- Dikey zaman çizelgesi: başlangıç düğümü "Kayıt açıldı" (açılış zamanı) → her faz (büyük
  düğüm) ve altında adımları (küçük nokta) → bitiş düğümü (Tamamlandı / İptal edildi / Süresi
  doldu / henüz kapanmadıysa "Tamamlanacak").
- Durumlar ve renkleri (renkler yalnızca `_tokens.scss` üzerinden, durum rozetleriyle aynı aile):
  tamamlanan = ok (dolu, onay simgesi), devam eden = vurgu (halka, yavaş nabız; `prefers-reduced-motion`
  ile durur), duraklatılan = warn, gelecek = nötr çerçeve, kayıt kapandığı için yapılmayan = soluk +
  "Yapılmadı". Düğümleri bağlayan çizgi tamamlanan kısımda dolu, gelecek kısımda kesikli.
- Adım bağlantısı `#step-{id}` (Adımlar sekmesini açar); güncel adımda `aria-current="step"`,
  "Şu an" etiketi ve `#now` bağlantısı.
- Faz satırında: "a / b adım", tamamlanan fazda ölçülen süre, minimum altında kapanan fazda uyarı.
- Geniş ekranda sağ sütun yapışkan (sticky).
