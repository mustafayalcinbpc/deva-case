# Uygulama Planı — Kayıt Açma ve Adım Ekranları

Kapsam:
- **Kayıt açma:** Temizlik kaydını açan form.
- **Kayıt listesi:** Bütün kayıtlar, filtreli.
- **Kayıt detayı:** Sahada adım adım ilerlenen asıl ekran.
- **Uç noktalar:** Ekrandaki her aksiyonun (başlat, duraklat, devam et, tamamla, görevli değiştir, malzeme, iptal) sunucu tarafı.

Tanım yönetimi (yönetici) ve raporlar bu planın dışındadır.

İş kuralları `docs/is-gereksinimleri.md` (R-xx, K-xx), durum makinesi `app/Services/Cleaning/CleaningWorkflow.php`. **Kuralların tek sahibi workflow'dur:** ekranlar kural tekrarlamaz, yalnızca hangi butonun gösterileceğine karar verir. Asıl kontrol her zaman workflow'da yapılır.

Tasarım ilkesi `docs/plan-arayuz.md` ile aynıdır: standart AdminLTE/Bootstrap yapısı, anlamlı sınıf adları, inline stil yok. Renk ve dekorasyon yok; tema katmanı sonra baştan yazılacak.

**Saha kullanımı önceliklidir (R-21–R-25):** detay ekranında operatör ne yapması gerektiğini ilk bakışta görür. Güncel adım ve onun büyük aksiyon butonları en üsttedir. Ekran tablet ve telefonda da kullanılabilir olmalıdır.

## Fazlar

| Faz | İş | Kim | Paralel mi? |
|---|---|---|---|
| 0 | Yetki kurallarının ortak sınıfa çıkarılması (`CleaningPermissions`), kural ihlallerinin ekrana dönüşü, route'lar, menü, JS modül yükleyici, sayfa SCSS dosyaları, Türkçe doğrulama mesajları, Bootstrap sayfalama | Tamamlandı (ana oturum) | — (ortak sözleşme) |
| 1A | Kayıt açma formu + kayıt listesi | Tamamlandı (Ajan A) | Evet |
| 1B | Kayıt detayı: özet, güncel adım, kontrol listesi, malzemeler, olay geçmişi ve bütün aksiyon formlarının görünümü | Tamamlandı (Ajan B) | Evet |
| 1C | Aksiyon uç noktaları: adım, malzeme ve iptal controller'ları, form doğrulamaları, HTTP testleri | Tamamlandı (Ajan C) | Evet |
| 2 | Entegrasyon: testler, tarayıcıda uçtan uca akış, tema kuralları, dokümanlar | Tamamlandı | Hayır |

## Sözleşmeler (Faz 0'da kurulur)

### Route'lar (`routes/web.php`, hepsi `auth`)

| Ad | Yöntem ve yol | Controller | Alanlar |
|---|---|---|---|
| `cleanings.index` | GET `/cleanings` | `CleaningController@index` (A) | sorgu: `status`, `machine_id`, `mine` |
| `cleanings.create` | GET `/cleanings/create` | `CleaningController@create` (A) | |
| `cleanings.store` | POST `/cleanings` | `CleaningController@store` (A) | `machine_id`, `type`, `helper_ids[]`, `work_order_id`, `notes`, `materials[i][material_id\|lot_no\|expiry_date]` |
| `cleanings.show` | GET `/cleanings/{cleaning}` | `CleaningDetailController@show` (B) | |
| `cleanings.steps.start` | POST `/cleanings/{cleaning}/steps/{step}/start` | `CleaningStepController@start` (C) | |
| `cleanings.steps.pause` | POST `.../pause` | `CleaningStepController@pause` (C) | |
| `cleanings.steps.resume` | POST `.../resume` | `CleaningStepController@resume` (C) | |
| `cleanings.steps.complete` | POST `.../complete` | `CleaningStepController@complete` (C) | `deviation_reason` (isteğe bağlı) |
| `cleanings.steps.workers` | PUT `/cleanings/{cleaning}/steps/{step}/workers` | `CleaningStepController@workers` (C) | `user_ids[]` |
| `cleanings.materials.store` | POST `/cleanings/{cleaning}/materials` | `CleaningMaterialController@store` (C) | `material_id`, `lot_no`, `expiry_date` (Y-m-d) |
| `cleanings.materials.void` | POST `/cleanings/{cleaning}/materials/{material}/void` | `CleaningMaterialController@void` (C) | `void_reason` |
| `cleanings.cancel` | POST `/cleanings/{cleaning}/cancel` | `CleaningCancellationController@store` (C) | `cancel_reason` (CancelReason değeri), `cancel_note` |

