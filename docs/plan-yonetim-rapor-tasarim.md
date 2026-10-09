# Uygulama Planı — Tanım Yönetimi, Raporlar, Kuyruk, Tasarım, İyileştirmeler

Kapsam, "neler kaldı" listesindeki beş madde:

1. **Tanım yönetimi (yönetici):** tesis/hat/makine, prosedür ve versiyonlar, malzeme, iş emri, kullanıcı.
2. **Raporlar (yönetici):** süre ve efor, sapmalar, malzeme izlenebilirliği, kayıt bazında denetim raporu (yazdırma ve PDF).
3. **RabbitMQ'nun gerçek kullanımı:** domain olaylarından bildirimler ve rapor dışa aktarımı kuyrukta çalışır.
4. **Tasarım:** kullanıcının verdiği "Nocturne" tasarımının görsel dili, açık ve koyu tema.
5. **Teknik iyileştirmeler:** olay zinciri kontrol noktaları, production compose, sorgu iyileştirmeleri.

İş kuralları `docs/is-gereksinimleri.md` (R-xx, K-xx). Önceki planlar: `docs/plan.md`, `docs/plan-arayuz.md`, `docs/plan-ekranlar.md`.

## Tasarım kaynağı

Görsel dil, kullanıcının verdiği "Nocturne" tasarımından alındı. Tasarım başka bir projeye ait olduğu için kaynak dosya repoya konmadı; entegrasyonda kaldırıldı. Yalnızca görsel dil alındı, içerik alınmadı:
- **Token'lar:** `--color-*`, `--shadow-*`, `--radius-*`, `--space-*`, ok/warn/crit.
- **Yazı:** Inter yazı tipi.
- **Bileşenler:** kenarları solan ayırıcı, kart (kicker, başlık, meta), KPI, etiketler, butonlar, segment kontrol, sekmeler, tablo.
- **Kabuk:** açık sidebar, alt kısımda kullanıcı bloğu, üst barda breadcrumb, tema düğmesi ve bildirim zili.

Bunlar `resources/scss/theme/` altındadır.

## Fazlar

| Faz | İş | Kim | Paralel mi? |
|---|---|---|---|
| 0 | dompdf paketi, bildirim tablosu, route dosyalarının ayrılması, menü, layout'a `page-subtitle`/`page-actions` alanları, bildirim zili yer tutucusu, tasarım artboard'larının çıkarılması | Tamamlandı (ana oturum) | — (ortak sözleşme) |
| 1A | Yönetim: tesis, hat, makine (makineyi kullanımdan kaldırma, K-16) | Tamamlandı (Ajan A) | Evet |
| 1B | Yönetim: prosedür, versiyon taslağı ve yayımlama, faz ve adım tanımları, adım medyası (K-15, R-05) | Tamamlandı (Ajan B) | Evet |
| 1C | Yönetim: malzeme kataloğu, iş emri, kullanıcı (pasife alma, R-36) | Tamamlandı (Ajan C) | Evet |
| 1D | Raporlar: süre ve efor, sapmalar, malzeme izlenebilirliği, denetim raporu (yazdırma ve PDF), kuyrukta dışa aktarma | Tamamlandı (Ajan D) | Evet |
| 1E | Domain olayları ve bildirimler: RabbitMQ kuyruğunda dinleyiciler, bildirim zili | Tamamlandı (Ajan E) | Evet |
| 1F | Tasarım: Nocturne tema katmanı, açık/koyu tema düğmesi, kabuk ve mevcut ekranların görünümü | Tamamlandı (Ajan F) | Evet |
| 1G | Olay zinciri kontrol noktaları ve doğrulama komutu, production compose | Tamamlandı (Ajan G) | Evet |
| 1H | Tanım değişiklik günlüğü: yönetimdeki her değişikliğin kim/ne zaman/ne değişti kaydı (değerlendirme sonrası eklendi; A, B, C bittikten sonra) | Tamamlandı (Ajan H) | Evet |
| 2 | Entegrasyon: bütün testler, derleme, tarayıcıda açık ve koyu temada uçtan uca kontrol, kalan küçük iyileştirmeler, dokümanlar | Tamamlandı (ana oturum) | Hayır |

## Sözleşmeler (Faz 0'da kurulur)

### Route dosyaları

Her ajan yalnızca kendi route dosyasına yazar. `routes/web.php` bunları aşağıdaki gruplarla yükler.

| Dosya | Grup | Sahip |
|---|---|---|
| `routes/web/admin-locations.php` | `auth`, `can:manage-definitions`, prefix `admin`, ad `admin.` | A |
| `routes/web/admin-procedures.php` | aynı | B |
| `routes/web/admin-catalog.php` | aynı | C |
| `routes/web/reports.php` | `auth`, `can:view-reports`, prefix `reports`, ad `reports.` | D |
| `routes/web/notifications.php` | `auth`, prefix `notifications`, ad `notifications.` | E |

