# Dijital Temizlik Takip Sistemi — İş Gereksinimleri

> **Kaynak:** `Dijital_Temizlik_Takip_Projesi_Is_Birimi_Anlatim_Senaryosu.docx` (iş birimi temsilcisinin anlatımı).
>
> Bu dosya o dokümanın yapılandırılmış hâlidir. 1–14. bölümler yalnızca dokümanda söylenenleri içerir; tasarım kararı veya varsayım eklenmemiştir. Örnekler dokümandaki örneklerdir. Dokümanın net olmadığı noktalar için verdiğimiz kararlar en sondaki **Kararlar** bölümündedir. Çelişki olursa docx esas alınır.
>
> Gereksinimler `R-xx` olarak numaralanmıştır; tasarım ve kodda bu numaralarla referans verilebilir.

---

## 1. Problem ve Amaç

- Makine temizliği iki durumda gerekir: makinede üretilen ürün değiştiğinde ve belirli aralıklarla (periyodik).
- Önemli olan yalnızca temizliğin yapılmış olması değil; denetimde **kim, ne zaman, hangi adımlarla, hangi malzemelerle, ne kadar sürede** yaptığını gösterebilmek.
- **Bugünkü durum:** Kağıt form. Operatör temizliği yapıyor, sonra forma yazıyor.
  - Adımın o anda mı yapıldığı yoksa sonradan mı yazıldığı ayırt edilemiyor (operatör tüm temizlik bittikten sonra formu doldurabiliyor).
  - Süre ölçülemiyor (bir makinenin temizliği normalde 30 dk mı, sürekli 50 dk mı?). Hangi aşamanın uzun sürdüğü görülemiyor.
  - Sonuç: hem denetim hem üretim planlama için ölçülebilir veri yok.
- **Hedef:** Kağıt formu bilgisayara taşımak değil, temizlik sürecini gerçekten dijital olarak takip etmek.

## 2. Sözlük

| Terim | Anlamı |
|---|---|
| Tesis → Hat → Makine | Temizliğin yapıldığı yerin hiyerarşisi |
| Temizlik prosedürü / form tipi / checklist dokümanı | Bir makine için kullanılabilecek, önceden tanımlı temizlik şablonu. Fazlardan oluşur, versiyonu vardır |
| Faz | Prosedürün aşaması. İçinde sıralı adımlar ve bir minimum süre vardır |
| Adım (işlem) | Fazın içindeki tek bir iş. Açıklaması ve isteğe bağlı fotoğraf/video'su olabilir |
| Malzeme | Temizlikte kullanılan malzeme: kod, parti/lot, son kullanma tarihi |
| Temizlik kaydı | Bir temizliğin gerçekleşmesine ait kayıt |
| Kayıt sahibi (sorumlu) | Kaydı açan kişi |
| Yardımcı personel / görevli | Bir adımda çalışan, kayıt sahibi dışındaki kişiler. Adımdan adıma değişebilir |
| Saha defteri referansı | Normal (planlı) temizliklerde sahadaki fiziksel deftere verilen referans |
| Plansız müdahale | Üretim sırasında beklenmeyen bir durum nedeniyle yapılan acil temizlik. Saha defterine işlenmez |
| Üretim iş emri | Temizliğin ilişkili olduğu üretim işi |
| Süre | Bir işin başlangıcı ile bitişi arasında geçen zaman |
| Efor (insan eforu) | Harcanan toplam insan emeği: süre × o sırada çalışan kişi sayısı |
| Operatör | Sahada temizliği yapan kullanıcı |
| Yönetici | Tanımları yöneten ve raporlayan kullanıcı |

Kavramsal ilişki (dokümandan çıkarım):

```
Tesis ─< Hat ─< Makine >── Prosedür   (her makinenin tek geçerli prosedürü var, K-18)
Prosedür (versiyonlu) ─< Faz (sıra, min. süre) ─< Adım (sıra, açıklama, foto/video)

Temizlik kaydı ── tesis, hat, makine, prosedür versiyonu, sahip,
                  malzemeler (kod, lot, SKT), üretim iş emri, açıklama,
                  kayıt no, saha defteri ref. (yalnızca planlı)
   └─< Adım uygulaması ── başlangıç zamanı, bitiş zamanı, görevli personeller
```

