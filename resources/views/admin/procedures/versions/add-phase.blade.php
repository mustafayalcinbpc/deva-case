{{-- Taslağa faz ekleme; yeni faz sona eklenir, sırası faz kartındaki düğmelerle değişir. --}}
<section class="card procedure-add-phase mb-3" aria-labelledby="procedure-add-phase-title">
    <div class="card-header">
        <h2 class="card-title" id="procedure-add-phase-title">Faz ekle</h2>
    </div>

    <form method="POST" action="{{ route('admin.procedures.phases.store', [$procedure, $version]) }}" data-module="submit-once" novalidate>
        @csrf
        <div class="card-body">
            @include('admin.procedures.phases.fields', ['phase' => null])
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-plus-circle" aria-hidden="true"></i> Fazı ekle
            </button>
        </div>
    </form>
</section>
