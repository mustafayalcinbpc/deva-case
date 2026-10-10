# Notlarım — Dijital Temizlik Takip (deva-case)

Son durum: 10 Ekim 2026. Prosedür sayfası sekmeleri ile malzeme lotları, üretim iş emri durumu, temizlik planları ve görevler `main`'e merge edildi ve GitHub'a push edildi. Bütün testler geçiyor (830 test). Mac'te yeni migration'lar için: `docker compose exec app php artisan migrate:fresh --seed`.

## Nasıl çalıştırılır

```bash
docker compose up -d --build        # ilk açılışta migration + demo verisi kendiliğinden yüklenir
# http://localhost:8005
```

- Demo hesapları, hepsinin şifresi `1234`:
  - Operatörler: `operator1@demo.test` … `operator3@demo.test`. `operator4@demo.test` pasif personel.
  - Yönetici: `yonetici1@demo.test`.
- Demo verisini sıfırdan yüklemek (veritabanını siler): `docker compose exec app php artisan migrate:fresh --seed`
- Testler: `docker compose exec app php artisan test`
- Production: README'deki "Production" bölümü (`compose.prod.yaml`).
- GitHub'a push, ortamdaki deploy key ile:
  `GIT_SSH_COMMAND="ssh -i /root/.config/deva-case/deploy_key -o IdentitiesOnly=yes" git push origin main`

## Dokümanlar

- `docs/is-gereksinimleri.md`: case dokümanından çıkarılan gereksinimler (R-01–R-49) ve verdiğimiz kararlar (K-01–K-23).
- `docs/plan.md`: durum makinesi planı ve sonuçları.
- `docs/plan-arayuz.md`: AdminLTE kurulumu.
- `docs/plan-ekranlar.md`: kayıt ekranları.
- `docs/plan-yonetim-rapor-tasarim.md`: yönetim, raporlar, kuyruk, tasarım, iyileştirmeler.
- `docs/plan-detay-sekmeler.md`: kayıt detayı sekmeleri ve ilerleme göstergesi.
- `docs/plan-malzeme-planlama.md`: malzeme lotları, üretim iş emri, temizlik planı ve görevler; ajan sözleşmeleri.
- `README.md`: kurulum, mimari, durum yönetimi, ekranlar, yönetim, raporlar, bildirimler, kontrol noktaları, production, tema.

## Yapılanlar

