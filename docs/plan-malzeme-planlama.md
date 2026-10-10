# Malzeme lotları, üretim iş emri, temizlik planı ve görev ataması

İstek (10 Ekim 2026). Dört konu birlikte ele alınır:

- **A. Malzeme:** Hangi malzemenin kullanılacağı prosedürden gelir, kayıtta yalnızca kullanılan lot
  seçilir. SKT lotun özelliğidir; ne kayıtta ne prosedürde elle girilir.
- **B. Üretim iş emri:** "İş emri" alanı üretim iş emridir; ad ve anlam netleşir.
- **C. Temizlik planı:** Planlı temizlik ya periyodik kuraldan ("her 7 günde") ya da tetikten ("makinedeki
  üretim iş emri tamamlanınca") doğar; sistem yapılması gereken temizlikleri görev olarak üretir.
- **D. Görev ataması (bekliyor):** Yönetici planlı temizliği kendisi açıp sorumlu olmaz; sorumlu
  operatörü ve yardımcı personeli seçerek görev verir. Plansız müdahaleyi sahadaki operatör açar ve
  sorumlusu odur.

**Durum (10 Ekim 2026):** Kullanıcı D'yi bekletti; A, B ve C yapılıyor. C'nin ürettiği görevler bu
aşamada **atanmamıştır**: "yapılması gereken temizlik" olarak listelenir, herhangi bir operatör görevden
kayıt açabilir ve kaydı açan sorumludur. D gelince görevlere sorumlu, yardımcılar ve yöneticinin elle
görev vermesi eklenir (`responsible_id`, `cleaning_task_helpers`, "Görev ver", "Görevlerim").
Açık kararlar 2–4 önerilen seçenekle uygulanır; karar 1 D ile birlikte verilecek.

## Temel fikir: görev ≠ kayıt

Yöneticinin verdiği iş bir **temizlik görevi**dir; **temizlik kaydı** ise görevin sorumlusu olan operatör
işe gittiğinde görevden açılır. Böylece:

- R-15 bozulmaz: kaydı açan (görevin sorumlusu) kaydın sorumlusudur, sorumluluk sonradan devredilmez.
  Görevin sorumlusu ise kayıt açılana kadar yönetici tarafından değiştirilebilir.
- R-20 ve K-06 bozulmaz: ileri tarihli iş bir kayıt değil görevdir; 30 dakikalık "başlatılmadı" süresi
  yalnızca açılmış kayda işler.
- Plansız müdahale bugünkü gibi kalır: operatör sahada açar, sorumlusu kendisidir.

```
Temizlik planı ──(periyodik / üretim emri tamamlandı)──► Temizlik görevi ──(sorumlu "Kaydı aç")──► Temizlik kaydı
                                         Yönetici elle ──►      │ sorumlu, yardımcılar, son tarih        │ sahibi = açan
                                                                └─ kayıt düşerse (iptal/süre) görev yeniden açık
```

## Kararlar (docs/is-gereksinimleri.md'ye işlenecek)

| No | Karar |
|----|-------|
| K-12 (değişir) | Prosedürde listelenen malzemeler kayıt formunda kendiliğinden gelir; operatör her biri için lot seçer. Ek malzeme eklenebilir. Zorunlu malzemelerin her biri için en az bir geçerli giriş olmadan ilk adım başlatılamaz. Yanlış giriş silinmez, gerekçeyle geçersiz kılınır (aynı). |
| K-13 (değişir) | Prosedür versiyonu beklenen malzemeleri listeler; her malzeme zorunlu ya da isteğe bağlıdır. Sürüm yayımlanınca liste değişmez (K-15). `material_required` alanı listeden türetilir. |
| K-14 (değişir) | SKT lotun özelliğidir. Lotlar yönetimde tanımlanır (gerçekte depo/ERP). Operatör yalnızca süresi geçmemiş ve kullanımdaki lotları seçebilir; kayıtta lot no ve SKT'nin o anki kopyası saklanır, lot sonradan düzeltilse de geçmiş değişmez. |
| K-19 (değişir) | Alan "Üretim iş emri"dir. İş emrinin durumu (planlandı, üretimde, tamamlandı) ve zamanları vardır; demoda yönetici değiştirir, gerçekte ERP'den gelir. Kayıt, temizliğin hazırladığı **sonraki** üretim iş emrine bağlanır (açık karar 3). |
| K-20 (yeni) | Temizlik planı: makine bazında periyodik kural (N günde bir) ya da "üretim iş emri tamamlanınca" tetiği; varsayılan sorumlu ve yardımcılar. Bir planın aynı anda tek açık görevi olur. |
| K-21 (yeni) | Temizlik görevi: makine, son tarih, kaynak (periyodik / üretim emri), ilgili üretim iş emri. Plan üretir. Görevden açılan kayıt planlı tiptedir ve üretim iş emri forma gelir. Bu aşamada görev atanmamıştır, herhangi bir aktif operatör kayıt açabilir; D ile sorumlu, yardımcılar ve elle görev eklenir. |
| K-22 (D ile) | Sorumluluk: görevden açılan kaydın sorumlusu açan operatördür (R-15). Plansız müdahaleyi sahadaki kişi açar ve sorumlusu odur. Yönetici planlı temizlik için kayıt açmaz, görev verir. |
| K-23 (yeni) | Görev durumu: açık → kayıt açıldı → tamamlandı; kayıt iptal edilir ya da süresi dolarsa görev yeniden açık olur. Son tarihi geçen açık görev "gecikti" görünür ve bildirim gider. |

## Veri modeli (yeni migration'lar; eski migration'lara dokunulmaz)

- `material_lots`: material_id, lot_no, expiry_date, received_at (null), is_active; unique(material_id, lot_no). Değişiklik günlüğüne girer.
- `procedure_version_materials`: procedure_version_id, material_id, sequence, is_required; unique(version, material). Yayımlanmış versiyonda model seviyesinde değiştirilemez.
- `cleaning_materials`: + material_lot_id (null, eski satırlar için); lot_no ve expiry_date kopya olarak kalır.
- `work_orders`: + status (planned / in_production / completed), planned_start_at, planned_end_at, completed_at, product (null).
- `cleaning_plans`: machine_id, kind (periodic / work_order_completed), interval_days (null), is_active, last_task_at. D ile: responsible_id, `cleaning_plan_helpers`.
- `cleaning_tasks`: machine_id, cleaning_plan_id, source (periodic / work_order), trigger_work_order_id (null), work_order_id (null, sonraki emir), due_at, status (open / in_record / done / cancelled), cleaning_id (null). D ile: responsible_id, created_by, notes, `cleaning_task_helpers`, source manual.
- `cleanings`: + cleaning_task_id (null).

## Kurallar (workflow)

- `CleaningWorkflow::open()` görev alır: görev açık olmalı, makine görevle aynı olmalı, tip planlı olur; üretim iş emri görevden gelir (formda değiştirilebilir). D ile: açan görevin sorumlusu olmalı, yardımcılar görevden gelir. Görev `in_record`, kayıt görevle bağlanır. Aynı anda görev başına tek kayıt: satır kilidi.
- `MaterialEntry` lot id taşır: lot malzemeye ait, kullanımda ve SKT'si geçmemiş olmalı (sunucu tarihi).
- İlk adım: zorunlu malzemelerin her biri için geçerli giriş şartı (R-09).
- Kayıt tamamlanınca görev `done`; iptal ya da süre dolumunda görev yeniden `open` (olaylar: CleaningCompleted, CleaningCancelled, CleaningExpired dinleyicileri).
- `cleaning:generate-tasks` (saatlik): periyodik planlarda zamanı gelen görevi üretir. Üretim iş emri `completed` olunca (domain olayı) tetikli planlar görev üretir.
- Geciken görevde yöneticilere bildirim (mevcut RabbitMQ zinciri). D ile: görev atanınca ve geciktiğinde sorumluya.

## Ekranlar

- **Yönetim > Malzemeler:** malzeme sayfasında Lotlar sekmesi: liste, ekle, kullanımdan kaldır; SKT'si geçenler işaretli.
- **Yönetim > Prosedür versiyonu (taslak):** beklenen malzemeler listesi (ekle, sırala, zorunlu/isteğe bağlı, kaldır).
- **Yönetim > Üretim iş emirleri:** ad değişir; durum ve planlanan zamanlar; "Tamamlandı" işaretleme (demo, ERP yerine).
- **Yönetim > Temizlik planları:** makine, kural, varsayılan sorumlu ve yardımcılar.
- **Gösterge paneli:** "Yapılması gereken temizlikler": açık görevler son tarih sırasıyla, gecikenler vurgulu, "Kaydı aç" düğmesi; yönetici için görev iptali.
- **D ile:** yöneticinin "Görev ver" formu (makine, son tarih, sorumlu, yardımcılar, üretim iş emri, not), operatörün "Görevlerim" listesi.
- **Yeni kayıt formu:**
  - Görevden gelince makine, tip (planlı), üretim iş emri dolu.
  - Malzemeler prosedürden gelir, her satırda lot seçimi (süresi geçmemiş lotlar), "Ek malzeme".
  - SKT girişi kalkar.
- **Kayıt detayı:** Malzemeler sekmesinde lot seçimi (malzemeye göre gruplu tek liste); Özet'te görev ve üretim iş emri.
- **Raporlar:** malzeme/lot izlenebilirliği lot kaydına bağlanır; etiketler "Üretim iş emri".

## Fazlar

| Faz | Kim | İş | Dosyalar |
|-----|-----|----|----------|
| 0. Temel | ana oturum | B (etiket değişikliği, bütün view'larda), migration'lar, model ve enum'lar, workflow değişiklikleri ve testleri, demo seed, sözleşmeler | `database/`, `app/Models`, `app/Enums`, `app/Services/Cleaning`, `app/Events`, `tests/Unit`, `tests/Feature/Cleaning` |
| 1A. Malzeme yönetimi | ajan | lot yönetimi, prosedür malzeme listesi editörü | `Admin/MaterialController`, `Admin/MaterialLot*`, `Admin/ProcedureVersion*`, `admin/materials/*`, `admin/procedures/versions/*`, ilgili testler |
| 1B. Kayıt malzemesi | ajan | kayıt formu malzeme satırları ve lot seçimi, detay Malzemeler sekmesi, malzeme raporu | `cleanings/form/material*`, `cleanings/show/materials`, `CleaningMaterialController`, `StoreCleaningRequest` (malzeme kısmı), `reports/materials`, `cleaning-form.js` (malzeme kısmı), ilgili testler |
| 1C. Plan ve üretim emri | ajan | temizlik planları yönetimi, üretim iş emri durumları, görev üretimi (komut + dinleyici), bildirimler | `Admin/WorkOrderController`, `Admin/CleaningPlan*`, `admin/work-orders/*`, `admin/cleaning-plans/*`, `app/Console/Commands/GenerateCleaningTasks`, `app/Listeners/*Task*`, ilgili testler |
| 1D. Görevler | ajan | "Yapılması gereken temizlikler" listesi, görev iptali, görevden kayıt açma akışı | `CleaningTaskController`, `views/tasks/*`, `dashboard*`, `CleaningController::create/store` (görev kısmı), `cleanings/form/type`, `form/details`, ilgili testler |
| 2. Entegrasyon | ana oturum | çakışmalar, tam test takımı, README, gereksinim dokümanı, notlarım.md | — |

Ortak dosya çakışması: `StoreCleaningRequest`, `CleaningController` ve `cleaning-form.js`'e hem 1B hem 1D dokunur. Faz 0 bu dosyaları bölümlere ayırır (malzeme / görev); her ajan yalnızca kendi bölümünü değiştirir.

Her ajan ayrı test veritabanında koşar: `temizlik_test_1a` … `temizlik_test_1d`.

## Açık kararlar

1. Görev modeli mi, yoksa yöneticinin kaydı açıp sorumlu seçmesi mi (R-15'i esneterek)? Öneri: görev modeli.
2. Operatör görev olmadan planlı kayıt açabilsin mi? Öneri: evet (bugünkü gibi; plan dışı rutin temizlik).
3. Kayıt hangi üretim iş emrine bağlanır: sonraki mi, tamamlanan mı? Öneri: sonraki; tamamlanan emir görevde "tetikleyen" olarak durur.
4. Testler nerede koşulsun: bu ortamda stack yalnızca test için açılıp iş bitince kapatılsın mı, yoksa kullanıcının Mac'inde mi?

Ön koşul: `feature/prosedur-sekmeler` önce `main`'e merge edilir (prosedür ekranlarına iki iş birden dokunur).

## Faz 0 sonucu ve ajan sözleşmeleri (10 Ekim 2026)

Faz 0 hazır (`feature/malzeme-planlama`):
- Migration'lar: `2026_10_10_000001`…`000004`.
- Enum'lar: `WorkOrderStatus`, `CleaningPlanKind`, `CleaningTaskSource`, `CleaningTaskStatus`.
- Modeller: `MaterialLot`, `ProcedureVersionMaterial`, `CleaningPlan`, `CleaningTask`, ilişkiler, değişiklik günlüğü türleri.
- Workflow: lot seçimi, zorunlu malzeme listesi, görevden kayıt açma, görevin tamamlanması ve yeniden açılması.
- İstekler: `materials[i][material_lot_id]`, `material_lot_id`, `cleaning_task_id`.
- `CleaningController::create()` verisi: `$task`, `$lotsByMaterial`, makinede `currentVersion.materials.material`.
- Rozet renkleri: `planned`, `in-production`, `open`, `in-record`, `done`, `overdue`.
- Demo seed: lotlar, prosedür malzemeleri, üretim iş emri durumları, 5 plan, 4 görev.
- B (etiket değişikliği) yapıldı.

Workflow testleri (`tests/Feature/Cleaning`, 248 test) geçiyor. Bilinen kırıklar, ilgili ajana aittir:
`Screens/Actions/MaterialActionsTest`, `Screens/CleaningCreateTest` (eski lot/SKT alanları) → 1B;
`Admin/Catalog/MaterialManagementTest` (iki kayıt testi) → 1A.

**Ortak kurallar:** Yalnızca kendi dosyalarına yaz. Commit atma. Testleri yerel PHP ile kendi
veritabanında koş: `t.sh temizlik_test_1x <yollar>` (scratchpad'deki yardımcı; MySQL 127.0.0.1:33060).
Renk yazma, token kullan. Türkçe arayüz metni ve dosya başı yorum üslubunu izle.

### 1A. Malzeme yönetimi
- **Lotlar:** Yönetim > Malzemeler'de malzeme sayfasında lotlar (liste, ekle, düzenle, kullanımdan kaldır/geri al; SKT'si geçenler işaretli). Lot kaydı kullanıldıysa lot no ve malzemesi kilitlenir, SKT düzeltilebilir (kayıtlar kopya taşır).
- **Prosedür versiyon taslağı:** Beklenen malzemeler editörü (ekle, sırala, zorunlu/isteğe bağlı, kaldır).
  - `ProcedureVersioning`'e malzeme yöntemleri eklenir, `material_required` listeden türetilir (zorunlu biri varsa true).
  - `createDraft` listeyi kopyalar.
  - Ayarlar formundaki elle "malzeme zorunlu" kutusu kalkar (eski versiyonlar için değer korunur).
- **Testler:** `MaterialManagementTest` (iki kırık test dahil), prosedür taslak/değişmezlik testleri.
- **Dosyalar:**
  - `Admin/MaterialController`, yeni `Admin/MaterialLotController`, `Requests/Admin/Catalog/*Material*`;
  - `admin/materials/*`;
  - `Services/Definitions/ProcedureVersioning`, `Admin/ProcedureVersion*Controller`, `Requests/Admin/Procedures/*`, `admin/procedures/versions/*`;
  - `routes/web/admin-*.php` (yalnızca malzeme/prosedür satırları);
  - `tests/Feature/Admin/Catalog/MaterialManagementTest`, `tests/Feature/Admin/Procedures/*`.

### 1B. Kayıt malzemesi
- **Yeni kayıt formu:**
  - Makine seçilince versiyonun beklediği malzemeler satır olarak gelir (zorunlu/isteğe bağlı etiketiyle). Her satırda o malzemenin lotları seçilir: "LOT · SKT gg.aa.yyyy".
  - "Ek malzeme" satırında malzeme ve lot seçilir.
  - SKT ve lot no girişi kalkar. Gönderilen alanlar: `materials[i][material_id]`, `materials[i][material_lot_id]`.
- **Kayıt detayı Malzemeler sekmesi:**
  - Ekleme formu tek liste: malzemeye göre `optgroup`, seçenek "LOT · SKT". Gönderilen alan: `material_lot_id`.
  - Zorunlu malzemelerden eksik olanlar uyarıda listelenir.
  - "Şimdi" kartındaki malzeme uyarısı da eksikleri adıyla söyler.
- **Malzeme/lot izlenebilirlik raporu:** Lot kaydına göre filtre ve gösterim; eski satırlar kopya alanlarla.
- **Dosyalar:**
  - `cleanings/form/material*`, `cleanings/form/machine-summary`, `resources/js/modules/cleaning-form.js`;
  - `cleanings/show/materials`, `cleanings/show/now`;
  - `CleaningDetailController` (yalnızca malzeme verisi), `CleaningMaterialController`;
  - `Services/Reports/MaterialTrace`, `reports/materials`;
  - `tests/Feature/Screens/Actions/MaterialActionsTest`, `tests/Feature/Screens/CleaningCreateTest` (malzeme testleri), `tests/Feature/Reports/MaterialTraceTest`.

### 1C. Plan ve üretim iş emri
- **Üretim iş emirleri:**
  - Liste ve formda ürün, durum, planlanan başlangıç/bitiş.
  - "Üretime al" ve "Tamamlandı" düğmeleri (demo; ERP yerine).
  - Tamamlanma bir servis yöntemiyle yapılır (ör. `WorkOrderLifecycle::complete`) ve `WorkOrderCompleted` olayını yayar.
- **Yönetim > Temizlik planları:** Liste, ekle, düzenle, kullanımdan kaldır/geri al. Alanlar: makine, kural (periyodik / üretim iş emri tamamlanınca), aralık (gün; periyodikte zorunlu). Menüde görünür.
- **Görev üretimi:**
  - `cleaning:generate-tasks` saatlik çalışır. Periyodik planda etkin görev yoksa ve `last_task_at + interval_days <= şimdi` ise (hiç görev yoksa hemen) `CleaningTask::openFor`. Yarışta unique ihlali "zaten var" sayılır.
  - `WorkOrderCompleted` dinleyicisi: makinedeki (ya da iş emrinin hattındaki makinelerdeki) etkin `work_order_completed` planları için görev açar. Tetikleyen emir = tamamlanan; sonraki emir = aynı makinenin en erken planlanmış emri.
- **Bildirim:** Gecikmiş görevler için yöneticilere bildirim (mevcut bildirim zinciri; aynı görev için bir kez).
- **Dosyalar:**
  - `Admin/WorkOrderController`, `Requests/Admin/Catalog/SaveWorkOrderRequest`, `admin/work-orders/*`;
  - yeni `Admin/CleaningPlanController`, `admin/cleaning-plans/*`, `config/menu.php` (yalnızca yeni satır);
  - `app/Services/Planning/*`, `app/Console/Commands/GenerateCleaningTasks`, `routes/console.php` (yalnızca zamanlama satırı);
  - `app/Events/WorkOrderCompleted`, `app/Listeners/*Task*`, `app/Notifications/*Task*`;
  - `routes/web/admin-*.php` (yalnızca iş emri/plan satırları);
  - `tests/Feature/Admin/Catalog/WorkOrderManagementTest`, yeni `tests/Feature/Planning/*`.

### 1D. Yapılması gereken temizlikler
- **Gösterge paneli:**
  - "Yapılması gereken temizlikler" kartı: açık görevler son tarih sırasıyla. Gösterilenler: makine, kaynak, tetikleyen ve sonraki üretim iş emri, son tarih, gecikti rozeti (`overdue`).
  - "Kaydı aç" düğmesi `cleanings.create?task=ID`'ye gider.
  - Yöneticiye "Görevi iptal et" (gerekçeli; görev `cancelled`, planın etkin görevi boşalır).
- **Yeni kayıt formu:**
  - `$task` varsa makine seçili ve kilitli, tip planlı ve kilitli, üretim iş emri görevden gelir.
  - Gizli `cleaning_task_id` alanı gönderilir; formun üstünde görev özeti gösterilir.
- **Kayıt detayı:** Özet sekmesinde "Görev" satırı (kaynak, son tarih). Olay geçmişinde "görevden açıldı" ayrıntısı.
- **Dosyalar:**
  - `dashboard.blade.php`, `dashboard/*`, `DashboardController`;
  - yeni `CleaningTaskController` (iptal) ve rotası;
  - `cleanings/create.blade.php`, `cleanings/form/type`, `cleanings/form/machine`, `cleanings/form/details`;
  - `cleanings/show/summary`, `Services/Cleaning/CleaningEventDescriber` (yalnızca `cleaning.opened`);
  - `tests/Feature/DashboardTest`, yeni `tests/Feature/Screens/CleaningTaskScreensTest`.

**Paylaşılan dosyalar:**
- `cleanings/form/machine.blade.php`: 1D'ye aittir; 1B'nin malzeme satırları `form/material*` ve `machine-summary` üzerinden gelir.
- `routes/web/*`: Her ajan yalnızca kendi satırlarını ekler.

## Faz 2 sonucu: entegrasyon

- **Ajan dosyaları:** Dört ajanın işi birleştirildi.
- **Ajanların bıraktığı işler:**
  - görev iptal alanları modele bağlandı;
  - görev yüklemesi `CleaningDetailController`'a taşındı;
  - malzeme doğrulama hataları `#materials` sekmesine dönüyor;
  - lot alanlarının Türkçe adları eklendi;
  - eski lot/SKT alanlarını kullanan iki detay testi ve değişiklik günlüğü testi güncellendi.
- **Doğrulama:** Tam test takımı 830/830 geçiyor. Production asset build'i hatasız.
- **Açık kalanlar:**
  - D (K-22 görev ataması) bekliyor.
  - Yeni ekranların sınıflarına özel stil yazılmadı.
  - Malzeme raporunda ayrı lot filtresi yok.

## Düzeltme: görevin vakti (K-24)

Kullanıcı geri bildirimi: üretim iş emri tamamlanınca açılan görev, açıldığı an "gecikti" görünüyordu. Neden: görev ancak vakti gelince açılıyordu ve son tarih vakitle aynıydı.

- **Model:** `cleaning_tasks.scheduled_at` (müdahale vakti; tetik bekleyen görevde NULL) ve `cleaning_plans.tolerance_hours` (varsayılan 4). `due_at` = vakit + tolerans; vakit yoksa NULL.
- **Durum:** yeni durum eklenmedi. Açık görev vakti gelmediyse "ileride" (`isUpcoming`), geldiyse kayıt açılabilir (`isDue`), son tarih geçtiyse gecikmiş (`isOverdue`).
- **Üretim (`CleaningTaskGenerator::generate`):**
  - Periyodik planda sıradaki görev önceki görev kapanınca açılır. Vakti son görevin vaktinden bir aralık sonrasıdır; geride kalan vakitler atlanır.
  - Tetikli planda görev, makinede bekleyen emir (üretimdeki önce) için vakitsiz açılır. `forCompletedWorkOrder` bekleyen görevin vaktini tamamlanma anına çeker.
- **Tetikleyiciler:**
  - Dakikalık `cleaning:generate-tasks`.
  - Plan ekle, güncelle ve yeniden kullanıma al.
  - Üretim iş emri ekle ve güncelle.
  - Görev iptali.
- **Kilit:** Toplu üretim `cleaning-tasks:generate` önbellek kilidiyle sıraya girer. Demo verisi yüklenirken kilidi DemoSeeder tutar ve en sonda görevi olmayan planları kendisi üretir.
- **Kural:** `CleaningWorkflow::open` vakti gelmemiş görevden kaydı `task_not_due` ile reddeder.
- **Ekranlar:**
  - Gösterge paneli "Vakti gelenler" ve "İleride yapılacak" grupları.
  - Kayıt formunda vakti gelmemiş görev için uyarı.
  - Plan listesinde "İleride" ve vakit.
  - Plan formunda gecikme toleransı.
