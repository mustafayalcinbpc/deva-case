# Uygulama Planı — Arayüz (AdminLTE)

Kapsam: AdminLTE 4'ü kurmak ve uygulamayı tarayıcıda çalışır hale getirmek: giriş, yetkiye göre menü, gösterge paneli (dashboard), demo verisi.
Kayıt açma, adım ekranları, tanım yönetimi ve raporlar sonraki adımdır.

**Tasarım ilkesi:** Görünüm (renkler, yazı tipi, köşe yuvarlaklığı, hareketler) sonradan CSS ile baştan değiştirilecek. Bu yüzden:
- Bütün stil `resources/scss/theme/` altındaki tema katmanında durur. View'larda inline stil ya da tek seferlik renk sınıfı yazılmaz.
- View'lar standart AdminLTE/Bootstrap yapısını ve anlamlı sınıf adlarını kullanır (ör. `status-badge`). Yeniden tasarım yalnızca tema dosyalarına dokunur.
- Varsayılan AdminLTE görünümünü güzelleştirmek için emek harcanmaz.

## Teknik seçimler

- **AdminLTE 4.9.1** (Bootstrap 5.3.8, jQuery yok). SCSS kaynaktan derlenir; böylece Bootstrap ve AdminLTE değişkenleri tek bir yerden değiştirilebilir.
- **Vite + Sass**, Tailwind kaldırıldı. İkonlar `bootstrap-icons`.
- **Docker'da `vite` servisi** (Node 24, Alpine) geliştirme sunucusunu çalıştırır. CSS değişiklikleri sayfa yenilenmeden yansır (HMR, port 5173). Üretim için `npm run build` kullanılır.
- **Paket sürümleri sabitlendi**, birkaç günlük sürümler alınmadı (tedarik zinciri riski).

## Tema katmanı (`resources/scss/`)

| Dosya | İçerik |
|---|---|
| `app.scss` | Giriş noktası; aşağıdakileri sırayla yükler |
| `theme/_variables.scss` | Derleme zamanı SCSS değişkenleri: Bootstrap/AdminLTE paleti, yazı tipi, köşe yuvarlaklığı, sidebar genişliği, geçiş süresi |
| `theme/_tokens.scss` | Çalışma zamanı CSS değişkenleri (`--app-*`): durum renkleri, hareket süreleri/eğrileri, gölgeler. Açık ve koyu tema için ayrı değerler |
| `theme/_components.scss` | Uygulamaya özel bileşenler (`status-badge` vb.) |
| `theme/_motion.scss` | Geçişler ve animasyonlar; `prefers-reduced-motion` desteği |

## Fazlar

| Faz | İş | Kim | Paralel mi? |
|---|---|---|---|
| 0 | Paketler, Vite, Docker `vite` servisi, tema katmanı, `layouts.app` / `layouts.guest`, menü yapısı, `x-status-badge`, route iskeleti, test temeli | Tamamlandı (ana oturum) | — (ortak sözleşme) |
| 1A | Giriş/çıkış, giriş isteğinin doğrulanması, pasif kullanıcı engeli, yönetici yetkisi (Gate) + testleri | Tamamlandı (Ajan A) | Evet |
| 1B | Demo verisi (`DemoSeeder`): tesis/hat/makine, prosedür, malzeme, iş emri, kullanıcılar, farklı durumlarda temizlikler + testi | Tamamlandı (Ajan B) | Evet |
| 1C | Gösterge paneli: özet kutuları ve açık kayıtlar tablosu + testleri | Tamamlandı (Ajan C) | Evet |
| 2 | Entegrasyon: testler, `npm run build`, tarayıcıda kontrol, README | Tamamlandı | Hayır |

## Sözleşmeler (Faz 0'da kurulur, ajanlar değiştirmez)

**Layout'lar** (Blade `@extends`):
- `layouts.app`: giriş yapmış kullanıcı için AdminLTE iskeleti. Bölümler: `@section('title')` (sekme başlığı), `@section('page-title')` (içerik başlığı), `@section('content')`. Sidebar ve üst bar layout'un içindedir.
- `layouts.guest`: giriş sayfası gibi oturumsuz sayfalar. Bölümler: `@section('title')`, `@section('content')`. Gövde sınıfı `login-page`.

**Route adları** (`routes/web.php`, Faz 0):
- `login` (GET `/login`) → `App\Http\Controllers\Auth\LoginController@create`
- `login.store` (POST `/login`) → `LoginController@store`
- `logout` (POST `/logout`) → `LoginController@destroy`
- `dashboard` (GET `/`) → `App\Http\Controllers\DashboardController@index`, `auth` middleware

