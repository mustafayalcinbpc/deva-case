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

Redis; cache, kilitleme ve eşzamanlı işlemlerin kontrolünde, RabbitMQ ise asenkron işlemlerin yönetiminde kullanılabilecek şekilde konumlandırılmıştır.

## Çözüm Yaklaşımı

Çözümde amaç yalnızca mevcut kağıt formun dijital bir kopyasını oluşturmak değildir.

Süreç; **iş kuralları, yetkilendirme, gerçek zamanlı işlem takibi, süre/efor ölçümü, versiyonlama ve değiştirilemez işlem geçmişi** perspektifinden ele alınmıştır.

UI tarafında ise saha personelinin mümkün olduğunca az işlemle, hangi adımı ne zaman ve nasıl gerçekleştirmesi gerektiğini net şekilde görebilmesi hedeflenmiştir.