---

## 3. Önceden Tanımlı Yapı

- **R-01** Saha personeli süreci kendi kafasına göre yazmaz; her şey önceden tanımlı yapıdan gelir.
- **R-02** Kişi rastgele makine veya temizlik formu seçemez. Bir makine için kullanılabilecek checklist dokümanları tanımlıdır.
- **R-03** Bir temizlik fazlardan, her faz sıralı adımlardan oluşur. *Örnek: 3 faz → 1. fazda 5, 2. fazda 3, 3. fazda 4 adım.*
- **R-04** Operatör sahaya geldiğinde ne yapacağını ve hangi adımı önce, hangisini sonra yapacağını sistemden görür.
- **R-05** Bazı adımlarda fotoğraf veya video gösterilebilir (özellikle yeni personelin nasıl yapılacağını görmesi için).
- **R-06** Her fazın iş birimince öngörülen bir minimum süresi vardır (*örn. en az 15 dk*) ve sistem buna göre kontrol yapar. → Ayrıntı: R-27, R-28

## 4. Malzeme Takibi

- **R-07** Malzemelerin kodu, parti/lot bilgisi ve son kullanma tarihi vardır.
- **R-08** Bazı temizliklerde malzeme kullanımı zorunludur, bazılarında değildir (temizlik tipine göre).
- **R-09** Temizlik tipi için malzeme zorunluysa personel bunu atlayıp devam edemez.
- **R-10** Geriye dönük olarak "Bu makinenin temizliği yapılırken hangi malzeme (ve hangi lot) kullanılmış?" sorusu cevaplanabilir.

## 5. Tanımların Zamanla Değişmesi (Versiyonlama)

- **R-11** Prosedürler zamanla değişebilir. *Örnekler: faz minimum süresi 15 dk → 20 dk; bir adımın açıklaması değişir.*
- **R-12** Bir makine kullanımdan kaldırılabilir.
- **R-13** Geçmiş temizlik kayıtları kaybolmaz ve **yapıldığı anda geçerli olan tanımla** görüntülenir. Bugün tanım değişti diye geçmiş kayıtlar değişmez. *Örnek: geçen ayki temizliğe bugün bakıldığında o zamanki tanım görülür.*

## 6. Kayıt Oluşturma

- **R-14** Operatör bir temizlik yapacağı zaman sistemden yeni bir kayıt açar.
- **R-15** Kaydı açan kişi işin sorumlusudur. Sorumluluk **sonradan başka birinin üzerine geçirilemez**.
- **R-16** Kayıtta şu bilgiler bulunur:
  - tesis, hat, makine
  - temizlik/form tipi
  - gerekiyorsa kullanılacak malzemeler
  - varsa yardımcı personeller
  - ilgili üretim iş emri
  - gerektiğinde açıklama
- **R-17** Kayıt numarasını personel vermez, **sistem otomatik üretir**. Numara benzersizdir ve mümkün olduğunca işin nerede yapıldığını anlatır.
- **R-18** Normal (planlı) temizliklerde saha defteri referansı da **sistem tarafından otomatik** üretilir.
- **R-19** Plansız müdahaleler (üretim sırasında beklenmeyen durumda operatörün hemen müdahale etmesi) normal temizliklerden ayrı değerlendirilir:
  - saha defterine işlenmez, dolayısıyla saha referansı yoktur;
  - sistemde normal işlerden ayırt edilebilir.
- **R-20** **Kayıt açmak ≠ işe başlamak.** Kayıt açıldığı anda temizlik başlamış sayılmaz. Süre, iş gerçekten yapılmaya başlandığı anda başlar. *Örnek: kayıt 08:00'de açılır, ilk adıma 08:15'te başlanır.*