- `{step}` ve `{material}` kayda bağlıdır (scoped binding): başka kaydın adımı 404 verir.
- Aynı sayfada birden fazla form olduğu için alan adları benzersizdir (`void_reason`, `cancel_reason`, `cancel_note`).

### Aksiyon sonrası davranış

- **Başarı:** `cleanings.show` sayfasına yönlendirilir ve `status` flash mesajı gösterilir (Türkçe, ör. "2. adım başlatıldı."). Adım aksiyonlarında URL'ye `#now` eklenir: operatör bir sonraki işlemi aynı karttan yapar (entegrasyonda `#step-{id}` yerine seçildi).
- **İş kuralı ihlali (`CleaningRuleViolation`):** `bootstrap/app.php` içinde merkezi olarak ele alınır (Faz 0). Web isteğinde önceki sayfaya dönülür, mesaj `workflow` hata anahtarıyla gösterilir (flash partial). Ayrıca `session('violation')` = `['rule' => ..., 'context' => [...]]` yazılır.
  - JSON isteğinde 422 döner: `{rule, message, context}`.
  - **Minimum süre altı faz (K-01):** `violation.rule === 'below_minimum_duration'` ve `context.phase_id` o fazdır. Detay ekranı bu durumda o fazın çalışan adımında gerekçe alanını açık gösterir.
- **Form doğrulama hatası:** Laravel'in standart davranışı geçerlidir (önceki sayfa + `$errors`). Mesajlar Türkçedir (`lang/tr`).

### Yetki: `App\Services\Cleaning\CleaningPermissions`

Workflow ile arayüzün ortak kullandığı kurallar (Faz 0'da workflow'dan çıkarıldı):
- `canOperateStep(User, Cleaning, CleaningStep): bool`: kaydın sahibi ya da adımın aktif görevlisi (R-44, K-10, K-11).
- `canManageMaterials(User, Cleaning): bool`: sahibi ya da herhangi bir adımın aktif görevlisi.
- `allowedCancelReasons(User, Cleaning): list<CancelReason>`:
  - Yönetici: kayıt açıksa bütün gerekçeler.
  - Sahibi: kayıt başlamamışsa yalnızca `InvalidRecord`.
  - Diğer durumlarda boş liste.

Kullanıcının aktifliği ayrıca kontrol edilir. `canOperateStep` ve `canManageMaterials` kaydın açık olup olmadığına bakmaz; `allowedCancelReasons` kapalı kayıt için boş liste döner.

### JS modülleri

`resources/js/app.js`, sayfadaki `data-module="ad"` taşıyan her elemanı bulur ve `resources/js/modules/ad.js` dosyasının varsayılan export'unu o elemanla çağırır: `export default function (element) {...}`. jQuery yok; düz JS. JS olmadan da formlar çalışmalıdır (JS yalnızca kolaylık katar).

### Sayfa stilleri

Her ajan kendi SCSS dosyasına yalnızca yerleşim (layout) kuralı yazabilir: `resources/scss/theme/pages/_cleaning-form.scss` (A), `_cleaning-detail.scss` (B). Renk yazılmaz. Renk gerekiyorsa `_tokens.scss`'teki `--app-*` değişkenleri kullanılır; yeni bir değişken gerekiyorsa raporlanır.

### Ortak kullanılanlar