### Menü (`config/menu.php`)

Menüde, route'u henüz tanımlanmamış öğe gösterilmez. Bu sayede ajanlar bitene kadar uygulama bozulmaz. Route adları sözleşmedir:

| Başlık | Öğe | Route | Sahip |
|---|---|---|---|
| Yönetim | Tesis ve Hatlar | `admin.facilities.index` | A |
| Yönetim | Makineler | `admin.machines.index` | A |
| Yönetim | Prosedürler | `admin.procedures.index` | B |
| Yönetim | Malzemeler | `admin.materials.index` | C |
| Yönetim | İş Emirleri | `admin.work-orders.index` | C |
| Yönetim | Kullanıcılar | `admin.users.index` | C |
| Raporlar | Süre ve Efor | `reports.durations` | D |
| Raporlar | Sapmalar | `reports.deviations` | D |
| Raporlar | Malzeme İzlenebilirliği | `reports.materials` | D |

### Layout

`layouts.app` şu bölümleri sunar:
- `title`, `page-title`, `content` (mevcut);
- `page-subtitle` (başlığın altındaki kısa satır, isteğe bağlı);
- `page-actions` (başlığın sağındaki butonlar, isteğe bağlı).

Yeni ekranlar yalnızca standart Bootstrap 5 / AdminLTE yapısını kullanır: `card`, `table`, `form-control`, `form-select`, `btn btn-primary` / `btn-outline-secondary`, `badge`, `nav-tabs`, `alert`. Inline stil ve renk sınıfı yazılmaz. Görünüm Ajan F'nin tema katmanından gelir.

Mevcut bileşenler kullanılır: `x-status-badge`, `x-datetime`, `x-duration`.

### Bildirimler

- Laravel database notifications. `notifications` tablosu Faz 0'da eklenir.
- `data` biçimi: `['title' => string, 'message' => string, 'url' => ?string, 'level' => 'info'|'warning'|'danger']`.
- Zil bileşeni `<x-notification-bell />` (sahibi E) üst bar partial'ında yer alır (Faz 0'da yer tutucu). Ajan F üst barı yeniden düzenlerken bu satırı korur.
- Ajan D, dışa aktarma hazır olunca aynı biçimde bildirim gönderir.

### Kuyruk

- Geliştirme ortamında `QUEUE_CONNECTION=rabbitmq`, testlerde `sync`.
- Kuyruğa giden işler `ShouldQueue` olur ve transaction commit'inden sonra gönderilir (`afterCommit`).

### Paketler ve derleme

- Composer paketleri yalnızca Faz 0'da eklenir (dompdf). Ajanlar `composer.json` dosyasına dokunmaz.
- `npm run build` yalnızca Ajan F çalıştırır; eşzamanlı derlemeler `public/build` dosyasını bozar. Diğer ajanlar JS'i `node --check` ile denetler.

## Dosya sahipliği

| Ajan | Dosyalar |
|---|---|
| A | `routes/web/admin-locations.php`, `app/Http/Controllers/Admin/{Facility,Line,Machine}Controller.php`, `app/Http/Requests/Admin/Locations/*`, `app/Services/Definitions/MachineRetirement.php` (veya benzeri), `resources/views/admin/locations/*`, `resources/views/admin/machines/*`, `tests/Feature/Admin/Locations/*` |
| B | `routes/web/admin-procedures.php`, `app/Http/Controllers/Admin/Procedure*Controller.php`, `app/Http/Requests/Admin/Procedures/*`, `app/Services/Definitions/ProcedureVersioning.php` (veya benzeri), `app/Models/{Procedure,ProcedureVersion,ProcedurePhase,ProcedureStep}.php`, `resources/views/admin/procedures/*`, `resources/js/modules/procedure-*.js`, `tests/Feature/Admin/Procedures/*`; gerekirse versiyon yayımlama yardımcıları `database/seeders/DemoSeeder.php::publishVersion` ve `tests/Feature/Cleaning/Concerns/BuildsCleaningFixtures.php::publishVersion` |
| C | `routes/web/admin-catalog.php`, `app/Http/Controllers/Admin/{Material,WorkOrder,User}Controller.php`, `app/Http/Requests/Admin/Catalog/*`, `app/Models/{Material,WorkOrder}.php`, yeni migration'lar (malzemeye `is_active`), `resources/views/admin/{materials,work-orders,users}/*`, `app/Http/Controllers/CleaningController.php` (yalnızca iş emri ilişkilerini kullanmak için), `tests/Feature/Admin/Catalog/*` |
| D | `routes/web/reports.php`, `app/Http/Controllers/Reports/*`, `app/Services/Reports/*`, `app/Jobs/Reports/*`, `app/Notifications/Reports/*`, `resources/views/reports/*`, `tests/Feature/Reports/*` |
| E | `routes/web/notifications.php`, `app/Events/*`, `app/Listeners/*`, `app/Notifications/Cleaning/*`, `app/Http/Controllers/NotificationController.php`, `resources/views/components/notification-bell.blade.php`, `resources/views/notifications/*`, `app/Services/Cleaning/CleaningWorkflow.php` (yalnızca olay yayımlamak için), `tests/Feature/Notifications/*` |
| F | `resources/scss/**`, `resources/js/app.js`, `resources/js/modules/theme-*.js`, `vite.config.js` (yazı tipi), `resources/views/layouts/**`, `resources/views/components/{status-badge,datetime,duration,sidebar-menu}.blade.php`, `app/View/Components/SidebarMenu.php`, `resources/views/auth/login.blade.php`, `resources/views/dashboard*`, `resources/views/cleanings/**` (yalnızca görünüm ve işaretleme), `tests/Feature/Ui/*` |
| G | `app/Console/Commands/{CheckpointEventChains,VerifyEventChains}.php` (veya benzeri), `app/Models/AuditCheckpoint.php`, yeni migration'lar, `app/Services/Cleaning/AuditCheckpoints.php` (veya benzeri), `routes/console.php`, `config/logging.php`, `compose.prod.yaml`, `docker/php/Dockerfile` (geliştirme davranışı korunarak), `docker/nginx/*` (production için ek dosya), `tests/Feature/Audit/*` |

