# Dijital Temizlik Takip Sistemi

## Proje Hakkında

Bu proje, üretim ortamındaki makine temizlik süreçlerinin kağıt formlardan dijital ortama taşınması amacıyla geliştirilmiş bir demo uygulamasıdır.

Temel amaç yalnızca temizlik kaydının oluşturulması değil; sürecin **kim tarafından, hangi adımlarla, ne kadar sürede ve hangi kaynaklar kullanılarak gerçekleştirildiğinin geriye dönük olarak izlenebilir ve denetlenebilir olmasıdır.**

## Teknik Yaklaşım

* Laravel
* MySQL
* Redis
* RabbitMQ
* Laravel Queue
* AdminLTE
* Docker / Docker Compose

Uygulama Docker üzerinde çalışacak şekilde yapılandırılmıştır.

## Temel Fonksiyonlar

* Tesis / hat / makine tanımları
* Temizlik prosedürü ve versiyon yönetimi
* Faz ve checklist adımlarının yönetimi
* Temizlik kaydı oluşturma
* Adım bazlı başlatma / tamamlama
* Adımlarda görev alan personellerin takibi
* Malzeme, lot ve son kullanma tarihi takibi
* Gerçek çalışma süresi ve insan eforunun ayrı hesaplanması
* Üretim iş emri ile ilişkilendirme
* Yetki bazlı kullanıcı işlemleri
* Açık ve tamamlanmış temizliklerin takibi
* Temizlik geçmişi ve detaylı kayıt görüntüleme
* Aktivite / işlem geçmişi
* Raporlama ve filtreleme

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
Laravel + AdminLTE
   │
   ├── MySQL
   ├── Redis
   │
   └── RabbitMQ
          │
          ▼
     Queue Worker
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

İlk açılışta `app` container'ı `.env` dosyasını `.env.example`'dan oluşturur, `composer install` çalıştırır, uygulama anahtarını üretir ve migration'ları uygular. `vite` container'ı `npm install` çalıştırıp arayüz için geliştirme sunucusunu başlatır.

Demo verisini yüklemek için (veritabanını sıfırlar):

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Giriş sayfası `local` ortamda demo hesaplarını listeler; hepsinin şifresi `password`. Operatör olarak `ahmet@demo.test`, yönetici olarak `yonetici@demo.test` ile girilebilir. Demo verisindeki "başlamamış" kayıt, zamanlayıcı tarafından yaklaşık 30 dakika sonra "süresi doldu" durumuna alınır (K-06); demo öncesi yeniden yüklemek yeterlidir.

| Servis | Adres |
|---|---|
| Uygulama (nginx) | http://localhost:8080 |
| Vite geliştirme sunucusu | http://localhost:5173 (sayfa bunu kendisi kullanır) |
| RabbitMQ yönetim paneli | http://localhost:15672 (`temizlik` / `secret`) |
| MySQL | `localhost:33060` (`temizlik` / `secret`) |

`queue` container'ı kuyruğu işler, `scheduler` container'ı zamanlanmış görevleri çalıştırır (ör. her dakika süresi dolan kayıtları kapatan `cleanings:expire-stale`).

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

## Arayüz ve Tema

Arayüz AdminLTE 4 (Bootstrap 5.3) üzerine kuruludur ve Vite + Sass ile derlenir. Görünüm (renkler, yazı tipi, köşeler, hareketler) tamamen `resources/scss/theme/` altından yönetilir. View'larda satır içi stil yoktur; yeniden tasarım yalnızca bu dosyalara dokunur.

| Dosya | Ne için |
|---|---|
| `theme/_variables.scss` | Derleme zamanı değişkenleri: ana palet, yazı tipi, köşe yuvarlaklığı, sidebar genişliği, AdminLTE geçiş süresi. Butonlar, formlar, kartlar bunlardan türer. |
| `theme/_tokens.scss` | Çalışma zamanı CSS değişkenleri (`--app-*`): durum renkleri, "Bana ait" vurgusu, gölgeler, hareket süreleri. Açık ve koyu tema ayrı. |
| `theme/_components.scss` | Uygulamaya özel bileşenler: durum rozetleri, gösterge paneli sayaçları, tablo satır vurgusu. |
| `theme/_motion.scss` | Geçiş ve animasyonlar; `prefers-reduced-motion` tercihine uyar. |

`vite` container'ı çalışırken bu dosyalarda yapılan değişiklikler sayfa yenilenmeden yansır. Üretim derlemesi:

```bash
docker compose run --rm vite npm run build
```

`vite` container'ı durdurulduğunda sayfalar asset bulamıyorsa geride `public/hot` dosyası kalmıştır; silinmesi yeterlidir.

