@extends('layouts.app')

@section('title', "Kullanıcı {$user->name}")
@section('page-title', $user->name)
@section('page-subtitle', "{$user->role->label()} · {$user->email}")

@section('page-actions')
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kullanıcılar
    </a>
@endsection

{{--
    Kullanıcı bilgileri, şifre sıfırlama ve pasife alma (R-36, R-40–R-43). Yönetici kendini pasife
    alamaz ve kendi yönetici rolünü kaldıramaz. Pasife alınacak kişinin açık kayıtları listelenir:
    sorumluluk devredilemediği için (R-15) yönetici gerekirse bu kayıtları "personel ayrıldı"
    gerekçesiyle iptal eder (K-08). Pasife almak yine de engellenmez.
--}}
@section('content')
    @php
        $hasOpenRecords = $ownedRecords->isNotEmpty() || $assignedRecords->isNotEmpty();
    @endphp

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <form method="POST" action="{{ route('admin.users.update', $user) }}" class="card admin-form user-form" data-module="submit-once" aria-labelledby="user-profile-title">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h2 class="card-title" id="user-profile-title">Kullanıcı bilgileri</h2>
                </div>

                <div class="card-body">
                    @include('admin.users.profile-fields', ['user' => $user, 'isSelf' => $isSelf])
                </div>

                <div class="card-footer admin-form__actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Kaydet
                    </button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-link">Vazgeç</a>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.users.password', $user) }}" class="card admin-form user-password mt-3" data-module="submit-once" aria-labelledby="user-password-title">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h2 class="card-title" id="user-password-title">Şifre sıfırla</h2>
                </div>

                <div class="card-body">
                    <p class="user-password__intro">Yeni şifreyi kişiye siz iletirsiniz; kişinin "beni hatırla" girişleri geçersiz olur.</p>
                    @include('admin.users.password-fields', ['label' => 'Yeni şifre'])
                </div>

                <div class="card-footer admin-form__actions">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-key" aria-hidden="true"></i> Şifreyi değiştir
                    </button>
                </div>
            </form>
        </div>

        <div class="col-12 col-xl-5">
            <section id="status" class="card admin-side user-status" aria-labelledby="user-status-title">
                <div class="card-header">
                    <h2 class="card-title" id="user-status-title">Durum</h2>
                </div>

                <div class="card-body">
                    <p>@include('admin.users.state', ['user' => $user])</p>

                    @error('is_active')
                        <div class="alert alert-danger user-status__error" role="alert">{{ $message }}</div>
                    @enderror

                    @if ($hasOpenRecords)
                        <div @class(['alert', 'alert-warning' => $user->is_active, 'alert-info' => ! $user->is_active, 'user-status__open']) role="note">
                            @if ($user->is_active)
                                Bu kişinin açık kayıtları var. Pasife alınması bu kayıtları kapatmaz.
                            @else
                                Pasif kişinin açık kayıtları var; kendiliğinden kapanmazlar.
                            @endif
                        </div>
                    @endif

                    @if ($ownedRecords->isNotEmpty())
                        <h3 class="h6 user-status__heading">Sahibi olduğu açık kayıtlar ({{ $ownedRecords->count() }})</h3>
                        <p class="form-text">
                            Sorumluluk başkasına devredilemez. Kişi işi sürdüremeyecekse kaydı açıp "Personel ayrıldı" gerekçesiyle iptal edin;
                            yapılan adımlar kayıtta kalır, temizlik gerekiyorsa yeni kayıt açılır.
                        </p>
                        @include('admin.users.open-records', ['records' => $ownedRecords, 'anchor' => '#cancel', 'class' => 'user-status__owned'])
                    @endif

                    @if ($assignedRecords->isNotEmpty())
                        <h3 class="h6 user-status__heading">Görevli olduğu açık kayıtlar ({{ $assignedRecords->count() }})</h3>
                        <p class="form-text">Bu kayıtlar başkasına ait; kaydın sahibi kişiyi bekleyen adımların görevlilerinden çıkarabilir.</p>
                        @include('admin.users.open-records', ['records' => $assignedRecords, 'anchor' => '', 'class' => 'user-status__assigned'])
                    @endif

                    @if (! $hasOpenRecords)
                        <p class="empty-state user-status__none">Açık kaydı ya da görevli olduğu bitmemiş adım yok.</p>
                    @endif

                    @if ($isSelf)
                        <p class="form-text user-status__self mb-0">Kendi hesabınızı pasife alamazsınız.</p>
                    @elseif ($user->is_active)
                        @php
                            $confirm = "{$user->name} pasife alınacak; giriş yapamayacak ve açık oturumu kapanacak.";
                            if ($hasOpenRecords) {
                                $confirm .= " Sahibi olduğu {$ownedRecords->count()} ve görevli olduğu {$assignedRecords->count()} açık kayıt kendiliğinden kapanmaz.";
                            }
                            $confirm .= ' Devam edilsin mi?';
                        @endphp
                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" class="user-status__form"
                              data-module="confirm-submit submit-once" data-confirm="{{ $confirm }}">
                            @csrf
                            <p class="form-text">Pasif kişi giriş yapamaz, yeni kayıtlarda görevli seçilemez; geçmiş kayıtlarda adı görünmeye devam eder.</p>
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="bi bi-person-slash" aria-hidden="true"></i> Pasife al
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.users.activate', $user) }}" class="user-status__form" data-module="submit-once">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="bi bi-person-check" aria-hidden="true"></i> Yeniden aktif et
                            </button>
                        </form>
                    @endif
                </div>
            </section>

            <x-definition-history :definition="$user" class="user-history mt-3" />
        </div>
    </div>
@endsection