1. **Gereksinim analizi.** docx → numaralı gereksinimler. Dokümanın açık bıraktığı 19 nokta K-xx kararı olarak kayda geçti.
2. **Durum makinesi** (`app/Services/Cleaning/CleaningWorkflow.php`):
   - temizlik, faz ve adım durumları; izin verilen geçişler enum'larda;
   - kural ihlalinde `CleaningRuleViolation`;
   - makine ve personel çakışması MySQL unique index'leriyle garanti ediliyor;
   - çalışma dilimleriyle net/brüt süre ve efor;
   - değiştirilemez, hash zincirli olay kaydı (trigger'lar).
3. **Altyapı.** Laravel 13 + Docker Compose: app, nginx, MySQL, Redis, RabbitMQ, queue, scheduler, vite.
4. **Arayüz.** AdminLTE 4. Giriş, roller, gösterge paneli.
5. **Kayıt ekranları.** Kayıt açma, liste, detay ("Şimdi" kartı, kontrol listesi, malzemeler, iptal, olay geçmişi), aksiyon uç noktaları. Yetki kuralları `CleaningPermissions`'ta; ekran ve workflow aynı kuralı kullanıyor.
6. **Demo verisi.** Boş veritabanına ilk açılışta kendiliğinden yükleniyor (`demo:seed`). Her durumdan 17 kayıt var. Hesaplar `config/demo.php`'de.
7. **Tanım yönetimi** (yönetici):
   - tesis/hat/makine: K-16 makine kaldırma, K-17 kod kilidi;
   - prosedür taslağı ve yayımlama: K-15, yayımlanmış versiyon model seviyesinde değişmez; adım medyası;
   - malzeme, iş emri, kullanıcı.
8. **Değişiklik günlüğü.** Yönetimdeki her değişiklik: kim, ne zaman, ne değişti. Değiştirilemez.
9. **Raporlar:**
   - süre ve efor (makine, faz);
   - sapmalar;
   - malzeme/lot izlenebilirliği;
   - denetim raporu (yazdır, PDF). PDF ve CSV kuyrukta üretiliyor.
10. **RabbitMQ.** Domain olayları → kuyruktaki dinleyiciler → bildirim zili.
11. **Denetim kontrol noktaları.** `audit:checkpoint` saatlik, `audit:verify` günlük. Kontrol noktaları log dosyasına da yazılıyor; bu dosya sunucu dışına taşınmalı.
12. **Production compose.** Kod imajda, debug kapalı.
13. **Tasarım.** Nocturne görsel dili, açık ve koyu tema (`resources/scss/theme/`). Kaynak tasarım dosyası başka projeye ait olduğu için repodan kaldırıldı.
14. **Düzeltmeler:**
   - kayıt numarası yılı yerel takvime göre (Europe/Istanbul);
   - kayıt açma ile makine kaldırma arasındaki yarış (makine satırı kilitleniyor);
   - production'da seeder (Faker yok);
   - geliştirme ortamında 20 MB dosya yükleme;
   - root'a ait derlenmiş view'lar yüzünden 500: entrypoint her açılışta `storage` sahipliğini www-data'ya veriyor.
15. **Kayıt detayı sekmeleri ve ilerleme göstergesi** (`docs/plan-detay-sekmeler.md`): Şimdi, Adımlar, Özet, Malzemeler, Olay geçmişi sekmelerde; sağ sütunda kargo takibi gibi faz/adım ilerlemesi. Operatör ve yönetici aynı sayfayı görür.
16. **Prosedür sayfası sekmeleri.** Versiyonlar, Özet, Kullanan makineler, Değişiklik geçmişi; sekme yapısı iki sayfada ortak (`section-tabs.js`, `<x-section-tabs.*>`).
17. **Malzeme, üretim iş emri, planlama** (`docs/plan-malzeme-planlama.md`):
   - malzeme lotları (SKT lotun özelliği); kayıtta yalnızca lot seçimi, lot no/SKT kopyası (K-14);
   - prosedür versiyonunda beklenen malzemeler, zorunlu olanların her biri ilk adımdan önce şart (K-12, K-13);
   - "İş emri" → "Üretim iş emri"; durum (planlandı/üretimde/tamamlandı), ürün, planlanan zamanlar (K-19);
   - temizlik planları (periyodik / üretim iş emri tamamlanınca), görev üretimi (saatlik komut + dinleyici), gecikme bildirimi (K-20);
   - gösterge panelinde "Yapılması gereken temizlikler", görevden kayıt açma, görev iptali (K-21, K-23);
   - port 8005 (`APP_PORT`, `APP_URL`).

## Önemli kararlar (mülakatta sorulabilir)

- **K-05:** Makine kilidi kayıt açılınca değil, ilk adım başlatılınca devreye giriyor. Aynı makineye iki kişi kayıt açabilir, ilk adımı önce başlatan kazanır; formda uyarı var. **R-34 ile gerilimli; savunulmalı.**
- **K-01:** Minimum süre altında kalan faz engellenmez. Gerekçe istenir ve sapma olarak işaretlenir.
- **K-02:** Adımlar arası boşluğun süreye dahil olup olmadığı faz bazında ayar.
- **K-03, K-04:** Adım duraklatılabilir; görevli değişince yeni çalışma dilimi açılır. Efor dilim bazında hesaplanır.
- **K-06:** Başlatılmayan kayıt 30 dakikada "süresi doldu" olur. Başlamış iş asla otomatik kapanmaz.
- **K-08, K-09:** Başlamış kaydı yalnızca yönetici iptal eder; sahip yalnızca başlamamış kaydını "hatalı kayıt" olarak iptal edebilir.
- **K-11:** Operatör bütün kayıtları görür ama değiştiremez. Adımı yalnızca sahibi ya da görevlisi yürütür.
- **K-18:** Her makinenin tek geçerli prosedürü var; planlı ve plansız temizlik aynı prosedürü kullanır.
- **K-14:** SKT lotun özelliği; operatör lot seçer, yazmaz. Kayıt lot no ve SKT'nin o anki kopyasını taşır.
- **K-19:** Kayıt, temizliğin hazırladığı *sonraki* üretim iş emrine bağlanır; tamamlanan emir görevde "tetikleyen" olarak durur.
- **K-20, K-21:** Görev ≠ kayıt. Plan görev üretir, kaydı operatör görevden açar ve sorumlusu olur (R-15 korunur, K-06 göreve işlemez).

## Yapılacaklar / açık konular

1. **D: görev ataması (K-22, bekliyor).** Yönetici görevi sorumlu operatör ve yardımcılar seçerek verir; "Görevlerim". Şu an görevler atanmamış, herhangi bir operatör görevden kayıt açabiliyor. Karar: görev modeli mi, yöneticinin kaydı açıp sorumlu seçmesi mi (öneri: görev modeli).
2. **K-05 kararı.** Ya mülakatta gerekçesiyle savun, ya da "açık kayıt varken aynı makineye ikinci kayıt açılamaz, başlamayan kayıt 30 dakikada düşer" modeline dön.
3. **Yapay zekâ görünürlüğü.** `docs/plan*.md` "Ajan A–H" diye yazıyor, commit'lerde Claude ortak yazar satırı var. Case kurallarındaki yapay zekâ politikasına göre karar ver. İzin varsa sahiplen ve kararları anlat.
4. **Tasarım dosyası git geçmişinde.** "Huzurevi Panel Tasarımı.html" GitHub'daki geçmişte duruyor (`c9c57e6`). Tamamen silmek için geçmişi yeniden yazıp force push etmek gerekir.
5. **Savunma ve mimari özeti.** Kısa bir doküman hazırlanmalı: neden durum makinesi, neden veritabanı kilidi, hash zinciri ve kontrol noktaları, kuyruk kullanımı, K-xx kararlarının gerekçeleri.
6. **Teknik küçükler:**
   - eski PDF/CSV dışa aktarma dosyalarını temizleyen bir görev yok;
   - `CleaningCompleted` olayının dinleyicisi yok (ileride rapor/önbellek için);
   - production'da `event:cache` ya da listener discovery açık kalmalı;
   - query builder ile toplu güncelleme değişiklik günlüğünü ve model korumalarını atlar (şu an kodda yok). Prosedür tablolarında trigger yok, koruma model seviyesinde.
   - `storage/logs/audit-checkpoints.log` dosyasının sunucu dışına taşınması operasyon işi;
   - malzeme izlenebilirlik raporunda lot kaydına göre ayrı filtre yok (lot araması lot kaydını da kapsıyor).
7. **Tasarım farkları:**
   - üst barda arama yok (uygulamada arama yok);
   - çok sütunlu tablolar yatay kayıyor;
   - yeni ekranların (lotlar, beklenen malzemeler, planlar, görev kartı) sınıflarına özel stil yazılmadı; Bootstrap/AdminLTE varsayılanıyla çiziliyor.
8. **Yerel branch'ler.** `feature/*` branch'leri yalnızca yerelde duruyor, GitHub'a gönderilmedi.
