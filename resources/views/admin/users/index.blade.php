@extends('layouts.app')

@section('title', 'Kullanıcılar')
@section('page-title', 'Kullanıcılar')
@section('page-subtitle', 'Kayıtlar kişilere bağlı olduğu için kullanıcı silinmez; işten ayrılan kişi pasife alınır.')

@section('page-actions')
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
        <i class="bi bi-person-plus" aria-hidden="true"></i> Yeni kullanıcı
    </a>
@endsection

{{--
    Kullanıcılar ve rolleri (R-40–R-43). Açık kayıt sütunları, pasife alınacak kişinin elindeki
    işleri gösterir (R-36, K-08): sahibi olduğu açık kayıtlar ve başkasının açık kaydında bitmemiş
    bir adımda görevli olduğu kayıtlar.
--}}
@section('content')
    <section class="card admin-list user-list" aria-labelledby="user-list-title">
        <div class="card-header">
            <h2 class="card-title" id="user-list-title">Kullanıcı listesi</h2>
        </div>

        @if ($users->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Henüz kullanıcı yok.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Ad soyad</th>
                                <th scope="col">E-posta</th>
                                <th scope="col">Rol</th>
                                <th scope="col">Durum</th>
                                <th scope="col" class="text-end" title="Kişinin sorumlusu olduğu, başlamamış ya da devam eden kayıtlar">Sahibi olduğu açık kayıt</th>
                                <th scope="col" class="text-end" title="Başkasına ait açık kayıtlarda bitmemiş bir adımda görevli olduğu kayıtlar">Görevli olduğu açık kayıt</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr id="user-{{ $user->id }}" @class(['admin-list__row', 'admin-list__row--inactive' => ! $user->is_active])>
                                    <td>
                                        <a href="{{ route('admin.users.edit', $user) }}" class="user-list__name">{{ $user->name }}</a>
                                        @if ($user->is(auth()->user()))
                                            <span class="mine-badge">siz</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ $user->email }}</td>
                                    <td>{{ $user->role->label() }}</td>
                                    <td>@include('admin.users.state', ['user' => $user])</td>
                                    <td class="text-end user-list__owned">{{ $user->open_owned_count }}</td>
                                    <td class="text-end user-list__assigned">{{ $user->open_assigned_count }}</td>
                                    <td class="text-end text-nowrap admin-list__actions">
                                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                            <span class="visually-hidden">{{ $user->name }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($users->hasPages())
                <div class="card-footer admin-list__pagination">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
