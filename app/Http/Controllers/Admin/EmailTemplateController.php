<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    #[Middleware('permission:admin.settings.manage')]
    public function index(): Response
    {
        return Inertia::render('admin/email-templates/index', [
            'templates' => EmailTemplate::query()->orderBy('id')->get(['key', 'subject', 'body_html']),
            'placeholders' => ['cliente', 'numero', 'empresa', 'total', 'fecha', 'vendedor', 'usuario'],
        ]);
    }

    #[Middleware('permission:admin.settings.manage')]
    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update($request->validated());

        return redirect()->route('admin.email-templates.index')->with('success', 'Plantilla actualizada');
    }
}
