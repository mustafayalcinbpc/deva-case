# Uygulama Planı — Durum Makinesi (State Machine)

Kapsam: Laravel + Docker iskeleti, temizlik/faz/adım durum makinesi, geçiş servisi ve testler.
Arayüz (AdminLTE), yönetim ekranları, raporlar ve demo verisi bu planın dışındadır.

Tüm kurallar `docs/is-gereksinimleri.md` dosyasındaki R-xx gereksinimlerine ve K-xx kararlarına dayanır.

## Fazlar

| Faz | İş | Durum | Paralel mi? |
|---|---|---|---|
| 0 | Temel: Docker, config, enum'lar, `CleaningRuleViolation`, migration'lar, modeller, test fixture'ları | Tamamlandı | — (diğer fazların ortak sözleşmesi) |
| 1A | Yardımcı servisler: `WorkTime`, `RecordNumberGenerator`, `CleaningEventRecorder` + testleri | Tamamlandı (Ajan A) | Evet |
| 1B | `CleaningWorkflow` (durum makinesi), `MaterialEntry`, süre dolumu komutu + zamanlama | Tamamlandı (Ajan B) | Evet |
| 1C | `CleaningWorkflow` için davranış testleri ve enum geçiş testleri (spesifikasyondan, koda bakmadan) | Tamamlandı (Ajan C) | Evet |
| 2 | Entegrasyon: tüm testleri çalıştırma, hataları giderme, Pint, README güncellemesi | Tamamlandı | Hayır (1A–1C'ye bağlı) |

1A, 1B ve 1C aynı anda çalışır. Birbirlerinin kodunu beklemezler, aşağıdaki **sözleşmelere** göre yazarlar.
1B, 1A'nın sınıflarını sözleşmedeki imzalarla kullanır; 1C, 1B'nin API'sini sözleşmedeki davranışlarla test eder.

## Dosya sahipliği

Her ajan yalnızca kendi dosyalarını oluşturur/düzenler. Faz 0 dosyaları donmuştur.

| Ajan | Dosyalar |
|---|---|
| A | `app/Services/Cleaning/WorkTime.php`, `app/Services/Cleaning/RecordNumberGenerator.php`, `app/Services/Cleaning/CleaningEventRecorder.php`, `tests/Unit/Cleaning/WorkTimeTest.php`, `tests/Feature/Cleaning/RecordNumberGeneratorTest.php`, `tests/Feature/Cleaning/CleaningEventRecorderTest.php` |
| B | `app/Services/Cleaning/CleaningWorkflow.php`, `app/Services/Cleaning/MaterialEntry.php`, `app/Console/Commands/ExpireStaleCleanings.php`, `routes/console.php`. Gerekirse `app/Exceptions/CleaningRuleViolation.php` ve `app/Models/*` (yalnızca ekleme; değişikliği raporla) |
| C | `tests/Unit/Cleaning/StatusTransitionsTest.php`, `tests/Feature/Cleaning/*Test.php` (A'nın dosyaları hariç) |

Ortak fixture: `tests/Feature/Cleaning/Concerns/BuildsCleaningFixtures.php` (Faz 0, salt okunur).

## Ortak kurallar (tüm ajanlar)

- Komutlar Docker içinde çalışır: `docker compose exec -T app <komut>`.
- Test veritabanları çakışmasın diye her ajanın kendi veritabanı var. Testi her zaman şöyle çalıştır:
  `docker compose exec -T -e DB_DATABASE=<db> app php artisan test --filter=<SınıfAdı>`
  - Ajan A: `temizlik_test_a`
  - Ajan B: `temizlik_test_b` (yalnızca kendi geçici denemeleri için; `tests/` altına dosya yazmaz)
  - Ajan C: testleri yazar, çalıştırmaz (1B bitmeden çalışamaz). Yalnızca `php -l` ile sözdizimi kontrolü yapar.
  - `temizlik` ve `temizlik_test` veritabanlarına dokunma.
- Git commit yapma. Docker container'larını durdurma/yeniden başlatma.
- Kod stili: Laravel 13 idiomları, mevcut modellerdeki gibi (`#[Fillable]`, `casts()` metodu), yorumlar Türkçe ve az.

## Sözleşme 1A — Yardımcı servisler

```php
namespace App\Services\Cleaning;

final class WorkTime
{
    /** @param iterable<WorkSlice> $slices */
    public static function net(iterable $slices): int;    // Σ $slice->durationSeconds()
    public static function gross(iterable $slices): int;  // max(endOrNow) − min(started_at); boşsa 0
    public static function effort(iterable $slices): int; // Σ durationSeconds() × workerCount()
}

final class RecordNumberGenerator
{
    // Açık bir DB transaction'ı içinde çağrılır. Sıra: sequence_counters tablosunda,
    // satır kilitlenerek (insertOrIgnore + lockForUpdate + update) artırılır.
    public function recordNo(Machine $machine, CleaningType $type, CarbonInterface $at): string;
    //   "{tesis}-{hat}-{makine}-{T|M}-{yıl}-{sıra:04}"  örn. IST-H01-M03-T-2026-0042
    //   sayaç anahtarı: "cleaning:{machine_id}:{yıl}"  (planlı ve plansız aynı sırayı paylaşır)
    public function fieldRef(Facility $facility, CarbonInterface $at): string;
    //   "{tesis}-SD-{yıl}-{sıra:04}"  örn. IST-SD-2026-0123
    //   sayaç anahtarı: "field-ref:{facility_id}:{yıl}"
}

final class CleaningEventRecorder
{
    // Çağıran, temizlik satırını kilitlemiş olmalı (ya da kayıt aynı transaction'da oluşturulmuş olmalı).
    // sequence = o temizliğin son olayının sequence'ı + 1; previous_hash = son olayın hash'i.
    public function record(Cleaning $cleaning, string $type, ?User $actor, CarbonInterface $at, array $payload = []): CleaningEvent;

    // Zinciri veritabanından baştan hesaplar; bir olay değişmiş ya da eksikse false.
    public function verify(Cleaning $cleaning): bool;

    /** @param iterable<CleaningEvent> $events  sequence sırasıyla */
    public function verifyEvents(iterable $events): bool;

    // sha256( json_encode([previous_hash, cleaning_id, sequence, type, actor_id,
    //   occurred_at "Y-m-d H:i:s", kanonik payload]) )
    // Kanonik payload: ilişkisel diziler anahtara göre özyinelemeli sıralanır (MySQL JSON
    // anahtar sırasını değiştirdiği için). JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES.
    public function hash(?string $previousHash, int $cleaningId, int $sequence, string $type, ?int $actorId, CarbonInterface $occurredAt, array $payload): string;
}
```

## Sözleşme 1B — `CleaningWorkflow`

```php
namespace App\Services\Cleaning;

final readonly class MaterialEntry
{
    public function __construct(public int $materialId, public string $lotNo, public string $expiryDate /* Y-m-d */) {}
}

final class CleaningWorkflow
{
    public function __construct(private RecordNumberGenerator $numbers, private CleaningEventRecorder $events) {}

    /** @param list<int> $helperIds  @param list<MaterialEntry> $materials */
    public function open(User $actor, Machine $machine, CleaningType $type, array $helperIds = [], array $materials = [], ?WorkOrder $workOrder = null, ?string $notes = null): Cleaning;
    public function addMaterial(User $actor, Cleaning $cleaning, MaterialEntry $entry): CleaningMaterial;
    public function voidMaterial(User $actor, CleaningMaterial $item, string $reason): void;
    /** @param list<int> $userIds */
    public function setWorkers(User $actor, CleaningStep $step, array $userIds): void;
    public function startStep(User $actor, CleaningStep $step): void;
    public function pauseStep(User $actor, CleaningStep $step): void;
    public function resumeStep(User $actor, CleaningStep $step): void;
    public function completeStep(User $actor, CleaningStep $step, ?string $deviationReason = null): void;
    public function cancel(User $actor, Cleaning $cleaning, CancelReason $reason, string $note): void;
    public function expireStale(): int; // süresi dolan kayıt sayısı
}
```

### Genel davranış

- Her public metot `DB::transaction(..., attempts: 3)` içinde çalışır; işlem başında temizlik satırı `lockForUpdate()` ile kilitlenir ve parametre olarak gelen model (`$step`, `$item`) kilitten sonra `refresh()` edilir.
- Zaman damgası metot başında bir kez alınır: `$now = now()->toImmutable()->startOfSecond()`; o çağrıdaki tüm zamanlar bu değerdir. Kullanıcıdan zaman bilgisi alınmaz (R-24, R-47).
- Durum değişiklikleri yalnızca `$model->transitionTo(...)` ile yapılır (enum'daki geçiş tablosu dışına çıkılırsa `invalid_transition`).
- Kural ihlallerinde `App\Exceptions\CleaningRuleViolation` fırlatılır; ihlalde hiçbir değişiklik kalıcı olmaz (transaction geri alınır).
- Kontrol sırası (aynı anda birden fazla ihlal varsa ilk olan döner): `record_closed` → `inactive_user` (işlemi yapan) → `not_allowed` → durum geçişi / `step_completed` → `step_out_of_order` → `no_workers` → `material_required` → `machine_busy` → `worker_busy`.

### Yetki

- **Adım işlemleri** (`setWorkers`, `startStep`, `pauseStep`, `resumeStep`, `completeStep`): kaydın sahibi ya da o adımın aktif görevlisi (R-44, K-10, K-11). Yönetici rolü burada ayrıcalık vermez. Aksi `not_allowed`.
- **Malzeme** (`addMaterial`, `voidMaterial`): kaydın sahibi ya da kaydın herhangi bir adımının aktif görevlisi. `voidMaterial` gerekçesi boşsa `reason_required`.
- **İptal** (`cancel`): not boşsa `reason_required`.
  - Kayıt `created` ise: sahibi (yalnızca `CancelReason::InvalidRecord` ile, diğer gerekçeyle `not_allowed`) ya da yönetici (K-09, K-08).
  - Kayıt `in_progress` ise: yalnızca yönetici; sahibi dahil diğerleri `not_allowed` (K-08).
- Kapalı kayıt (`completed`, `expired`, `cancelled`) üzerinde her işlem `record_closed`.

### `open`

1. İşlemi yapan aktif değilse `inactive_user`. Makine `is_active=false` ise `machine_unavailable` (K-16). Makinenin prosedürü ya da yayımlanmış versiyonu yoksa `no_procedure` (K-18).
2. İş emri verilmişse `WorkOrder::isUsableFor($machine)` değilse `invalid_work_order` (K-19). Yardımcılardan aktif olmayan varsa `inactive_user`.
3. Malzemelerden son kullanma tarihi bugünden (sunucu tarihi) önce olan varsa `material_expired` (K-14). Bugün geçerlidir.
4. Kayıt açmak makineyi **kilitlemez**; aynı makinede başka `created` kayıtlar olabilir (K-05).
5. Oluşturulanlar: `cleanings` (status `created`, sahibi = işlemi yapan, tesis/hat makineden kopyalanır, `procedure_version_id` = `procedure->currentVersion()` (K-15), `record_no` = `recordNo(...)`, `field_ref` NULL); her faz için `cleaning_phases` (`pending`); her adım için `cleaning_steps` (`pending`, `sequence` fazlar arası 1..N genel sıra); her adıma görevli olarak sahibi + yardımcılar (`cleaning_step_assignees`, tekrarsız); malzemeler.
6. Olaylar: `cleaning.opened` {record_no, type, machine_id, procedure_version_id, work_order_id, helper_ids}, her malzeme için `material.added` {cleaning_material_id, material_id, lot_no, expiry_date}.

### `startStep`

1. Adım `pending` olmalı (değilse `invalid_transition`). Kendisinden önceki bütün adımlar `completed` olmalı, değilse `step_out_of_order`. Aktif görevli yoksa `no_workers`.
2. Temizlik `created` ise (ilk adım):
   - Prosedür versiyonunda `material_required` ve geçerli (voided olmayan) malzeme yoksa `material_required` (K-12).
   - Temizlik `in_progress` olur, `started_at = $now`; planlıysa `field_ref = fieldRef(...)` (K-17).
   - Kaydetme sırasında `active_machine_id` unique index'i ihlal edilirse (`Illuminate\Database\UniqueConstraintViolationException`) `machine_busy`; `blocking` = o makinedeki `in_progress` kayıt (K-05, R-35). Uygulama tarafında ayrı bir ön kontrol yapılmaz; kilidin tek kaynağı veritabanıdır.
   - Olay: `cleaning.started` {field_ref}.
3. Faz `pending` ise `in_progress` olur, `started_at = $now`. Olay: `phase.started` {phase_id, sequence}.
4. Adım `running` olur, `started_at = $now` (yalnızca ilk başlatmada set edilir).
5. Yeni dilim açılır: `work_slices` (started_at, started_by) + aktif görevliler için `work_slice_workers`. Görevli satırı eklenirken `active_user_id` unique index'i ihlal edilirse `worker_busy`; `blocking` = o kişinin açık diliminin temizliği (K-07).
6. Olay: `step.started` {step_id, sequence, worker_ids}.

### `pauseStep` / `resumeStep`

- `pauseStep`: adım `running` → `paused`; açık dilim `ended_at = $now`, `end_reason = paused`, `ended_by`; dilimin görevli satırlarına `ended_at = $now` (kişiler serbest kalır). Olay `step.paused` {step_id}.
- `resumeStep`: adım `paused` → `running`; aktif görevli yoksa `no_workers`; yeni dilim açılır (`worker_busy` kuralı aynı). Olay `step.resumed` {step_id, worker_ids}.

### `setWorkers`

- `$userIds` boşsa `no_workers`; aralarında aktif olmayan varsa `inactive_user`; adım `completed` ise `step_completed`.
- Fark yoksa hiçbir şey yapılmaz (olay da yazılmaz).
- Çıkarılan görevlilerin satırları silinmez: `removed_at = $now`, `removed_by`. Yeni görevliler eklenir.
- Adım `running` ise: açık dilim `end_reason = workers_changed` ile kapanır, aynı `$now` ile yeni görevli listesiyle yeni dilim açılır (K-04). Yeni kişi başka yerde çalışıyorsa `worker_busy` ve hiçbir değişiklik kalmaz.
- Olay: `step.workers_changed` {step_id, added, removed}.

### `completeStep`

1. Adım `running` olmalı (`paused` iken tamamlanamaz → `invalid_transition`).
2. Açık dilim `end_reason = completed` ile kapanır; adım `completed`, `completed_at = $now`. Olay `step.completed` {step_id}.
3. Fazın son adımıysa: `measured = $phase->measuredSeconds()` (K-02: `include_gaps` ise brüt, değilse net), `minimum = procedurePhase.min_duration_seconds`.
   - `measured < minimum` ve `$deviationReason` boşsa `below_minimum_duration` (context: measured_seconds, minimum_seconds) ve hiçbir değişiklik kalmaz — adım `running` kalır (K-01).
   - `measured < minimum` ve gerekçe varsa: faz `below_minimum = true`, `deviation_reason` kaydedilir.
   - Süre yeterliyse gerekçe yok sayılır (`deviation_reason` NULL).
   - Faz `completed`, `completed_at = $now`, `measured_seconds = measured`. Olay `phase.completed` {phase_id, sequence, measured_seconds, minimum_seconds, below_minimum, deviation_reason}.
4. Son fazın son adımıysa: temizlik `completed`, `closed_at = $now` (makine kilidi kalkar). Olay `cleaning.completed` {net_seconds, gross_seconds, effort_seconds}.

### `cancel`

- Çalışan (`running`) bir adım varsa: açık dilim `end_reason = cancelled` ile kapanır, görevliler serbest kalır, adım `paused` olur.
- Temizlik `cancelled`, `closed_at = $now`, `cancelled_by`, `cancel_reason`, `cancel_note`. Yapılan adımlar ve dilimler olduğu gibi kalır (K-08). Olay `cleaning.cancelled` {reason, note}.

### `expireStale` ve zamanlama

- `created` durumunda ve `created_at <= now − config('cleaning.stale_after_minutes')` olan her kayıt için ayrı transaction: kilitle, hâlâ `created` mı kontrol et, `expired` yap, `closed_at = $now`. Olay `cleaning.expired` {stale_after_minutes}, `actor = null` (sistem) (K-06). `in_progress` kayıtlara asla dokunulmaz (R-31).
- Komut: `cleanings:expire-stale` (`app/Console/Commands/ExpireStaleCleanings.php`).
- Zamanlama (`routes/console.php`): her dakika, `withoutOverlapping()`.

### Olay tipleri

`cleaning.opened`, `cleaning.started`, `cleaning.completed`, `cleaning.cancelled`, `cleaning.expired`, `phase.started`, `phase.completed`, `step.started`, `step.paused`, `step.resumed`, `step.workers_changed`, `step.completed`, `material.added`, `material.voided` {cleaning_material_id, reason}.

## Sözleşme 1C — Testler

- `RefreshDatabase` + `Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures` kullanılır. Servis `app(CleaningWorkflow::class)` ile alınır.
- Zaman: `$this->travelTo(...)` / `$this->travel(10)->minutes()`.
- Her test tek bir kuralı ihlal eden bir senaryo kurar ve `CleaningRuleViolation`'ın `rule` alanını doğrular (mesaj metnini değil).
- Kapsanacak senaryolar (her biri en az bir test): yukarıdaki sözleşmedeki her kural kodu; R-20 (kayıt 08:00, ilk adım 08:15); R-26 (10 dk, 2 kişi → süre 600 sn, efor 1200 sn); K-04 (dilim bazında efor); R-28/K-02 (12 sn adım + 40 dk boşluk, `include_gaps` açık/kapalı); K-01 (gerekçeli/gerekçesiz); K-05 (iki başlamamış kayıt, ilk başlatan kazanır; tamamlanınca diğeri başlayabilir); K-07 (Ahmet/Mehmet örneği; duraklatınca serbest kalma); K-06 (süre dolumu, `in_progress`'e dokunmama, `actor_id` NULL); K-08/K-09 iptal kuralları; K-15 (açıktaki kayıt yeni versiyondan etkilenmez); K-17 (numara formatı, plansızda `field_ref` yok, `field_ref` ilk adımda üretilir); R-15/R-47 (sahiplik ve zaman damgaları değiştirilemez, `LogicException`); olay tablosunun veritabanı seviyesinde değiştirilemezliği (`QueryException`); R-35 (servisi atlayıp iki kaydı doğrudan `in_progress` yapmak unique index'e takılır).

## Sonuç

- 1A–1C ayrı ayrı yazıldı; birleştirmede bütün test paketi ilk çalıştırmada geçti. Son durum: 293 test, 1124 doğrulama.
- Testlerin hatayı gerçekten yakaladığı, servise bilerek hata eklenerek denendi. Adım sırası kontrolünü kaldırmak 7, minimum süre kontrolünü kaldırmak 6, sahibin iptal kuralını gevşetmek 2, duraklatmada personeli serbest bırakmamak 27 testi kırdı.

### Sözleşmeden sapmalar ve netleştirmeler

- **Sayaç kilitleme sırası (1A).** `insertOrIgnore → lockForUpdate` sırası, var olan bir sayaca aynı anda gelen iki istekte MySQL'de deadlock üretiyor (iki oturumla yeniden üretildi). Önce satırın varlığına bakılıyor, yalnızca yoksa `insertOrIgnore` çalışıyor.
- **`setWorkers` kontrol sırası (1B).** Metodun kendi bölümündeki sıra genel sırayla çelişiyordu. Genel sıra esas alındı: `record_closed → inactive_user → not_allowed → step_completed → no_workers → inactive_user (görevliler) → worker_busy`.
- **`reason_required` (1B).** Yetki kontrolünden sonra gelir.
- **Zaman (1B).** `$now`, kayıt satırının kilidi alındıktan sonra okunur. Kilit bekleyen bir istek, önceki işlemin açtığı dilimden daha önceki bir zamanla onu kapatamaz.
- **İhlal sonrası (1B).** Parametre olarak gelen model, geri alınan değişikliği bellekte göstermesin diye veritabanından yeniden okunur.
- **Eklenen kural (entegrasyon).** Zaten geçersiz kılınmış malzemeyi tekrar geçersiz kılmak `material_already_voided` hatası verir. Önceden iç `LogicException` üretiyordu.
- **Doküman düzeltmesi.** Duraklatılmış adım doğrudan tamamlanamaz; önce devam ettirilir. Gereksinim dokümanındaki adım diyagramı bu kurala uyduruldu.

### Bilinen sınırlar

- Hash zinciri gizli anahtar içermez. Doğrudan INSERT yetkisi olan biri zincirin sonuna geçerli görünen bir olay ekleyebilir; trigger'lar yalnızca UPDATE/DELETE'i engeller. Daha güçlü koruma için zincirin son hash'i periyodik olarak dışarıya (ör. imzalı rapor) yazılabilir.
- Arayüz (AdminLTE), yönetim ekranları, raporlar ve demo verisi henüz yok.