## 7. Sahada Kullanım — Adım Adım İlerleme

> İş birimi bu konuyu "en önemli konu" olarak nitelendiriyor.

- **R-21** Operatör temizliği yaparken sistemde adım adım ilerler.
- **R-22** Her adımda kimlerin gerçekten çalıştığı ayrı ayrı seçilir. Yardımcı personel tüm temizlik boyunca aynı kişi olmak zorunda değildir. *Örnek: 1. adım yalnızca Ahmet; 2. adım Ahmet + Mehmet; 3. adım yalnızca Mehmet.*
- **R-23** Kaydı açan kişi adıma varsayılan olarak görevli gelir; gerektiğinde adıma başka kişiler eklenir; ardından adım başlatılır.
- **R-24** Adımın başlama ve bitiş saatini **sistem kaydeder**. Personel saat yazmaz, "bu işi 10 dakikada yaptım" gibi manuel süre girmez.
- **R-25** Adımlar tamamlandıkça faz, tüm fazlar tamamlandığında temizlik tamamlanır.

## 8. Süre ve Efor

- **R-26** Süre ve efor ayrı tutulur ve raporlarda ikisi de görülür; birbirine karıştırılmaz. *Örnek: adım 10:00–10:10, iki kişi çalıştı → süre 10 dk, efor 20 dk.*
- **R-27** Fazın minimum süresi kontrol edilir. *Örnek: faz için en az 15 dk öngörüldüyse bu kontrol edilir.*
- **R-28** Faz süresi hesaplanırken adımlar arasındaki boşluk doğru ele alınmalıdır. *Örnek: operatör 1. adımı başlatıp 12 saniye sonra bitirir, yemeğe/başka işe gider, 40 dk sonra gelip 2. adımı başlatır.* İşin başında olunmayan bu 40 dakikanın temizlik süresine **dahil edilmesinin istenmediği durumlar olabilir**. Esas olan: sistemin **gerçek çalışma süresini** doğru göstermesi.

## 9. Eşzamanlılık Kısıtları

- **R-29** Bir makinede devam eden temizlik varsa başka bir kişi aynı makine için ikinci bir temizlik başlatamaz.
- **R-30** Bir personel aynı anda iki farklı temizlikte çalışamaz. *Örnek: Ahmet, Mehmet'in açtığı temizlikte bir adımda yardımcı olarak çalışıyorsa, aynı anda kendi açtığı başka bir temizlikte başka bir adımı başlatamaz.*
- **R-31** Yarım kalan temizlik **otomatik kapatılmaz**. *Örnek: vardiya bitti, iş tamamlanamadı.* Kayıt açık kalır; kişi sonra geldiğinde kaldığı yerden devam eder.

## 10. Kayıt Açmak ile İşe Başlamak Arasındaki Ayrım

- **R-32** Kayıt açılmış ama hiçbir adım başlatılmamışsa, bunun gerçekten başlamış bir iş olup olmadığı ayrıca değerlendirilmelidir.
- **R-33** Sadece kayıt açıldı diye makine başka bir temizlik için **sonsuza kadar kilitlenmemeli**.
- **R-34** Aynı zamanda iki kişi aynı makine için kayıt açıp **birbirinin önüne geçmemeli**. İki kişi aynı anda aynı makine için işlem yaparsa sistem doğru kişiye izin vermeli.
- **R-35** Bu kontrol **yalnızca ekranda yapılırsa yeterli değildir**, çünkü iki kişi gerçekten aynı anda işlem yapabilir (eşzamanlı isteklerde de geçerli olmalı).

## 11. Hatalı veya Yarım Bırakılan Kayıtlar

- **R-36** Ele alınması gereken durumlar:
  - operatör yanlış makineyi seçti ya da yanlışlıkla kayıt açtı;
  - kaydı açan kişi işten ayrıldı, başka bölüme geçti veya herhangi bir nedenle işi devam ettiremiyor.
