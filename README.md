# Dijital Temizlik Takip Sistemi

## Hızlı Başlangıç

Gereken tek şey Docker (Compose v2). Başka kurulum gerekmez; PHP, Composer, Node, MySQL, Redis ve RabbitMQ container'larda çalışır.

```bash
git clone https://github.com/mustafayalcinbpc/deva-case.git
cd deva-case
docker compose up -d --build
```

İlk açılış birkaç dakika sürer. `app` container'ı şunları kendisi yapar:
- `.env` dosyasını oluşturur;
- bağımlılıkları kurar;
- veritabanı tablolarını oluşturur;
- boş veritabanına demo verisini yükler.

Hazır olduğunu görmek için:

```bash
docker compose logs -f app      # "ready to handle connections" satırı görününce hazırdır
docker compose logs -f vite     # arayüz asset'leri: "VITE ... ready" satırı görününce hazırdır
```

Ardından **http://localhost:8005** adresi açılır. Demo hesaplarının hepsinin şifresi `1234`; giriş sayfasında da listelenirler:

| Hesap | Rol |
|---|---|
| `operator1@demo.test` | Operatör (Ahmet Yılmaz) |
| `operator2@demo.test`, `operator3@demo.test` | Operatör |
| `yonetici1@demo.test` | Yönetici (yönetim ekranları ve raporlar) |
| `operator4@demo.test` | Pasif personel (giriş yapamaz) |

Sık kullanılan komutlar:

```bash
docker compose exec app php artisan test                  # testler (ayrı test veritabanında)
docker compose exec app php artisan migrate:fresh --seed  # demo verisini sıfırdan yükle (veriyi siler)
docker compose down                                       # durdur (veri korunur; -v ile veri de silinir)
```

| Servis | Adres |
|---|---|
| Uygulama | http://localhost:8005 |
| RabbitMQ yönetim paneli | http://localhost:15672 (`temizlik` / `secret`) |
| MySQL | `localhost:33060` (`temizlik` / `secret`) |

Portlar başka bir uygulamayla çakışırsa `APP_PORT`, `FORWARD_DB_PORT` ve `FORWARD_RABBITMQ_UI_PORT` ortam değişkenleriyle değiştirilebilir (ör. `APP_PORT=8090 docker compose up -d`). Vite geliştirme sunucusu 5173 portunu kullanır; bu port boş olmalıdır. Ayrıntılar aşağıdaki **Kurulum** bölümünde, production kurulumu **Production** bölümündedir.

## Proje Hakkında

Bu proje, üretim ortamındaki makine temizlik süreçlerinin kağıt formlardan dijital ortama taşınması amacıyla geliştirilmiş bir demo uygulamasıdır.

Temel amaç yalnızca temizlik kaydının oluşturulması değil; sürecin **kim tarafından, hangi adımlarla, ne kadar sürede ve hangi kaynaklar kullanılarak gerçekleştirildiğinin geriye dönük olarak izlenebilir ve denetlenebilir olmasıdır.**

## Teknik Yaklaşım