## Ortak kurallar

- Faz 0 dosyaları ve başka ajanın dosyaları salt okunurdur; değişiklik gerekiyorsa raporlanır.
- Her ajanın kendi test veritabanı vardır: `temizlik_test_a` … `temizlik_test_g`.
- Commit yapılmaz.
- Kod stili mevcut kodla aynıdır: Laravel 13, Türkçe arayüz metinleri, az ve Türkçe yorum, ilgili yerlerde R-xx/K-xx referansı.

## Sonuç

- **Testler:** Bütün paket geçiyor: 733 test, 4446 doğrulama. Pint temiz, `npm run build` uyarısız.
- **Production:** Ayrı bir proje adıyla derlenip çalıştırıldı (Ajan G). Giriş sayfası, derlenmiş asset'ler, kuyruk ve zamanlayıcı çalıştı, ardından kaldırıldı.
- **Uçtan uca deneme (başsız Chrome):**
  - Yönetici ekranları, raporlar, denetim raporu ve değişiklik günlüğü açıldı.
  - Koyu tema seçimi yenilemede korundu.
  - Operatör bir kayıt açıp fazı minimum süre altında gerekçeyle kapattı. Olay RabbitMQ kuyruğundan geçti ve yöneticinin zilinde bildirim olarak göründü.
  - Konsolda hata yok.

### Entegrasyonda yapılanlar

- **Değerlendirme sonrası eklenen.** Tanım değişiklik günlüğü (1H).
- **Tasarım dosyası.** Kaynak dosya ve ondan çıkarılan artboard'lar repodan kaldırıldı; içerik başka bir projeye aitti.
- **Kayıt numarası yılı.** Yıl yerel takvime (Europe/Istanbul) göre hesaplanıyor. Önceden 1 Ocak 00:00–03:00 arasında açılan kayıt eski yılın numarasını alıyordu.
- **Production'da demo verisi.** Seeder kullanıcıları factory'siz oluşturuyor; production imajında Faker yok (Ajan G'nin bulgusu).
- **Eşzamanlılık.** `CleaningWorkflow::open()` makine satırını kilitliyor; makineyi kullanımdan kaldırmayla aynı anda kayıt açılamıyor (Ajan A'nın bulgusu, Ajan E uyguladı).
- **Eski testler.** Yayımlanmış versiyonu doğrudan değiştiren üç eski test, yeni K-15 korumasına göre düzeltildi (Ajan B'nin yaması).
- **Ortak olay açıklamaları.** Olay açıklamaları detay ekranı ile denetim raporu arasında tek sınıfta (`CleaningEventDescriber`).
- **Kayıt detayı.** Yöneticiye "Denetim raporu" bağlantısı ve "PDF hazırla" düğmesi eklendi.
- **Sorgu iyileştirmeleri.** Kayıt açma formunda geçerli versiyon eager load ile geliyor (`currentPublishedVersion`). `pendingOn` birden fazla makine alıyor. İş emri `machine`/`line` ilişkileri kullanılıyor (Ajan C).
- **Geliştirme ortamı.** Dosya yükleme sınırı 20 MB (`docker/php/dev.ini`, nginx `client_max_body_size`).
- **Denetim log'u.** Testler ayrı bir denetim log dosyası kullanıyor. Seeder, sıfırlanan veritabanıyla eşleşmeyecek eski kontrol noktası log'unu arşive alıyor.
- **Tema.** Değişiklik günlüğü ekranının tema kuralları eklendi; derleme çıktısındaki Sass süre bilgi notu kapatıldı.