- **R-37** Açık kalan iş sonsuza kadar kilit oluşturmamalı.
- **R-38** Saha personeli istediği kaydı kapatamamalı.
- **R-39** Bu durumlar kontrollü yönetilmeli; **yöneticinin müdahale edebileceği** bir yapı olmalı.

## 12. Yetkiler

- **R-40** Operatör ile yönetici aynı şeyleri görmez.
- **R-41** Operatör: kendi kaydını açabilir, kendi işindeki adımları ilerletebilir, kendi kayıtlarını görebilir.
- **R-42** Operatör tanımlara müdahale edemez. *Örnek: yeni makine tanımlayamaz, faz süresini değiştiremez, adım açıklamasını değiştiremez.*
- **R-43** Yönetici tanımları yönetebilir ve tüm temizlik kayıtlarını raporlayabilir.
- **R-44** **Görmek ≠ çalıştırmak.** Bir kişi başkasının kaydını görebilir, ama bu o kaydın adımlarını çalıştırabileceği anlamına gelmez. Bir adımı yalnızca **işin sahibi** veya **o adımda görevli olarak belirlenmiş kişiler** ilerletebilir. *Örnek: Mehmet, Ahmet'in kaydını görebilir; ama o kayıtta yardımcı olarak tanımlı değilse adım başlatamaz veya kapatamaz.*

## 13. Beklenen Temel Çıktı

- **R-45** Bir kayıt açıldığında şu sorular cevaplanabilmelidir:
  1. Temizlik hangi tesiste yapılmış?
  2. Hangi hatta ve hangi makinede yapılmış?
  3. Kim başlatmış?
  4. Kimler hangi adımlarda görev almış?
  5. Hangi adım ne zaman başlamış?
  6. Ne zaman bitmiş?
  7. Toplam ne kadar sürmüş?
  8. Toplam insan eforu ne kadar olmuş?
  9. Hangi malzemeler kullanılmış?
  10. Hangi parti/lot malzemeler kullanılmış?
  11. Temizlik hangi prosedürün hangi versiyonuna göre yapılmış?
  12. Hangi üretim işiyle ilişkiliymiş?
  13. Ne zaman tamamlanmış?
- **R-46** En önemlisi: bu bilgilerin **sonradan değiştirilip değiştirilmediği anlaşılabilmelidir**. Denetimde "temizlik yapılmıştır" demek yetmez; temizliğin gerçekte nasıl gerçekleştiği gösterilebilmelidir.

## 14. Değiştirilemezlik ve İzlenebilirlik

> İş birimi bu konuyu "son olarak bizim için en önemli konu" olarak nitelendiriyor.

- **R-47** Sistemde oluşan kayıtlar sonradan değiştirilemez. *Örnek: bugün 10:05'te başlatılan bir adım, yarın 09:30 olarak değiştirilemez.*
- **R-48** Yapılmamış bir adım sonradan yapılmış gibi gösterilemez.
- **R-49** Bir kayıt üzerinde kim, ne zaman, ne yaptı izlenebilir olmalıdır.

İş biriminin kendi cümlesiyle temel beklenti:

> "Sisteme girilen bilgi işlem gerçekleştiği anda oluşsun, sonradan geriye dönük olarak değiştirilerek geçmiş farklı gösterilemesin ve gerektiğinde bize bu sürecin tamamını kanıtlayabilecek kayıtları sunabilsin."

Teknik tasarım konusunda iş biriminin bir yönlendirmesi yoktur; çözümün teknik tasarımı tamamen bize bırakılmıştır.

---

## Kararlar

Dokümanın net olmadığı noktalar için verilen kararlar. Bunlar **dokümanda yazmaz**; tasarım ve kodda bu kararlar esas alınır. Her kararın yanında ilgili gereksinim numarası vardır.

Süre eşikleri (30 dk, 4 saat) varsayılan değerlerdir ve yönetici tarafından değiştirilebilir.

### Süre ve faz

