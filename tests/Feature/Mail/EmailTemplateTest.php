<?php

use App\Models\EmailTemplate;
use App\Services\Mail\EmailTemplateRenderer;
use Database\Seeders\EmailTemplateSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new EmailTemplateSeeder)->run());

test('seeder creates the four templates idempotently and the renderer replaces placeholders', function () {
    (new EmailTemplateSeeder)->run();
    expect(EmailTemplate::count())->toBe(4);
    $r = app(EmailTemplateRenderer::class)->render('invoice', ['cliente' => 'ACME', 'numero' => 'FAC-000001', 'empresa' => 'Gomez', 'total' => 'RD$ 1.00', 'fecha' => '01/11/2026', 'vendedor' => 'Ana', 'usuario' => 'Ana']);
    expect($r['subject'])->toBe('Factura FAC-000001 de Gomez')->and($r['body_html'])->toContain('con fecha 01/11/2026 por RD$ 1.00');
});

test('templates page requires settings manage and saves edits with audit', function () {
    actingAsRole('accounting');
    $this->get(route('admin.email-templates.index'))->assertForbidden();
    actingAsRole('owner');
    $this->get(route('admin.email-templates.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/email-templates/index')->has('templates', 4));
    $this->put(route('admin.email-templates.update', 'invoice'), ['subject' => 'Su factura {{numero}}', 'body_html' => '<p>Hola {{cliente}}</p>'])->assertRedirect(route('admin.email-templates.index'))->assertSessionHas('success', 'Plantilla actualizada');
    $this->put(route('admin.email-templates.update', 'invoice'), ['subject' => '', 'body_html' => '<script>x</script>'])->assertSessionHasErrors(['subject', 'body_html']);
    expect(EmailTemplate::where('key', 'invoice')->first()->subject)->toBe('Su factura {{numero}}')
        ->and(Activity::inLog('email_templates')->where('event', 'updated')->exists())->toBeTrue();
});
