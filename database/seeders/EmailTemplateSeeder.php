<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            'proforma' => [
                'subject' => 'Proforma {{numero}} de {{empresa}}',
                'body_html' => '<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la proforma {{numero}} por {{total}}, válida hasta la fecha indicada en el documento.</p><p>Quedamos atentos a su confirmación.</p><p>{{vendedor}}<br>{{empresa}}</p>',
            ],
            'invoice' => [
                'subject' => 'Factura {{numero}} de {{empresa}}',
                'body_html' => '<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la factura {{numero}} con fecha {{fecha}} por {{total}}.</p><p>Gracias por su preferencia.</p><p>{{vendedor}}<br>{{empresa}}</p>',
            ],
            'statement' => [
                'subject' => 'Estado de cuenta de {{empresa}}',
                'body_html' => '<p>Estimado/a {{cliente}}:</p><p>Adjuntamos su estado de cuenta al {{fecha}}. Saldo pendiente: {{total}}.</p><p>{{usuario}}<br>{{empresa}}</p>',
            ],
            'credit_note' => [
                'subject' => 'Nota de crédito {{numero}} de {{empresa}}',
                'body_html' => '<p>Estimado/a {{cliente}}:</p><p>Adjuntamos la nota de crédito {{numero}} por {{total}}.</p><p>{{usuario}}<br>{{empresa}}</p>',
            ],
        ];

        foreach ($templates as $key => $template) {
            EmailTemplate::query()->firstOrCreate(['key' => $key], $template);
        }
    }
}