* Laravel 13 (PHP 8.4)
* MySQL 8.4
* Redis
* RabbitMQ
* Laravel Queue
* AdminLTE 4 (Bootstrap 5.3), Vite + Sass
* dompdf (denetim raporu PDF'i)
* Docker / Docker Compose

Uygulama Docker üzerinde çalışacak şekilde yapılandırılmıştır.

## Temel Fonksiyonlar

* Tesis / hat / makine tanımları
* Temizlik prosedürü ve versiyon yönetimi
* Faz ve checklist adımlarının yönetimi
* Temizlik kaydı oluşturma
* Adım bazlı başlatma / tamamlama
* Adımlarda görev alan personellerin takibi
* Malzeme lotları (lot no, son kullanma tarihi) ve prosedürün beklediği malzemeler
* Gerçek çalışma süresi ve insan eforunun ayrı hesaplanması
* Üretim iş emri ile ilişkilendirme; üretim iş emri durumu (planlandı, üretimde, tamamlandı)
* Temizlik planları (periyodik ya da üretim iş emri tamamlanınca) ve yapılması gereken temizlikler
* Yetki bazlı kullanıcı işlemleri
* Açık ve tamamlanmış temizliklerin takibi
* Temizlik geçmişi ve detaylı kayıt görüntüleme
* Aktivite / işlem geçmişi (temizlik kayıtları için hash zincirli olay kaydı, tanımlar için değişiklik günlüğü)
* Raporlama ve filtreleme (süre ve efor, sapmalar, malzeme izlenebilirliği, denetim raporu, PDF/CSV)
* Bildirimler (RabbitMQ kuyruğunda işlenen domain olayları)
* Denetim kontrol noktaları ve bütünlük doğrulaması

## Kritik İş Kuralları

Sistem içerisinde özellikle aşağıdaki kuralların çalışır şekilde ele alınması hedeflenmiştir:

* Aynı makinede eş zamanlı birden fazla aktif temizlik başlatılamaması
* Bir personelin aynı anda birden fazla temizlikte görev alamaması
* Yalnızca kaydın sahibi veya ilgili adımda görevli personelin adımı ilerletebilmesi
* Kayıt oluşturma ile gerçek işe başlama zamanının birbirinden ayrılması
* Adım başlangıç ve bitiş zamanlarının sistem tarafından oluşturulması
* Faz minimum sürelerinin kontrol edilmesi
* Zorunlu malzemelerin girilmeden sürecin ilerletilememesi
* Yarım kalan işlerin otomatik olarak kapatılmaması
* Geçmiş temizliklerin, oluşturuldukları dönemde geçerli olan prosedür versiyonuyla korunması
* Gerçekleşmiş işlemlerin geriye dönük olarak değiştirilmemesi
* Tüm kritik işlemlerin izlenebilir olması

## Süre ve Efor Takibi

Sistem, bir işlemin geçen süresi ile insan eforunu birbirinden ayrı değerlendirir.

Örneğin bir adım 10 dakika sürmüş ve bu adımda 2 personel çalışmışsa:

* İş süresi: 10 dakika
* İnsan eforu: 20 dakika

şeklinde hesaplanır.

## İzlenebilirlik

Her temizlik kaydı üzerinden;

* Kim başlattı?
* Hangi personeller hangi adımlarda görev aldı?
* Hangi adım ne zaman başladı ve tamamlandı?
* Hangi malzemeler kullanıldı?
* Hangi prosedür versiyonu kullanıldı?
* Toplam süre ve efor ne kadar?
* Kim, ne zaman, hangi işlemi gerçekleştirdi?

sorularının cevaplanabilmesi hedeflenmiştir.

Kritik kayıtların sonradan değiştirilmesini engellemek amacıyla işlem geçmişi ayrıca tutulmaktadır.

## Mimari

Uygulama Docker ortamında çalışacak şekilde tasarlanmıştır.

```text
Browser
   │
   ▼
nginx ─▶ Laravel (php-fpm) + AdminLTE / Nocturne teması
            │
            ├── MySQL     kayıtlar, olay zinciri, kontrol noktaları (trigger'larla korunur)
            ├── Redis     cache, oturum, zamanlanmış görev kilitleri
            └── RabbitMQ ─▶ Queue Worker   bildirimler, PDF/CSV dışa aktarma
                              Scheduler    süresi dolan kayıtlar, kontrol noktası, doğrulama
```

Redis cache ve oturumlar için, RabbitMQ kuyruk (Laravel Queue) için kullanılır. Eşzamanlılık garantisi (aynı makinede iki temizlik, aynı kişinin iki adımda çalışması) Redis'te değil MySQL'deki unique index'lerdedir; ayrıntı aşağıda **Durum Yönetimi** bölümünde.

## Çözüm Yaklaşımı

Çözümde amaç yalnızca mevcut kağıt formun dijital bir kopyasını oluşturmak değildir.

Süreç; **iş kuralları, yetkilendirme, gerçek zamanlı işlem takibi, süre/efor ölçümü, versiyonlama ve değiştirilemez işlem geçmişi** perspektifinden ele alınmıştır.

UI tarafında ise saha personelinin mümkün olduğunca az işlemle, hangi adımı ne zaman ve nasıl gerçekleştirmesi gerektiğini net şekilde görebilmesi hedeflenmiştir.

## Kurulum

Gereken tek şey Docker (Compose v2).

```bash
docker compose up -d --build
```

İlk açılışta `app` container'ı sırasıyla:

1. `.env` dosyasını `.env.example`'dan oluşturur, `composer install` çalıştırır ve uygulama anahtarını üretir.
2. MySQL hazır olunca migration'ları uygular.
3. **Veritabanı boşsa demo verisini yükler** (`php artisan demo:seed`).

`vite` container'ı `npm install` çalıştırıp arayüz için geliştirme sunucusunu başlatır. Sonraki açılışlarda veritabanında kayıt olduğu için demo verisi yeniden yüklenmez.

Demo verisi bütün süreci kapsar:
- **Tanımlar:** tesis, iki hat, yedi makine (biri kullanımdan kaldırılmış), dört prosedür (birinin v1 ve v2 versiyonu, her biri beklediği malzemelerle), malzeme kataloğu ve lotları (biri SKT'si geçmiş, biri kullanımdan kaldırılmış), farklı durumlarda üretim iş emirleri, beş temizlik planı ve beş kullanıcı (biri pasif).
- **Görevler:** biri gecikmiş, biri kayda bağlı, biri tamamlanmış temizlik görevi; ileride yapılacak iki periyodik görev ve üretimdeki emrin tamamlanmasını bekleyen bir görev.
- **Temizlik kayıtları** (17 kayıt, her durumdan):
  - başlamamış, devam eden ve duraklatılmış kayıtlar;
  - bugün ve geçmiş haftalarda tamamlanmış temizlikler: adım bazında farklı görevliler, duraklatma, adım sırasında görevli değişikliği, minimum süre altında gerekçeyle kapanan faz, geçersiz kılınmış malzeme, plansız müdahale;
  - iptal edilmiş ve süresi dolmuş kayıtlar.
- **Durum makinesinden üretim:** Bütün kayıtlar gerçek durum makinesi üzerinden üretilir ve olay zincirleri doğrulanır.

Demo verisini sıfırdan yeniden yüklemek için (veritabanını sıfırlar):

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Otomatik yüklemeyi kapatmak için `.env` dosyasında `DEMO_SEED=false` yazılır. Belirtilmezse yalnızca `local` ortamda açıktır.

Giriş sayfası `local` ortamda demo hesaplarını listeler (`config/demo.php`); hepsinin şifresi `1234`. Adresler unvana göre numaralıdır: operatörler `operator1@demo.test` … `operator4@demo.test` (`operator4` pasif personel), yönetici `yonetici1@demo.test`. Demo verisindeki "başlamamış" kayıt, zamanlayıcı tarafından yaklaşık 30 dakika sonra "süresi doldu" durumuna alınır (K-06); bu kaydı yeniden görmek için demo verisini yukarıdaki komutla yeniden yüklemek yeterlidir.

| Servis | Adres |
|---|---|
| Uygulama (nginx) | http://localhost:8005 |
| Vite geliştirme sunucusu | http://localhost:5173 (sayfa bunu kendisi kullanır) |
| RabbitMQ yönetim paneli | http://localhost:15672 (`temizlik` / `secret`) |
| MySQL | `localhost:33060` (`temizlik` / `secret`) |

`queue` container'ı kuyruğu işler, `scheduler` container'ı zamanlanmış görevleri çalıştırır (ör. her dakika süresi dolan kayıtları kapatan `cleanings:expire-stale`, her dakika görevi olmayan planların sıradaki görevini açan ve geciken görevleri bildiren `cleaning:generate-tasks`).

## Testler

```bash
docker compose exec app php artisan test
```

Testler ayrı bir veritabanında (`temizlik_test`) çalışır. Kurallar MySQL'deki generated column, unique index ve trigger'lara dayandığı için testler SQLite'ta değil MySQL'de çalışır.

## Durum Yönetimi

İş kuralları ve verilen kararlar `docs/is-gereksinimleri.md` dosyasındadır (gereksinimler `R-xx`, kararlar `K-xx`). Kodda bu numaralara referans verilir.

Temizlik kaydı, faz ve adımın durumları ayrı tutulur. İzin verilen geçişler enum'larda tanımlıdır (`app/Enums`); durum yalnızca bu tablodaki geçişlerle değişir.

```
Temizlik:  BAŞLAMADI ──ilk adım başlar──▶ DEVAM EDİYOR ──son faz tamamlanır──▶ TAMAMLANDI
              ├─ 30 dk adım başlamadı (sistem) ─▶ SÜRESİ DOLDU
              └─ sahibi / yönetici ─▶ İPTAL ◀─ yönetici ─┘

Faz:       BEKLİYOR ─▶ DEVAM EDİYOR ─▶ TAMAMLANDI
Adım:      BEKLİYOR ─▶ ÇALIŞIYOR ⇄ DURAKLATILDI
                          └─▶ TAMAMLANDI
```

Bütün geçişler tek bir servisten geçer: `app/Services/Cleaning/CleaningWorkflow.php`. Her işlem tek bir transaction'dır. İşlem kaydın satırını kilitleyerek başlar, kuralları sabit bir sırayla kontrol eder, durumu değiştirir ve olayı kaydeder. Kural ihlalinde `CleaningRuleViolation` fırlatılır ve hiçbir değişiklik kalıcı olmaz.

**Kayıt açmak işe başlamak değildir.** Süre ve makine kilidi ilk adım başlatılınca başlar. Hiç başlatılmayan kayıt 30 dakika sonra sistem tarafından "süresi doldu" durumuna alınır. Başlamış iş hiçbir zaman otomatik kapanmaz.

**Süre ve efor.** Bir adımın kesintisiz çalışılan her bölümü bir *çalışma dilimi*dir. Duraklatma ya da görevli değişikliği dilimi kapatır.
- Net süre = dilim sürelerinin toplamı
- Brüt süre = ilk dilim başlangıcı → son dilim bitişi
- Efor = Σ (dilim süresi × dilimdeki kişi sayısı)

Faz minimum süresi, fazın ayarına göre net ya da brüt süreyle kontrol edilir. Minimumun altında kalan faz ancak gerekçe yazılarak kapanır ve sapma olarak işaretlenir.

**Eşzamanlılık veritabanında garanti edilir**, yalnızca ekranda değil:
- Kayıt devam ederken makine id'sini alan bir generated column üzerindeki unique index, aynı makinede ikinci bir temizliğin başlamasını engeller.
- Açık çalışma dilimindeki kişi id'si üzerindeki unique index, bir kişinin aynı anda iki adımda çalışmasını engeller.

İki kişi aynı anda denese bile biri başarılı olur, diğeri "makine meşgul" ya da "personel meşgul" hatası alır.

**Değiştirilemezlik.**
- Bir kez dolan alanlar (sahip, başlangıç/bitiş zamanları, ölçülen süreler) model seviyesinde değiştirilemez, kayıtlar silinemez.
- Zamanlar her zaman sunucudan alınır.
- Her işlem `cleaning_events` tablosuna yazılır. Bu tablo MySQL trigger'larıyla UPDATE/DELETE'e kapalıdır. Her olay bir önceki olayın SHA-256 hash'ini içerir; `CleaningEventRecorder::verify()` zinciri baştan hesaplayarak sonradan yapılan değişikliği tespit eder.
- Hash zinciri tek başına, veritabanına doğrudan yazabilen birinin zinciri baştan hesaplamasını ya da sona sahte olay eklemesini fark edemez. Bunun için saatlik **kontrol noktaları** vardır (aşağıda).

## Ekranlar

- **Gösterge paneli:** başlamamış, devam eden, bugün tamamlanan kayıtlar ve minimum süre altında kalan fazlar; açık kayıtlar tablosu. Kullanıcının sorumlu ya da görevli olduğu kayıtlar "Bana ait" olarak işaretlenir.
- **Yapılması gereken temizlikler (gösterge paneli):** görev planlandığı an burada görünür (K-24). Vakti gelenler üstte, son tarih sırasıyla; son tarihi (vakit + planın gecikme toleransı) geçen "Gecikti" olarak işaretlenir. Vakti gelmeyenler "İleride yapılacak" altında: periyodik görevde vakit, üretim iş emri tetikli görevde "emir tamamlanınca" yazar ve vakti gelene kadar kayıt açılamaz. "Kaydı aç" görevden kayıt açar; yönetici görevi gerekçeyle iptal edebilir (K-21, K-23).
- **Temizlik kayıtları:** bütün kayıtlar; duruma, makineye ve "bana ait" olmaya göre filtrelenir. Liste sadedir: kayıt (altında tür ve saha referansı), makine (altında adı), sorumlu, durum, açılış ve net süre. Başlangıç ve kapanış zamanları kayıt detayındadır.
- **Yeni kayıt:** yalnızca kullanımda olan ve geçerli prosedürü bulunan makineler seçilebilir. Seçilen makinenin prosedürü (fazlar, minimum süreler, beklenen malzemeler) ve makinede başlamamış kayıt varsa uyarı gösterilir.
  - Prosedürün beklediği malzemeler satır olarak gelir; operatör her biri için lot seçer (yalnızca kullanımdaki ve SKT'si geçmemiş lotlar). Lot no ve SKT elle yazılmaz (K-14). Ek malzeme eklenebilir.
  - Görevden gelindiyse makine ve tür (planlı) kilitli, üretim iş emri görevin sonraki emriyle dolu gelir.
- **Kayıt detayı (sahadaki ekran):**
  - Bölümler sekmelerdedir: **Şimdi**, **Adımlar**, **Özet**, **Malzemeler**, **Olay geçmişi**. Her sekme yalnızca kendi bölümünü gösterir; adresteki çapa (`#materials`, `#step-12`) ilgili sekmeyi açar, yenilemede aynı sekme kalır.
  - **Şimdi** sekmesinde güncel adım, büyük aksiyon butonları (başlat, duraklat, devam et, tamamla) ve canlı sayan çalışma süresi bulunur. Butonlar yalnızca kaydın sorumlusuna ve adımın görevlilerine görünür.
  - Fazı minimum süresinin altında kapatırken gerekçe alanı açılır.
  - Sağ sütundaki **İlerleme** göstergesi kargo takibindeki gibi kayıt açılışından kapanışa fazları ve adımları noktadan noktaya gösterir: tamamlanan, devam eden, duraklatılan, gelecek ve (iptal/süre dolumunda) yapılmayan adımlar renk, simge ve metinle ayrılır. Adıma tıklamak Adımlar sekmesinde o adımı açar. İptal formu da sağ sütundadır.
  - Malzemeler sekmesinde prosedürün beklediği malzemeler ve girilen lotlar, eksik zorunlu malzemeler, lot seçerek ekleme ve gerekçeyle geçersiz kılma bulunur. Olay geçmişi ile bütünlük doğrulaması kendi sekmesindedir.

Bütün aksiyonlar `CleaningWorkflow` üzerinden çalışır. Kural ihlalinde kullanıcı aynı sayfaya mesajla döner; ekranlar kural tekrarlamaz, yalnızca hangi butonun gösterileceğine `CleaningPermissions` ile karar verir.

## Yönetim (yönetici)

Yönetim ekranları yalnızca yöneticiye açıktır (`manage-definitions`); operatör tanımlara müdahale edemez (R-42).

- **Tesis, hat ve makine:**
  - Kayıt numarasında geçen kodlar, o yere ait ilk temizlik kaydı açıldıktan sonra değiştirilemez (K-17); adlar değiştirilebilir.
  - Açık kaydı olan makine kullanımdan kaldırılamaz (K-16). Kaldırılan makine silinmez, geçmişte görünmeye devam eder.
- **Prosedürler:**
  - Prosedür sayfasında Versiyonlar, Özet, Kullanan makineler ve Değişiklik geçmişi sekmelerdedir; her sekme yalnızca kendi bölümünü gösterir (kayıt detayıyla aynı sekme yapısı).
  - Taslak hazırlanır, fazlar ve adımlar düzenlenir (minimum süre, adımlar arası boşluk ayarı, açıklama, fotoğraf/video), sonra hemen ya da ileri bir tarihte yayımlanır.
  - Taslakta beklenen malzemeler listelenir (zorunlu ya da isteğe bağlı, sıralı); "malzeme zorunlu" bilgisi bu listeden türetilir (K-13).
  - Yayımlanmış versiyon ve fazları/adımları/malzeme listesi model seviyesinde değiştirilemez (K-15). Açık kayıtlar açıldıkları versiyonla devam eder.
- **Malzemeler ve lotlar:** Malzeme silinmez, kullanımdan kaldırılır; kaldırılan malzeme yeni kayıtlarda seçilemez. Malzeme sayfasında lotlar tanımlanır (lot no, SKT, giriş tarihi); lot silinmez, kullanımdan kaldırılır. Kayıtta kullanılmış lotun numarası kilitlenir, SKT düzeltilebilir; kayıtlar seçildikleri andaki kopyayı taşır (K-14).
- **Üretim iş emirleri:** Bir hatta ya da makineye bağlanabilir; ürün, planlanan başlangıç/bitiş ve durum taşır. "Üretime al" ve "Tamamla" ERP'nin yerine geçer; tamamlanma temizlik planlarının tetiğidir (K-19). Listede satırda yalnızca sıradaki adım görünür; açıklama kodun, tamamlanma anı durumun altındadır. Tamamlama onayı uygulamanın onay penceresinde sorulur.
- **Temizlik planları:** Makine bazında periyodik ("7 günde bir") ya da "üretim iş emri tamamlanınca" kuralı ve gecikme toleransı (saat). Plan görev üretir; aynı anda tek etkin görevi olur. Sıradaki görev hemen açılır: periyodik planda vakti bir aralık sonra, tetikli planda makinedeki emir tamamlanınca gelir. Listede etkin görev ve vakti görünür (K-20, K-24).
- **Kullanıcılar:**
  - Pasife alınan kullanıcı açık oturumundan da çıkarılır.
  - Açık kayıtları varsa listelenir; yönetici bu kayıtları "personel ayrıldı" gerekçesiyle iptal edebilir (K-08).
  - Yönetici kendini pasife alamaz.
- **Değişiklik günlüğü:** Yönetimdeki her değişiklik kaydedilir: kim, ne zaman, neyi hangi değerden hangi değere değiştirdi. Şifre değerleri yazılmaz. Günlük, olay tablosu gibi veritabanında değiştirilemez ve silinemez.

## Raporlar (yönetici)

- **Süre ve efor:** Makine bazında tamamlanan temizlik sayısı; ortalama, en kısa ve en uzun net süre; brüt süre ve insan eforu. Bir makine seçilince faz bazında ortalama süre ve minimum sürenin altında kalma sayısı görünür. "Bu makinenin temizliği 30 dakika mı sürüyor, 50 mi; hangi faz uzun?" sorusunun cevabı buradadır.
- **Sapmalar:** Minimum süre altında kapanan fazlar (gerekçe, kim, ne zaman) ve eşikten uzun çalışma dilimleri.
- **Malzeme izlenebilirliği:** Malzeme ya da lot numarasıyla geriye dönük arama (R-10); arama lot kaydının güncel numarasını da kapsar, sonradan düzeltilen ya da kullanımdan kaldırılan lot satırda belirtilir.
- **Denetim raporu:** Bir kaydın bütün hikâyesi (R-45) ve olay zincirinin doğrulama sonucu. Yazdırılabilir; PDF olarak da hazırlanabilir.
- **Dışa aktarma:** PDF ve CSV dosyaları kuyrukta üretilir; hazır olunca isteyene bildirim gider.

Filtrelerdeki tarihler Türkiye saatiyle yorumlanır.

## Kuyruk ve Bildirimler (RabbitMQ)

Durum makinesi önemli geçişlerde domain olayları yayımlar: minimum süre altı faz, temizlik tamamlandı, iptal, süre dolumu. Olaylar transaction commit edildikten sonra yayımlanır; reddedilen bir işlem hiçbir olay üretmez.

Olayları dinleyen işler RabbitMQ kuyruğunda çalışır ve ilgili kişilere bildirim yazar:
- minimum süre altı faz → bütün aktif yöneticilere;
- başkası tarafından iptal → kayıt sahibine;
- süre dolumu → kayıt sahibine;
- geciken temizlik görevi → bütün aktif yöneticilere, görev başına bir kez (`cleaning:generate-tasks`).

Üretim iş emri tamamlanınca yayımlanan olayı da kuyruktaki bir dinleyici işler: tetikli planlarda bekleyen görevin vakti gelir (görev yoksa vakti gelmiş görev açılır).

Rapor dışa aktarmaları da aynı kuyrukta üretilir. Bildirimler üst bardaki zilde ve `/notifications` sayfasında görünür.

## Denetim Kontrol Noktaları

`audit:checkpoint` komutu saatte bir çalışır:
- Her kaydın zincir başını tek bir özete (digest) bağlar.
- Bu özeti bir önceki kontrol noktasına zincirler.
- Kontrol noktasını hem değiştirilemez `audit_checkpoints` tablosuna hem `storage/logs/audit-checkpoints.log` dosyasına yazar.

**Log dosyası sunucu dışına taşınmalıdır.** Taşındığı anda dış çapa (anchor) olur: veritabanına doğrudan yazabilen biri geçmişi yeniden hesaplasa bile dışarıdaki kopyayla tutmaz.

```bash
docker compose exec app php artisan audit:checkpoint
docker compose exec app php artisan audit:verify            # --log ya da --log-path=/dis/kopya.log ile log karşılaştırması
```

`audit:verify` şunları kontrol eder ve sorun bulursa sıfırdan farklı kodla çıkar:
- her kaydın zinciri;
- kontrol noktalarının kendi zinciri;
- her kontrol noktasının bugünkü verilerden yeniden hesaplanan özeti;
- kontrol noktasından sonra eklenmiş ama tarihi geriye atılmış olaylar;
- istenirse log kopyası.

Her gece zamanlanmış olarak çalışır ve sonucu loglar. Son kontrol noktasından sonraki olaylar, bir sonraki kontrol noktasına kadar yalnızca kayıt bazındaki hash zinciriyle korunur.

## Production

`compose.prod.yaml` ayrı bir production kurulumudur:
- Kod ve bağımlılıklar imaja gömülüdür (`composer --no-dev`, derlenmiş asset'ler); Vite sunucusu yoktur.
- `APP_ENV=production`, `APP_DEBUG=false`, config/route/view önbelleklidir.
- Demo verisi varsayılan olarak kapalıdır.

```bash
cp docker/production.env.example .env.production   # APP_KEY, DB_PASSWORD, RABBITMQ_PASSWORD doldurulur
echo "base64:$(openssl rand -base64 32)"            # APP_KEY: bir kez üretilir ve saklanır
docker compose -f compose.prod.yaml --env-file .env.production up -d --build
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan about
docker compose -f compose.prod.yaml --env-file .env.production down   # -v verileri de siler
```

`APP_KEY`, `DB_PASSWORD` ve `RABBITMQ_PASSWORD` zorunludur; eksikse compose başlamaz. Gerçek anahtar repoya konmaz.

## Arayüz ve Tema

Arayüz AdminLTE 4 (Bootstrap 5.3) üzerine kuruludur ve Vite + Sass ile derlenir.

**Görünüm: "Nocturne" tasarım dili.**
- Açık renkli sidebar ve vurgulu menü bağlantıları.
- Sol menü gruplar halindedir: Gösterge Paneli, Temizlik, Planlama, Tanımlar, Raporlar, Yönetim (operatör yalnızca ilk ikisini görür). Gruplar aşağı doğru açılır; bulunulan sayfanın grubu açık gelir. Menü geniş ekranda yalnızca ana ikonlarla durur; fare üzerine gelince (ya da klavyeyle içine girilince) içeriği itmeden açılır, çıkınca ikonlara döner. Üst bardaki menü düğmesi menüyü kalıcı olarak açar. Telefonda menü düğmeyle açılan kenar çekmecesidir.
- Üst barda breadcrumb, tema düğmesi ve bildirim zili.
- KPI kartları, etiket rozetleri.
- Tablolar: zeminli başlık satırı, satırlar arasında tam genişlikte ince çizgi, üzerine gelince vurgu tonunda zemin. Hücrede ikincil bilgi ana bilginin altında soluk yazılır (`.cell-sub`); böylece listeler az sütunla kalır.
- Onay gerektiren işlemler (iptal, kaldırma, yayımlama, tamamlama) tarayıcının kutusu yerine ortak onay penceresinde sorulur (`<x-confirm-modal>`).
- Inter yazı tipi.
- Listelerde tarihler kısa biçimdedir: "10 Ekim 16:34" (SKT gibi saatsiz tarihler "31 Mayıs 2027"); yıl yalnızca bu yıldan değilse yazılır, tam zaman üzerine gelince görünür (`<x-datetime format="list">`). Kayıt detayı, olay geçmişi ve denetim raporu saniyeli tam zamanı gösterir.

**Açık ve koyu tema.** İkisi de yalnızca token değerleriyle değişir. Seçim tarayıcıda saklanır; ilk açılışta işletim sisteminin tercihi kullanılır. Tema, sayfa çizilmeden önce uygulandığı için yanıp sönme olmaz.

Görünüm tamamen `resources/scss/theme/` altından yönetilir. View'larda satır içi stil yoktur; yeniden tasarım yalnızca bu dosyalara dokunur.

| Dosya | Ne için |
|---|---|
| `theme/_tokens.scss` | Tasarım token'ları (renk, gölge, köşe, boşluk, durum renkleri). Açık tema varsayılan, koyu tema `[data-bs-theme="dark"]` altında. |
| `theme/_variables.scss` | Bootstrap/AdminLTE'nin derleme zamanı değişkenleri (vurgu rengi, yazı tipi, köşeler). |
| `theme/_base.scss`, `theme/_nocturne.scss` | Bootstrap değişkenlerinin token'lara bağlanması ve Nocturne yardımcıları (`tag`, `seg`, `kpi-card`, `avatar`, `hr` …). |
| `theme/bootstrap/*` | Butonlar, kartlar, formlar, tablolar, rozetler, sekmeler, uyarılar: bütün ekranlara global uygulanır. |
| `theme/_shell.scss`, `theme/_notifications.scss` | Sidebar, üst bar, kullanıcı bloğu, bildirim zili. |
| `theme/_components.scss` | Uygulamaya özel bileşenler (durum rozetleri, "Bana ait" işareti …). |
| `theme/pages/*` | Sayfalara özel yerleşim (gösterge paneli, kayıt formu, kayıt detayı, yönetim ekranları). |
| `theme/_motion.scss` | Geçiş ve animasyonlar; `prefers-reduced-motion` tercihine uyar. |

`vite` container'ı çalışırken bu dosyalarda yapılan değişiklikler sayfa yenilenmeden yansır. Üretim derlemesi:

```bash
docker compose run --rm vite npm run build
```

`vite` container'ı durdurulduğunda sayfalar asset bulamıyorsa geride `public/hot` dosyası kalmıştır; silinmesi yeterlidir.