Bunlar zaten var ve kullanılır:
- `x-status-badge`, `x-datetime`, `x-duration` bileşenleri,
- `layouts.app`,
- `Cleaning::netSeconds/grossSeconds/effortSeconds`,
- `CleaningEventRecorder::verify`,
- test trait'leri `BuildsCleaningFixtures` ve `InteractsWithCleaningWorkflow`.

## Dosya sahipliği

| Ajan | Dosyalar |
|---|---|
| A | `app/Http/Controllers/CleaningController.php`, `app/Http/Requests/StoreCleaningRequest.php`, `resources/views/cleanings/index.blade.php`, `resources/views/cleanings/create.blade.php`, `resources/views/cleanings/form/*`, `resources/js/modules/cleaning-form.js`, `resources/scss/theme/pages/_cleaning-form.scss`, `tests/Feature/Screens/CleaningIndexTest.php`, `tests/Feature/Screens/CleaningCreateTest.php` |
| B | `app/Http/Controllers/CleaningDetailController.php`, `resources/views/cleanings/show.blade.php`, `resources/views/cleanings/show/*`, `resources/js/modules/` altında `cleaning-` ile başlamayan, B'nin oluşturduğu modüller (ör. `live-duration.js`), `resources/scss/theme/pages/_cleaning-detail.scss`, `tests/Feature/Screens/CleaningDetailTest.php` |
| C | `app/Http/Controllers/CleaningStepController.php`, `app/Http/Controllers/CleaningMaterialController.php`, `app/Http/Controllers/CleaningCancellationController.php`, `app/Http/Requests/Cleaning/*`, `tests/Feature/Screens/CleaningActionsTest.php` (gerekirse `tests/Feature/Screens/Actions/*`) |

Ortak kurallar:
- Faz 0 dosyaları salt okunurdur; değişiklik gerekiyorsa raporlanır.
- Her ajanın kendi test veritabanı vardır (`temizlik_test_a/b/c`).
- Commit yapılmaz.

## Sonuç

- **Testler:** Bütün paket geçiyor: 443 test, 2424 doğrulama.
- **Uçtan uca deneme (başsız Chrome):** Bir operatör formdan kayıt açtı. İlk adımı başlattı, duraklattı ve devam ettirdi, sonra beş adımı sırayla tamamladı.
  - Minimum süresinin altında kalan iki fazda ekran gerekçe istedi; gerekçe yazılınca fazlar "minimum süre altında" olarak kapandı.
  - Kayıt "Tamamlandı" oldu, olay zinciri doğrulandı, konsolda hata yok.
- **Telefon genişliği:** "Şimdi" kartı en üstte, canlı süre sayıyor. Görevli olmayan kullanıcıya buton yerine açıklama gösteriliyor.

### Entegrasyonda yapılanlar

- **Yönlendirme (Ajan B önerisi).** Adım aksiyonlarından sonra `#step-{id}` yerine `#now` çapasına dönülüyor. Önceki çapa sayfayı kontrol listesine kaydırıyor, operatör her dokunuştan sonra butonlara geri çıkmak zorunda kalıyordu.
- **Adım medyası.** Medya herkese açık diskten sunuluyor (`Storage::disk('public')`). `storage:link` container açılışında oluşturuluyor.
- **Tema.** `--app-current-step-*` değişkenleri eklendi. Güncel adım, "Şimdi" kartı, bütünlük durumu, geçersiz malzeme ve başlamamış kayıt uyarısı için tema kuralları yazıldı.
- **Sayfalama.** Sayfalama özeti Türkçe (`lang/tr.json`, Ajan A önerisi).
- **Küçük düzeltme.** `x-duration` sonunda satır sonu üretmiyor.

### Sonraki adımlar

- **Tanım yönetimi (yönetici):** makine, prosedür ve versiyon yayımlama, adım medyası yükleme, malzeme ve iş emri tanımları.
- **Raporlar:** makine ve faz bazında süre ve efor, minimum süre sapmaları.
- **Küçük iyileştirmeler (ajan önerileri):** `WorkOrder` için `machine`/`line` ilişkileri, `currentVersion` için eager load edilebilir ilişki, birden fazla makine alan `pendingOn` scope'u.