- **K-01 Minimum süre ihlali (R-27):** Faz minimum süreden kısa sürerse faz yine kapanır, ama "minimum süre altında" sapması olarak işaretlenir. Fazın son adımı kapatılırken operatörden gerekçe istenir; gerekçe yazmak zorunludur.
- **K-02 Faz süresinin hesaplanması (R-28):** Prosedür versiyonunda her faz için "adımlar arasındaki boşluklar süreye dahil mi" seçeneği tanımlanır.
  - Dahil değilse faz süresi **net süredir**: fazdaki adımların çalışma dilimlerinin toplamı.
  - Dahilse faz süresi **brüt süredir**: fazın ilk çalışma diliminin başlangıcından son çalışma diliminin bitişine kadar geçen süre.
  - Minimum süre kontrolü, fazın bu ayara göre hesaplanan süresiyle yapılır.
  - Raporlarda temizliğin toplam net ve brüt süresi birlikte gösterilir.
- **K-03 Adımı duraklatma (R-28, R-31):** Adım duraklatılıp sonra devam ettirilebilir. Bir adımın kesintisiz çalışılan her bölümü ayrı bir **çalışma dilimi** olarak kaydedilir; başlangıç ve bitiş zamanları sunucu saatinden alınır. Adım duraklatılınca görevli personel serbest kalır. Bir dilim 4 saati aşarsa raporda "anormal uzun" olarak işaretlenir, ama kayıt değiştirilmez.
- **K-04 Adım sırasında kişi değişikliği (R-22, R-26):** Adım devam ederken görevli eklenip çıkarılabilir. Değişiklik anında açık olan dilim kapanır ve yeni görevli listesiyle yeni bir dilim başlar.
  - Adım süresi = dilim sürelerinin toplamı
  - Efor = her dilim için (dilim süresi × dilimdeki kişi sayısı) değerlerinin toplamı

### Kilitleme ve kaydın yaşam döngüsü

- **K-05 Makine kilidi (R-29, R-34, R-35):** Makine kilidi kayıt açılınca değil, **ilk adım başlatılınca** başlar.
  - Kayıt açmak makineyi kilitlemez; aynı makine için birden fazla başlamamış kayıt olabilir.
  - Bir makinede aynı anda en fazla bir başlamış kayıt olabilir. Bu, veritabanındaki bir unique index ile garanti edilir; iki kişi aynı anda başlatmaya çalışırsa ilk gelen kazanır, diğerine "bu makinede X kaydı devam ediyor" hatası döner.
  - Kayıt açılırken aynı makinede başlamamış başka bir kayıt varsa kullanıcı uyarılır.
  - Kilit, temizlik tamamlanınca veya iptal edilince kalkar. Duraklatılmış adımı olan kayıt kilidi tutmaya devam eder, çünkü makinede yarım kalmış bir temizlik vardır.
- **K-06 Başlamamış kayıt (R-32, R-33):** Açıldıktan sonra 30 dakika içinde ilk adımı başlatılmayan kayıt, sistem tarafından **"süresi doldu"** durumuna alınır. Kayıt silinmez, geçmişte görünür ve bu işlem olay kaydına sistem işlemi olarak yazılır. Kilit zaten ilk adımda başladığı için bu işlem makineyi serbest bırakmak için değil, eskimiş kayıtları temizlemek için yapılır. Başlamış bir iş olmadığı için R-31'i bozmaz.
- **K-07 Personel çakışması (R-30):** Bir kişi aynı anda yalnızca bir açık çalışma diliminde bulunabilir. Bu da veritabanındaki bir unique index ile garanti edilir. Duraklatılmış bir adım ya da kişinin kendi açık kaydı onu başka bir temizlikte çalışmaktan alıkoymaz.
- **K-08 Yöneticinin müdahalesi (R-36–R-39):** Yönetici, başlamış ya da başlamamış her açık kaydı iptal edebilir.
  - Gerekçe türü (hatalı kayıt / personel ayrıldı / diğer) seçmek ve açıklama yazmak zorunludur.
  - O ana kadar yapılan adımlar ve dilimler kayıtta kalır. Açık bir dilim varsa iptal anında kapanır. Makine ve personel serbest kalır.
  - Sorumluluk devredilemediği için (R-15) yarım kalan işi başkası devralamaz. Temizliğin tamamlanması gerekiyorsa yeni bir kayıt açılır.
