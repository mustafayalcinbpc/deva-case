<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procedures\PublishProcedureVersionRequest;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Services\Definitions\ProcedureVersioning;
use Illuminate\Http\RedirectResponse;

/**
 * Taslağı yayımlama (K-15): hemen ya da ileri bir tarihte. Yayımlanan versiyon bir daha
 * değişmez; açık kayıtlar kendi versiyonunda kalır.
 */
class ProcedurePublicationController extends Controller
{
    public function store(PublishProcedureVersionRequest $request, Procedure $procedure, ProcedureVersion $version, ProcedureVersioning $versioning): RedirectResponse
    {
        $published = $versioning->publish($version, $request->publishAt());

        $message = $published->isScheduled()
            ? "v{$published->version} yayımlandı; ".$published->published_at->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i').' itibarıyla açılan yeni kayıtlara uygulanacak.'
            : "v{$published->version} yayımlandı; bundan sonra açılan kayıtlara uygulanır. Açık kayıtlar kendi versiyonuyla devam eder.";

        return redirect()
            ->route('admin.procedures.show', $procedure)
            ->with('status', $message);
    }
}
