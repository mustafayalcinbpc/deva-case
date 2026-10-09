<?php

namespace Database\Seeders;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Enums\UserRole;
use App\Models\Cleaning;
use App\Models\CleaningStep;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Material;
use App\Models\Procedure;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cleaning\CleaningWorkflow;
use App\Services\Cleaning\MaterialEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Arayüzün gösterecek bir şeyi olsun ve değerlendiren kişi giriş yapabilsin diye demo verisi.
 *
 * Tanımlar (tesis, hat, makine, prosedür, malzeme, iş emri, kullanıcı) doğrudan yazılır.
 * Temizlik kayıtları ise yalnızca CleaningWorkflow üzerinden oluşturulur: saat
 * `CarbonImmutable::setTestNow()` ile geçmişe alınır ve kayıtlar son birkaç haftaya yayılan
 * gerçekçi bir sırayla ilerletilir. Böylece olay zinciri, kayıt numaraları, saha defteri
 * referansları ve süreler gerçek kullanımda oluşacağı gibi oluşur. Sonunda saat sıfırlanır.
 *
 * Tarihler çalıştırma anına göredir (geçmiş günler sabit saatlerde, bugünkü kayıtlar
 * "şimdi"den geriye doğru); bunun dışında veri her çalıştırmada aynıdır.
 *
 * Not: Bugün açılan başlamamış kayıt, zamanlayıcı çalışıyorsa açılıştan 30 dk sonra "süresi
 * doldu" durumuna geçer (K-06).
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private CleaningWorkflow $workflow;

    /** Çalıştırma anı (tam saniye, UTC). */
    private CarbonImmutable $now;

    /** Bugünün başlangıcı, gösterim saat diliminde (geçmiş günlerin saatleri buna göre). */
    private CarbonImmutable $today;

    /** @var array<string, User> e-postanın @ öncesi => kullanıcı */
    private array $users = [];

    /** @var array<string, Line> */
    private array $lines = [];

    /** @var array<string, Machine> "H01-M02" => makine */
    private array $machines = [];

    /** @var array<string, Material> */
    private array $materials = [];

    /** @var array<string, WorkOrder> */
    private array $workOrders = [];

    /** @var array<string, Procedure> */
    private array $procedures = [];

    public function run(CleaningWorkflow $workflow): void
    {
        $this->workflow = $workflow;
        $this->now = CarbonImmutable::now('UTC')->startOfSecond();
        $this->today = $this->now->setTimezone(config('app.display_timezone'))->startOfDay();

        try {
            $this->seedDefinitions();
            $this->seedHistory();
            $this->seedToday();
        } finally {
            CarbonImmutable::setTestNow(); // Carbon 3: Carbon ve CarbonImmutable aynı saati paylaşır.
        }
    }

    // ---------------------------------------------------------------------------------------
    // Tanımlar
    // ---------------------------------------------------------------------------------------

    private function seedDefinitions(): void
    {
        $this->travelTo($this->day(45, '09:00'));

        $this->seedUsers();
        $this->seedProcedures();
        $this->seedLocations();
        $this->seedMaterials();
        $this->seedWorkOrders();
    }

    private function seedUsers(): void
    {
        $users = [
            ['ahmet@demo.test', 'Ahmet Yılmaz', UserRole::Operator],
            ['mehmet@demo.test', 'Mehmet Kaya', UserRole::Operator],
            ['ayse@demo.test', 'Ayşe Demir', UserRole::Operator],
            ['yonetici@demo.test', 'Zeynep Arslan', UserRole::Manager],
            // Geçmişte kayıt açmış, sonra ayrılmış personel; ayrılınca pasif yapılır (aşağıda).
            ['eski@demo.test', 'Eski Personel', UserRole::Operator],
        ];

        foreach ($users as [$email, $name, $role]) {
            $this->users[strstr($email, '@', true)] = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
                'is_active' => true,
            ]);
        }
    }

    private function seedProcedures(): void
    {
        $definitions = [
            'PRC-DOL' => ['Sıvı dolum makinesi temizliği', true, $this->fillingPhases(version: 1)],
            'PRC-TNK' => ['Hazırlama tankı temizliği (CIP)', true, $this->tankPhases()],
            'PRC-BLS' => ['Blister makinesi temizliği', false, $this->blisterPhases()],
            'PRC-PKT' => ['Paketleme makinesi temizliği', false, $this->packagingPhases()],
        ];

        foreach ($definitions as $code => [$name, $materialRequired, $phases]) {
            $procedure = Procedure::create(['code' => $code, 'name' => $name]);
            $this->publishVersion($procedure, 1, $materialRequired, $phases);
            $this->procedures[$code] = $procedure;
        }
    }

    private function seedLocations(): void
    {
        $facility = Facility::create(['code' => 'IST', 'name' => 'İstanbul Tesisi']);

        $lines = [
            'H01' => ['Dolum Hattı', [
                'M01' => ['Hazırlama Tankı', 'PRC-TNK'],
                'M02' => ['Sıvı Dolum Makinesi 1', 'PRC-DOL'],
                'M03' => ['Sıvı Dolum Makinesi 2', 'PRC-DOL'],
                // R-12, K-16: geçmişte kullanılmış, sonra kullanımdan kaldırılmış makine.
                'M04' => ['Sıvı Dolum Makinesi (eski)', 'PRC-DOL'],
            ]],
            'H02' => ['Paketleme Hattı', [
                'M01' => ['Blister Makinesi', 'PRC-BLS'],
                'M02' => ['Kartonlama Makinesi', 'PRC-PKT'],
                'M03' => ['Etiketleme Makinesi', 'PRC-PKT'],
            ]],
        ];

        foreach ($lines as $lineCode => [$lineName, $machines]) {
            $line = $this->lines[$lineCode] = Line::create(['facility_id' => $facility->id, 'code' => $lineCode, 'name' => $lineName]);

            foreach ($machines as $code => [$name, $procedureCode]) {
                $this->machines["{$lineCode}-{$code}"] = Machine::create([
                    'line_id' => $line->id,
                    'procedure_id' => $this->procedures[$procedureCode]->id,
                    'code' => $code,
                    'name' => $name,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function seedMaterials(): void
    {
        $materials = [
            'DET-01' => 'Alkali deterjan',
            'DEZ-02' => 'Dezenfektan (perasetik asit)',
            'DUR-03' => 'Durulama asidi',
            'ALK-04' => 'İzopropil alkol %70',
        ];

        foreach ($materials as $code => $name) {
            $this->materials[$code] = Material::create(['code' => $code, 'name' => $name]);
        }
    }

    private function seedWorkOrders(): void
    {
        // K-19: gerçekte ERP'den gelir. Kimi makineye, kimi yalnızca hatta bağlı.
        $workOrders = [
            'IE-2026-1041' => ['H01-M02', null, 'Parasetamol şurup 150 ml — dolum'],
            'IE-2026-1042' => ['H01-M03', null, 'İbuprofen süspansiyon 100 ml — dolum'],
            'IE-2026-1043' => [null, 'H01', 'Öksürük şurubu çözelti hazırlama — 500 L parti'],
            'IE-2026-1048' => ['H01-M02', null, 'Parasetamol şurup 150 ml — dolum (2. parti)'],
            'IE-2026-1050' => [null, 'H02', 'C vitamini 1000 mg efervesan tablet — paketleme'],
            'IE-2026-1051' => ['H02-M01', null, 'Amoksisilin 500 mg film tablet — blister'],
            'IE-2026-1055' => ['H01-M02', null, 'Çinko şurup 100 ml — dolum'],
            'IE-2026-1056' => ['H02-M01', null, 'Parasetamol 500 mg tablet — blister'],
            'IE-2026-1058' => ['H01-M03', null, 'İbuprofen süspansiyon 200 ml — dolum'],
        ];

        foreach ($workOrders as $code => [$machineKey, $lineCode, $description]) {
            $machine = $machineKey !== null ? $this->machines[$machineKey] : null;

            $this->workOrders[$code] = WorkOrder::create([
                'code' => $code,
                'line_id' => $machine?->line_id ?? $this->lines[$lineCode]->id,
                'machine_id' => $machine?->id,
                'description' => $description,
            ]);
        }
    }

    /**
     * @param  list<array{name: string, min_minutes: int, include_gaps: bool, steps: list<array{0: string, 1: string}>}>  $phases
     */
    private function publishVersion(Procedure $procedure, int $version, bool $materialRequired, array $phases): void
    {
        $procedureVersion = $procedure->versions()->create([
            'version' => $version,
            'material_required' => $materialRequired,
            'published_at' => CarbonImmutable::now(),
        ]);

        foreach ($phases as $phaseIndex => $phase) {
            $procedurePhase = $procedureVersion->phases()->create([
                'sequence' => $phaseIndex + 1,
                'name' => $phase['name'],
                'min_duration_seconds' => $phase['min_minutes'] * 60,
                'include_gaps' => $phase['include_gaps'],
            ]);

            foreach ($phase['steps'] as $stepIndex => [$title, $description]) {
                $procedurePhase->steps()->create([
                    'sequence' => $stepIndex + 1,
                    'title' => $title,
                    'description' => $description,
                ]);
            }
        }
    }

    /**
     * Sıvı dolum makinesi: R-03 örneğindeki gibi 5, 3 ve 4 adımlı üç faz. v2'de yıkama fazının
     * minimum süresi 15 dk'dan 20 dk'ya çıkar ve dezenfektan adımının açıklaması değişir (R-11).
     *
     * @return list<array{name: string, min_minutes: int, include_gaps: bool, steps: list<array{0: string, 1: string}>}>
     */
    private function fillingPhases(int $version): array
    {
        $disinfection = $version === 1
            ? 'DEZ-02 çözeltisini (%0,5) bütün ürün temas yüzeylerine püskürtün; yüzeylerin ıslak kalmasını sağlayın.'
            : 'DEZ-02 çözeltisini (%1) bütün ürün temas yüzeylerine püskürtün; nozül yuvaları gibi ulaşılması zor bölgelere fırçayla uygulayın.';

        return [
            [
                'name' => 'Hazırlık ve söküm',
                'min_minutes' => 10,
                'include_gaps' => false,
                'steps' => [
                    ['Makineyi durdur ve enerjisini kes', 'Ana şalteri kapatın, kilitleyip etiketleyin (LOTO). Basınçlı hava hattını kapatın.'],
                    ['Ürün kalıntısını boşalt', 'Dolum tankında ve hortumlarda kalan ürünü etiketli atık kabına boşaltın.'],
                    ['Dolum nozüllerini sök', 'Nozülleri ve contaları sökerek numaralı tepsiye dizin; hasarlı conta varsa bakım ekibine bildirin.'],
                    ['Ürün temas parçalarını sök', 'Piston, valf ve dolum hortumlarını söküp yıkama arabasına taşıyın.'],
                    ['Kuru temizlik yap', 'Gövdedeki etiket, ambalaj artığı ve tozu vakumla ve tüy bırakmayan bezle alın.'],
                ],
            ],
            [
                // Dezenfektanın temas süresi adımlar arasında da sürer; boşluklar faz süresine dahildir (K-02).
                'name' => 'Yıkama ve dezenfeksiyon',
                'min_minutes' => $version === 1 ? 15 : 20,
                'include_gaps' => true,
                'steps' => [
                    ['Alkali deterjanla yıka', "Sökülen parçaları ve ürün temas yüzeylerini %2'lik DET-01 çözeltisiyle fırçalayarak yıkayın."],
                    ['Ara durulama yap', 'Deterjanı arıtılmış suyla durulayın; yüzeyde köpük kalmadığını kontrol edin.'],
                    ['Dezenfektan uygula', $disinfection],
                ],
            ],
            [
                'name' => 'Durulama, montaj ve kontrol',
                'min_minutes' => 10,
                'include_gaps' => false,
                'steps' => [
                    ['Son durulamayı yap', 'DUR-03 ile asidik durulama yapın, ardından arıtılmış suyla son kez durulayın.'],
                    ['Parçaları kurula', 'Parçaları filtreli basınçlı havayla kurulayın; açıkta nem kalmadığını kontrol edin.'],
                    ['Parçaları monte et', 'Nozül, conta ve hortumları talimattaki sırayla takın; bağlantıların sıkılığını kontrol edin.'],
                    ['Görsel kontrol yap ve hattı teslim et', 'Temas yüzeylerinde görünür kalıntı olmadığını kontrol edin, "Temizlendi" etiketini makineye asın.'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{name: string, min_minutes: int, include_gaps: bool, steps: list<array{0: string, 1: string}>}>
     */
    private function tankPhases(): array
    {
        return [
            [
                'name' => 'Ön durulama',
                'min_minutes' => 5,
                'include_gaps' => false,
                'steps' => [
                    ['Tankı boşalt ve izole et', 'Alt boşaltma vanasını açarak tankı tamamen boşaltın; ürün hattı vanalarını kapatın.'],
                    ['Ön durulama yap', 'Tankı sprey bilyesinden arıtılmış suyla en az 5 dakika durulayın.'],
                ],
            ],
            [
                'name' => 'Kimyasal yıkama',
                'min_minutes' => 20,
                'include_gaps' => true,
                'steps' => [
                    ['Alkali deterjan sirkülasyonu', "DET-01 çözeltisini 70 °C'de 15 dakika sirküle edin; sıcaklığı panelden takip edin."],
                    ['Ara durulama yap', 'Deterjanı tahliye edin ve tankı arıtılmış suyla durulayın.'],
                    ['Asidik yıkama yap', 'DUR-03 çözeltisini 10 dakika sirküle edin, ardından tahliye edin.'],
                ],
            ],
            [
                'name' => 'Son durulama ve kontrol',
                'min_minutes' => 10,
                'include_gaps' => false,
                'steps' => [
                    ['Son durulamayı yap', 'Saflaştırılmış suyla son durulamayı yapın; son durulama suyunun pH değerini ölçün (6,5–7,5).'],
                    ['Görsel kontrol yap', 'Tank iç yüzeyini fenerle kontrol edin, vanaları kapalı konuma getirin ve tankı "Temiz" etiketiyle işaretleyin.'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{name: string, min_minutes: int, include_gaps: bool, steps: list<array{0: string, 1: string}>}>
     */
    private function blisterPhases(): array
    {
        return [
            [
                'name' => 'Söküm ve kuru temizlik',
                'min_minutes' => 10,
                'include_gaps' => false,
                'steps' => [
                    ['Makineyi durdur ve folyoları çıkar', 'Makineyi durdurun; PVC ve alüminyum folyo rulolarını çıkarıp etiketli kutulara koyun.'],
                    ['Kalan tabletleri topla', 'Besleme kanalı ve bunkerdeki tabletleri toplayın, sayın ve imha kabına alın.'],
                    ['Format parçalarını sök', 'Besleme kanalını ve şekillendirme kalıplarını sökün.'],
                ],
            ],
            [
                'name' => 'Silme, montaj ve kontrol',
                'min_minutes' => 10,
                'include_gaps' => true,
                'steps' => [
                    ['Toz ve kırıntıları vakumla', 'Makine içindeki toz ve tablet kırıntılarını vakumla alın.'],
                    ['Yüzeyleri alkolle sil', 'Ürün temas yüzeylerini ALK-04 (%70 izopropil alkol) ile nemlendirilmiş tüy bırakmayan bezle silin.'],
                    ['Montaj ve hat açılış kontrolü', 'Format parçalarını takın; önceki ürüne ait folyo, etiket ya da tablet kalmadığını kontrol edin.'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{name: string, min_minutes: int, include_gaps: bool, steps: list<array{0: string, 1: string}>}>
     */
    private function packagingPhases(): array
    {
        return [
            [
                'name' => 'Hat boşaltma',
                'min_minutes' => 5,
                'include_gaps' => false,
                'steps' => [
                    ['Hattaki ürün ve ambalajı topla', 'Bant üzerindeki kutu, prospektüs ve etiketleri toplayın; önceki ürüne ait malzemeyi depoya iade edin.'],
                    ['Basılı malzeme mutabakatı yap', 'Kullanılan, iade edilen ve imha edilen basılı malzeme adetlerini karşılaştırın.'],
                ],
            ],
            [
                'name' => 'Temizlik ve kontrol',
                'min_minutes' => 10,
                'include_gaps' => false,
                'steps' => [
                    ['Bant ve kılavuzları temizle', 'Taşıma bandını, kılavuzları ve magazinleri vakumlayıp nemli bezle silin.'],
                    ['Kodlama ünitesini temizle', 'Lot/SKT baskı kafasını temizleyin; önceki ürünün baskı ayarlarını silin.'],
                    ['Hat açılış kontrolünü yap', 'Hatta önceki ürüne ait hiçbir malzeme kalmadığını kontrol edin ve "Temizlendi" etiketini asın.'],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Geçmiş temizlikler (son birkaç hafta)
    // ---------------------------------------------------------------------------------------

    private function seedHistory(): void
    {
        ['ahmet' => $ahmet, 'mehmet' => $mehmet, 'ayse' => $ayse, 'yonetici' => $manager, 'eski' => $former] = $this->users;

        // Kullanımdan kaldırılacak makinedeki son temizlik (v1).
        $this->travelTo($this->day(25, '08:30'));
        $cleaning = $this->open($ahmet, 'H01-M04', materials: ['DET-01', 'DEZ-02', 'DUR-03']);
        $this->wait(5);
        $this->runSteps($ahmet, $cleaning, [1 => 3, 2 => 4, 3 => 4, 4 => 3, 5 => 3]);
        $this->runSteps($ahmet, $cleaning, [6 => 5, 7 => 3, 8 => 7]);
        $this->runSteps($ahmet, $cleaning, [9 => 4, 10 => 3, 11 => 5, 12 => 3]);

        // R-21 adım adım ilerleme, R-22 örneği: 1. adım yalnızca Ahmet, 2. adım Ahmet + Mehmet,
        // 3. adım yalnızca Mehmet. Yıkama fazı 17 dk sürer; v1'in 15 dk minimumunu geçer.
        $this->travelTo($this->day(21, '08:00'));
        $cleaning = $this->open($ahmet, 'H01-M02', helpers: [$mehmet], materials: ['DET-01', 'DEZ-02', 'DUR-03'], workOrder: 'IE-2026-1041');
        $this->wait(5);
        $this->workflow->setWorkers($ahmet, $this->step($cleaning, 1), [$ahmet->id]);
        $this->runSteps($ahmet, $cleaning, [1 => 3, 2 => 4]);
        $this->workflow->setWorkers($ahmet, $this->step($cleaning, 3), [$mehmet->id]);
        $this->runSteps($mehmet, $cleaning, [3 => 5]);
        $this->runSteps($ahmet, $cleaning, [4 => 4, 5 => 3, 6 => 6, 7 => 3, 8 => 6]);
        $this->runSteps($mehmet, $cleaning, [9 => 4, 10 => 3, 11 => 5, 12 => 3]);

        // R-12, K-16: makine kullanımdan kaldırılır; geçmiş kaydı görünmeye devam eder.
        $this->travelTo($this->day(20, '12:00'));
        $this->machines['H01-M04']->update(['is_active' => false]);

        // K-03: çay molası için duraklatılan adım; dilimler arasındaki ara net süreye girmez.
        // Malzeme zorunlu değil, yine de girilmiş.
        $this->travelTo($this->day(18, '09:30'));
        $cleaning = $this->open($ayse, 'H02-M01', materials: ['ALK-04'], workOrder: 'IE-2026-1051');
        $this->wait(10);
        $this->runSteps($ayse, $cleaning, [1 => 4, 2 => 6]);
        $this->workflow->startStep($ayse, $this->step($cleaning, 3));
        $this->wait(4);
        $this->workflow->pauseStep($ayse, $this->step($cleaning, 3));
        $this->wait(25);
        $this->workflow->resumeStep($ayse, $this->step($cleaning, 3));
        $this->wait(4);
        $this->workflow->completeStep($ayse, $this->step($cleaning, 3));
        $this->wait(1);
        $this->runSteps($ayse, $cleaning, [4 => 3, 5 => 6, 6 => 4]);

        // K-04: adım sürerken görevli değişir. Ayşe sirkülasyonun 6. dakikasında katılır,
        // 12. dakikasında ayrılır; adımda üç dilim oluşur ve efor dilim bazında hesaplanır.
        $this->travelTo($this->day(15, '07:45'));
        $cleaning = $this->open($mehmet, 'H01-M01', materials: ['DET-01', 'DUR-03'], workOrder: 'IE-2026-1043');
        $this->wait(5);
        $this->runSteps($mehmet, $cleaning, [1 => 3, 2 => 6]);
        $this->workflow->startStep($mehmet, $this->step($cleaning, 3));
        $this->wait(6);
        $this->workflow->setWorkers($mehmet, $this->step($cleaning, 3), [$mehmet->id, $ayse->id]);
        $this->wait(6);
        $this->workflow->setWorkers($mehmet, $this->step($cleaning, 3), [$mehmet->id]);
        $this->wait(4);
        $this->workflow->completeStep($mehmet, $this->step($cleaning, 3));
        $this->wait(1);
        $this->runSteps($mehmet, $cleaning, [4 => 5, 5 => 10, 6 => 6, 7 => 5]);

        // R-19: plansız müdahale. Saha defteri referansı üretilmez; kayıt numarasında tip "M".
        $this->travelTo($this->day(13, '14:20'));
        $cleaning = $this->open(
            $ahmet,
            'H01-M03',
            type: CleaningType::Unplanned,
            helpers: [$mehmet],
            materials: ['DET-01', 'DEZ-02', 'DUR-03'],
            workOrder: 'IE-2026-1042',
            notes: 'Dolum sırasında nozüllerde ürün kristalleşmesi görüldü; üretim durduruldu, acil temizlik yapıldı.',
        );
        $this->wait(3);
        $this->runSteps($ahmet, $cleaning, [1 => 3, 2 => 3, 3 => 4, 4 => 3, 5 => 2, 6 => 5, 7 => 3, 8 => 6, 9 => 4, 10 => 3, 11 => 4, 12 => 2]);

        // R-11, K-15: dolum prosedürünün v2'si yayımlanır; bundan sonra açılan kayıtlar v2 ile
        // yürür, önceki kayıtlar v1'de kalır. (Geçerli versiyon yayım tarihine değil versiyon
        // numarasına bakar; bu yüzden v2 zaman çizelgesinde tam yerinde yayımlanır.)
        $this->travelTo($this->day(10, '09:00'));
        $this->publishVersion($this->procedures['PRC-DOL'], 2, true, $this->fillingPhases(version: 2));

        // R-31, K-08: kaydın sahibi vardiya sonunda adımı duraklatır, sonra işten ayrılır.
        // Yönetici yarım kalan kaydı "personel ayrıldı" gerekçesiyle iptal eder; yapılan adımlar
        // kayıtta kalır. Temizlik yeni bir kayıtla baştan yapılır.
        $this->travelTo($this->day(9, '16:25'));
        $abandoned = $this->open($former, 'H01-M01', materials: ['DET-01', 'DUR-03'], workOrder: 'IE-2026-1043');
        $this->wait(5);
        $this->runSteps($former, $abandoned, [1 => 3, 2 => 6]);
        $this->workflow->startStep($former, $this->step($abandoned, 3));
        $this->wait(14);
        $this->workflow->pauseStep($former, $this->step($abandoned, 3));

        $this->travelTo($this->day(8, '08:20'));
        $former->update(['is_active' => false]);
        $this->wait(10);
        $this->workflow->cancel($manager, $abandoned, CancelReason::PersonnelLeft, 'Kaydın sahibi işten ayrıldı, temizliği devam ettiremiyor. Temizlik yeni bir kayıtla baştan yapılacak.');

        $this->wait(30);
        $cleaning = $this->open($ahmet, 'H01-M01', materials: ['DET-01', 'DUR-03'], workOrder: 'IE-2026-1043', notes: "{$abandoned->record_no} numaralı iptal edilen kaydın yerine açıldı.");
        $this->wait(4);
        $this->runSteps($ahmet, $cleaning, [1 => 3, 2 => 6, 3 => 15, 4 => 5, 5 => 10, 6 => 6, 7 => 5]);

        // K-09: sahibi, başlamamış kaydını "hatalı kayıt" gerekçesiyle iptal eder; doğru makine
        // için yeni kayıt açar.
        $this->travelTo($this->day(8, '10:30'));
        $wrong = $this->open($ayse, 'H02-M03', workOrder: 'IE-2026-1050');
        $this->wait(3);
        $this->workflow->cancel($ayse, $wrong, CancelReason::InvalidRecord, 'Yanlış makine seçildi; temizlik Kartonlama Makinesi için yeniden açıldı.');
        $this->wait(1);
        $cleaning = $this->open($ayse, 'H02-M02', workOrder: 'IE-2026-1050');
        $this->wait(4);
        $this->runSteps($ayse, $cleaning, [1 => 3, 2 => 4, 3 => 5, 4 => 4, 5 => 3]);

        // K-15: v2 ile açılan kayıt. Yıkama fazı yine 17 dk sürer ama v2'nin minimumu 20 dk;
        // faz gerekçeyle kapanır ve sapma olarak işaretlenir (K-01).
        // K-12: yanlış lot numarasıyla girilen malzeme silinmez, gerekçeyle geçersiz kılınır.
        $this->travelTo($this->day(7, '08:15'));
        $cleaning = $this->open($ayse, 'H01-M02', helpers: [$ahmet], materials: ['DET-01', 'DUR-03'], workOrder: 'IE-2026-1048');
        $wrongLot = $this->workflow->addMaterial($ayse, $cleaning, $this->lot('DEZ-02', 'DZ-11270'));
        $this->wait(1);
        $this->workflow->voidMaterial($ayse, $wrongLot, 'Lot numarası etiketten yanlış okundu; doğru lot DZ-11207.');
        $this->workflow->addMaterial($ayse, $cleaning, $this->lot('DEZ-02'));
        $this->wait(4);
        $this->runSteps($ayse, $cleaning, [1 => 3, 2 => 4, 3 => 4, 4 => 3, 5 => 3, 6 => 6, 7 => 3]);
        $this->runStep($ayse, $cleaning, 8, 6, deviationReason: 'Hattın acil üretime açılması istendi; yıkama vardiya amirinin onayıyla kısa tutuldu, kalite güvence birimine bildirildi.');
        $this->wait(1);
        $this->runSteps($ayse, $cleaning, [9 => 4, 10 => 3, 11 => 5, 12 => 3]);

        // K-06: açılıp 30 dk içinde başlatılmayan kayıt sistem tarafından "süresi doldu" olur.
        $this->travelTo($this->day(5, '13:00'));
        $this->open($ahmet, 'H02-M03');
        $this->wait(31);
        $this->workflow->expireStale();

        // R-28: 1. adım 12 saniyede biter, operatör yemeğe gider ve 40 dk sonra 2. adıma başlar.
        // Faz boşlukları saymadığı için (net süre) bu 40 dk faz süresine girmez; brüt süre ise
        // raporda ayrıca görünür. K-12: son fazda ek malzeme girilir.
        $this->travelTo($this->day(4, '11:20'));
        $cleaning = $this->open($mehmet, 'H01-M03', materials: ['DET-01', 'DEZ-02'], workOrder: 'IE-2026-1042');
        $this->wait(3);
        $this->runStep($mehmet, $cleaning, 1, 0, seconds: 12);
        $this->wait(40);
        $this->runSteps($mehmet, $cleaning, [2 => 4, 3 => 3, 4 => 3, 5 => 3, 6 => 7, 7 => 4, 8 => 9]);
        $this->workflow->addMaterial($mehmet, $cleaning, $this->lot('DUR-03'));
        $this->runSteps($mehmet, $cleaning, [9 => 4, 10 => 3, 11 => 5, 12 => 3]);

        // İş emri olmayan periyodik temizlik (K-19: alan opsiyonel).
        $this->travelTo($this->day(2, '10:00'));
        $cleaning = $this->open($ahmet, 'H02-M01', helpers: [$ayse], notes: 'Haftalık periyodik temizlik.');
        $this->wait(5);
        $this->runSteps($ahmet, $cleaning, [1 => 4, 2 => 5, 3 => 5, 4 => 3, 5 => 5, 6 => 4]);
    }

    // ---------------------------------------------------------------------------------------
    // Bugün açık olan kayıtlar ("şimdi"den geriye doğru)
    // ---------------------------------------------------------------------------------------

    private function seedToday(): void
    {
        ['ahmet' => $ahmet, 'mehmet' => $mehmet, 'ayse' => $ayse] = $this->users;

        // Bugün sabah tamamlanmış kayıt ("Bugün tamamlanan" sayacı). Çalıştırma saati gece 04:00'ten
        // önceyse bu kayıt bir önceki güne düşer.
        $this->travelTo($this->now->subMinutes(240));
        $done = $this->open($ayse, 'H01-M01', materials: ['DET-01', 'DUR-03'], workOrder: 'IE-2026-1043');
        $this->wait(3);
        $this->runSteps($ayse, $done, [1 => 3, 2 => 6, 3 => 16, 4 => 4, 5 => 11, 6 => 6, 7 => 4]);

        // Duraklatılmış adımı olan kayıt: makine kilitli kalır (K-05), Mehmet serbesttir (K-07).
        $this->travelTo($this->now->subMinutes(150));
        $paused = $this->open($mehmet, 'H02-M02', workOrder: 'IE-2026-1050');
        $this->wait(5);
        $this->runSteps($mehmet, $paused, [1 => 4, 2 => 5]);
        $this->workflow->startStep($mehmet, $this->step($paused, 3));
        $this->wait(14);
        $this->workflow->pauseStep($mehmet, $this->step($paused, 3));

        // Devam eden kayıt: ilk faz ve ikinci fazın iki adımı tamam, 8. adım çalışıyor.
        // Mehmet kendi kaydını duraklattığı için bu adımda Ahmet'e yardımcı olabilir (K-07).
        $this->travelTo($this->now->subMinutes(45));
        $running = $this->open($ahmet, 'H01-M02', materials: ['DET-01', 'DEZ-02', 'DUR-03'], workOrder: 'IE-2026-1055');
        $this->wait(2);
        $this->runSteps($ahmet, $running, [1 => 3, 2 => 4, 3 => 4, 4 => 3, 5 => 3, 6 => 5, 7 => 3]);
        $this->workflow->setWorkers($ahmet, $this->step($running, 8), [$ahmet->id, $mehmet->id]);
        $this->wait(1);
        $this->workflow->startStep($ahmet, $this->step($running, 8));

        // Birkaç dakika önce açılmış, henüz başlatılmamış kayıt (R-20).
        $this->travelTo($this->now->subMinutes(4));
        $this->open($ayse, 'H02-M01', workOrder: 'IE-2026-1056', notes: 'Ürün değişimi: amoksisilinden parasetamole geçiş.');
    }

    // ---------------------------------------------------------------------------------------
    // Yardımcılar
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<User>  $helpers
     * @param  list<string>  $materials  malzeme kodları
     */
    private function open(
        User $owner,
        string $machine,
        CleaningType $type = CleaningType::Planned,
        array $helpers = [],
        array $materials = [],
        ?string $workOrder = null,
        ?string $notes = null,
    ): Cleaning {
        return $this->workflow->open(
            $owner,
            $this->machines[$machine],
            $type,
            array_map(fn (User $helper) => $helper->id, $helpers),
            array_map(fn (string $code) => $this->lot($code), $materials),
            $workOrder !== null ? $this->workOrders[$workOrder] : null,
            $notes,
        );
    }

    /**
     * Malzemenin demo lotu. Son kullanma tarihi çalıştırma anına göre ileridedir (K-14).
     */
    private function lot(string $code, ?string $lotNo = null): MaterialEntry
    {
        [$defaultLot, $months] = match ($code) {
            'DET-01' => ['DT-24118', 10],
            'DEZ-02' => ['DZ-11207', 6],
            'DUR-03' => ['DR-00931', 12],
            'ALK-04' => ['AL-33051', 4],
        };

        return new MaterialEntry(
            $this->materials[$code]->id,
            $lotNo ?? $defaultLot,
            $this->today->addMonthsNoOverflow($months)->endOfMonth()->toDateString(),
        );
    }

    /**
     * Adımları sırayla başlatıp kapatır; her adımdan sonra $gap dakika ara verilir.
     *
     * @param  array<int, int>  $minutes  adım sırası => çalışma süresi (dk)
     */
    private function runSteps(User $actor, Cleaning $cleaning, array $minutes, int $gap = 1): void
    {
        foreach ($minutes as $sequence => $duration) {
            $this->runStep($actor, $cleaning, $sequence, $duration);
            $this->wait($gap);
        }
    }

    private function runStep(User $actor, Cleaning $cleaning, int $sequence, int $minutes, int $seconds = 0, ?string $deviationReason = null): void
    {
        $this->workflow->startStep($actor, $this->step($cleaning, $sequence));
        $this->wait($minutes, $seconds);
        $this->workflow->completeStep($actor, $this->step($cleaning, $sequence), $deviationReason);
    }

    /**
     * Adımı (fazlar arası genel sırasıyla) her seferinde veritabanından taze okur.
     */
    private function step(Cleaning $cleaning, int $sequence): CleaningStep
    {
        return $cleaning->steps()->where('sequence', $sequence)->firstOrFail();
    }

    /**
     * Geçmiş bir günün yerel saati (gösterim saat dilimi), UTC olarak.
     */
    private function day(int $daysAgo, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->today->subDays($daysAgo)->toDateString().' '.$time,
            $this->today->getTimezone(),
        )->utc();
    }

    private function travelTo(CarbonImmutable $moment): void
    {
        CarbonImmutable::setTestNow($moment);
    }

    private function wait(int $minutes, int $seconds = 0): void
    {
        $this->travelTo(CarbonImmutable::now()->addMinutes($minutes)->addSeconds($seconds));
    }
}