**Menü:** `config/menu.php`: `[label, icon, route, roles?]` öğeleri ve `header` başlıkları. Sidebar, kullanıcının rolüne göre filtreler; aktif route işaretlenir.

**Durum rozeti:** `<x-status-badge :status="$enum" />`. `CleaningStatus`, `PhaseStatus` ve `StepStatus` enum'larını kabul eder ve `<span class="status-badge status-badge--{değer}">{label}</span>` üretir. Renkler `theme/_tokens.scss` içindedir.

**Zaman ve süre gösterimi:** Zamanlar veritabanında UTC saklanır. `<x-datetime :value="$carbon" />` zamanı `config('app.display_timezone')` (varsayılan Europe/Istanbul) ile `d.m.Y H:i` biçiminde gösterir, değer yoksa `—` gösterir. `<x-duration :seconds="$int" />` süreyi `45 sn`, `12 dk 30 sn`, `1 sa 05 dk` biçiminde gösterir.

**Demo kullanıcıları** (1B oluşturur, 1A giriş sayfasında yalnızca `local` ortamda gösterir). Sonradan unvana göre numaralandırıldı ve tek kaynak olarak `config/demo.php`'ye taşındı. Hepsinin şifresi `1234`:

| E-posta | Ad | Rol |
|---|---|---|
| `operator1@demo.test` | Ahmet Yılmaz | operatör |
| `operator2@demo.test` | Mehmet Kaya | operatör |
| `operator3@demo.test` | Ayşe Demir | operatör |
| `operator4@demo.test` | Eski Personel | operatör, pasif (`is_active = false`) |
| `yonetici1@demo.test` | Zeynep Arslan | yönetici |

**Gösterge paneli yer tutucusu:** Faz 0'da `DashboardController@index` ve `resources/views/dashboard.blade.php` basit birer yer tutucu olarak vardır; 1C bunları gerçek panelle değiştirir.

**Testler:** `tests/TestCase.php` her testte `withoutVite()` çağırır; view testleri derlenmiş asset istemez.

## Dosya sahipliği

| Ajan | Dosyalar |
|---|---|
| A | `app/Http/Controllers/Auth/LoginController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `resources/views/auth/login.blade.php`, `app/Providers/AppServiceProvider.php` (Gate), `tests/Feature/Auth/*` |
| B | `database/seeders/DemoSeeder.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/DemoSeederTest.php` |
| C | `app/Http/Controllers/DashboardController.php`, `resources/views/dashboard.blade.php`, `resources/views/dashboard/*`, `tests/Feature/DashboardTest.php` |

Ortak kurallar: Faz 0 dosyaları salt okunurdur; değişiklik gerekiyorsa raporlanır. Her ajanın kendi test veritabanı vardır (`temizlik_test_a/b/c`). Commit yapılmaz.

## Sonuç

- Bütün test paketi geçiyor: 334 test, 1492 doğrulama (durum makinesi dahil).
- `migrate:fresh --seed` ile demo verisi yükleniyor: 16 temizlik kaydı, hepsi `CleaningWorkflow` üzerinden üretildi ve olay zincirleri doğrulanıyor.
- Başsız Chrome ile kontrol edildi: giriş, gösterge paneli, kullanıcı menüsü, sidebar aç/kapa ve çıkış çalışıyor; konsolda hata yok. Mobil genişlikte sidebar gizleniyor, tablo kart içinde kayıyor.

### Entegrasyonda eklenenler

- **Pasif kullanıcının oturumu (Ajan A önerisi).** Oturum açıkken pasife alınan kullanıcı bir sonraki istekte çıkışa yönlendirilir (`EnsureUserIsActive`, web grubuna eklendi).
- **Bildirimler.** Giriş sayfası layout'u `session('status')` bildirimlerini gösterir.
- **Tema kuralları (Ajan C ve A'nın listelediği sınıflar).** Gösterge paneli sayaçları, "Bana ait" işareti ve satır vurgusu, kayıt numarası, boş durum, demo hesapları. Renkler `_tokens.scss` değişkenlerinden gelir.
- **İleri tarihli prosedür versiyonu (Ajan B'nin bulgusu).** `Procedure::currentVersion()` artık yayın tarihi gelmemiş versiyonu yeni kayıtlara uygulamıyor; test eklendi.

### Sonraki adımlar

- Türkçe doğrulama mesajları için `lang/tr` dosyaları (şu an yalnızca giriş formunda özel mesajlar var).
- Kayıt açma ve adım ekranları, tanım yönetimi (yönetici), raporlar.