- **K-09 Sahibin iptal etmesi (R-36, R-38):** Kaydın sahibi, hiçbir adımı başlamamışsa kendi kaydını iptal edebilir. Gerekçe türü "hatalı kayıt" olur ve açıklama zorunludur. Başlamış bir kaydı yalnızca yönetici iptal edebilir.

### Personel ve yetki

- **K-10 Sahibin bir adımdan çıkarılması (R-22, R-23):** Kaydın sahibi her adıma varsayılan olarak görevli gelir, ama görevli listesinden çıkarılabilir; bu durumda o adımın eforuna sayılmaz. Kaydın sahibi olduğu için adımı başlatma, duraklatma ve kapatma yetkisi devam eder (R-44). Her çalışma diliminde en az bir görevli bulunmak zorundadır.
- **K-11 Görünürlük (R-41, R-44):** Operatör tüm temizlik kayıtlarını görebilir ama değiştiremez. Bir adımı başlatmak, duraklatmak, devam ettirmek, kapatmak ve görevlilerini değiştirmek yalnızca kaydın sahibine ve o adımda görevli olanlara açıktır. Raporlama yalnızca yöneticiye açıktır.

### Malzeme

- **K-12 Malzeme girişi (R-09, R-16):** Malzeme kayıt açılırken girilir; temizlik sürerken ek malzeme de eklenebilir. Prosedürde malzeme zorunluysa, en az bir geçerli malzeme girilmeden ilk adım başlatılamaz. Yanlış girilen malzeme silinmez; gerekçe yazılarak geçersiz işaretlenir ve ilk kayıt olduğu gibi kalır.
- **K-13 Zorunluluğun seviyesi (R-08):** Malzemenin zorunlu olup olmadığı prosedür versiyonunda evet/hayır olarak tanımlanır. Malzeme kodu, yöneticinin tanımladığı katalogdan seçilir; lot ve son kullanma tarihini operatör girer.
- **K-14 Son kullanma tarihi (R-07):** Son kullanma tarihi, giriş anındaki sunucu tarihine göre geçmişse malzeme kaydedilemez.

### Tanımlar ve numaralandırma

- **K-15 Devam ederken prosedürün değişmesi (R-13):** Kayıt, açıldığı anda geçerli olan prosedür versiyonuna bağlanır ve sonuna kadar o versiyonla devam eder. Yeni versiyon yalnızca yeni kayıtlara uygulanır. Yayımlanmış bir versiyon değiştirilemez; her değişiklik yeni bir versiyon oluşturur.
- **K-16 Makineyi kullanımdan kaldırma (R-12):** Başlamış ya da başlamamış açık kaydı olan makine kullanımdan kaldırılamaz; yönetici önce kaydın tamamlanmasını bekler ya da kaydı iptal eder. Kullanımdan kaldırılan makine silinmez: yeni kayıtlarda seçilemez, geçmiş kayıtlarda görünmeye devam eder.
- **K-17 Numara formatı (R-17, R-18):**
  - Kayıt numarası: `{TESİS}-{HAT}-{MAKİNE}-{TİP}-{YIL}-{SIRA}`, örn. `IST-H01-M03-T-2026-0042`. `T` planlı temizlik, `M` plansız müdahale demektir. Sıra makine bazında tutulur, her yıl 1'den başlar; planlı ve plansız kayıtlar aynı sırayı paylaşır. Numara kayıt açılırken üretilir.
  - Saha defteri referansı: `{TESİS}-SD-{YIL}-{SIRA}`, örn. `IST-SD-2026-0123`. Yalnızca planlı temizliklerde üretilir. Sıra tesis bazında tutulur, her yıl 1'den başlar.
  - Saha defteri referansı kayıt açılırken değil, ilk adım başlatıldığında üretilir. Böylece hiç başlamayan, süresi dolan ya da iptal edilen kayıtlar fiziksel defterin sırasında boşluk bırakmaz.
  - Her iki numara da veritabanında unique index ile korunur. Sıra numarası, sayaç satırı kilitlenerek üretilir; böylece eşzamanlı isteklerde aynı numara iki kez verilmez.
