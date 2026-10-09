@extends('layouts.app')

@section('title', 'Yeni Kullanıcı')
@section('page-title', 'Yeni Kullanıcı')
@section('page-subtitle', 'Kişi, verdiğiniz e-posta ve şifreyle giriş yapar.')

@section('page-actions')
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kullanıcılar
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.users.store') }}" class="card admin-form user-form" data-module="submit-once" aria-labelledby="user-form-title">
        @csrf

        <div class="card-header">
            <h2 class="card-title" id="user-form-title">Kullanıcı bilgileri</h2>
        </div>

        <div class="card-body">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    @include('admin.users.profile-fields', ['user' => new \App\Models\User, 'isSelf' => false])
                </div>
                <div class="col-12 col-lg-6">
                    @include('admin.users.password-fields', ['label' => 'Şifre'])
                </div>
            </div>
        </div>

        <div class="card-footer admin-form__actions">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Kullanıcıyı ekle
            </button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-link">Vazgeç</a>
        </div>
    </form>
@endsection
