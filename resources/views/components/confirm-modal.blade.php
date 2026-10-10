{{--
    Ortak onay penceresi (layouts.app). data-module="confirm-submit" taşıyan formlar gönderilmeden
    önce bu pencereyi açar; başlık, mesaj ve onay düğmesi formun data-confirm-* niteliklerinden
    gelir (confirm-submit.js). JS yoksa form doğrudan gönderilir; asıl kontrol sunucudadır.
--}}
<div class="modal fade confirm-modal" id="confirm-modal" tabindex="-1" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirm-modal-title">Onay</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirm-modal-message"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" data-confirm-accept>Devam et</button>
            </div>
        </div>
    </div>
</div>