- **K-18 Makinenin prosedürü (R-02, R-16, R-19):** Her makineye tanımlı tek bir geçerli prosedür vardır. Planlı ya da plansız, o makinedeki bütün temizlikler bu prosedüre göre yapılır; plansız müdahaleler için ayrı bir prosedür yoktur.
  - Operatör prosedür seçmez. Kayıt açılırken makinenin geçerli prosedürünün en son yayımlanmış versiyonu kayda otomatik bağlanır (K-15). Operatör yalnızca temizliğin planlı mı plansız mı olduğunu seçer; R-16'daki "temizlik/form tipi" bu seçimdir.
  - Geçerli prosedürü tanımlanmamış bir makine için kayıt açılamaz.
  - Aynı prosedür birden fazla makineye bağlanabilir (ör. aynı model makineler).
  - Plansız müdahalede saha defteri referansı üretilmez. Bunun dışında kilit, süre, malzeme ve değiştirilemezlik kuralları planlı temizlikle aynıdır.
- **K-19 Üretim iş emri (R-16):** Sistemde bir iş emri tablosu bulunur; demoda örnek verilerle doldurulur, gerçekte ERP'den gelir. İş emri makineye veya hatta göre filtrelenip listeden seçilir. Periyodik temizlikte ilgili bir iş emri olmayabileceği için alan opsiyoneldir.

### Kararlardan çıkan sonuçlar

Yukarıdaki kararların birlikte doğurduğu yapı. Durum makinesi (state machine) bu yapıya göre yazılacaktır.

- **Adım sırası:** Adımlar ve fazlar tanımlı sırayla yürütülür; önceki adım tamamlanmadan sonraki başlatılamaz. Bu yüzden bir temizlikte aynı anda en fazla bir adım çalışır ya da duraklatılmış olur. Bu kural R-03 ve R-04'ten çıkarımdır.
- **Temizlik durumları:** Temizlik düzeyinde "duraklatıldı" durumu yoktur; duraklatma adım düzeyindedir.

  ```
  BAŞLAMADI ──ilk adım başlar──▶ DEVAM EDİYOR ──son faz tamamlanır──▶ TAMAMLANDI
     │                               │
     ├─ 30 dk geçti (sistem) ─▶ SÜRESİ DOLDU
     ├─ sahibi/yönetici + gerekçe ─▶ İPTAL
     │                               └─ yönetici + gerekçe ─▶ İPTAL
  ```

- **Faz durumları:** `BEKLİYOR → DEVAM EDİYOR → TAMAMLANDI`. Faz, son adımı kapanınca tamamlanır. Süresi minimumun altındaysa sapma olarak işaretlenir ve gerekçe istenir.
- **Adım durumları:** `BEKLİYOR → ÇALIŞIYOR ⇄ DURAKLATILDI`, `ÇALIŞIYOR → TAMAMLANDI`. Duraklatılmış adım doğrudan tamamlanamaz; önce devam ettirilir. Her ÇALIŞIYOR dönemi bir çalışma dilimidir. Görevli listesi değişince dilim kapanır ve yenisi başlar.
- **Veritabanı garantileri:** (1) Bir makinede en fazla bir başlamış kayıt bulunur. (2) Bir kişi en fazla bir açık çalışma diliminde bulunur. (3) Kayıt numarası ve saha defteri referansı benzersizdir. Bu kurallar arayüzde değil veritabanında uygulanır (R-35).
- **Zaman damgaları:** Bütün başlangıç, bitiş ve olay zamanları sunucu saatinden alınır; kullanıcıdan hiçbir zaman bilgisi alınmaz (R-24, R-47).
