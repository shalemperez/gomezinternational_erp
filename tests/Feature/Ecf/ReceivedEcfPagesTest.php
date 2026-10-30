<?php

use App\Models\ReceivedEcf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('received e-cf pages list, show, download and export with the right permissions', function () {
    Storage::fake('local');
    ecfMode('dgii');
    activeCertificate();
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml())])->assertOk();
    $received = ReceivedEcf::query()->firstOrFail();
    actingAsRole('sales');
    $this->get(route('fiscal.received-ecfs.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('fiscal.received-ecfs.index', ['search' => 'Proveedor']))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/received-ecfs/index')->has('rows.data', 1)->where('rows.data.0.encf', 'E310000000777')->where('rows.data.0.commercial_status_label', 'Pendiente'));
    $this->get(route('fiscal.received-ecfs.show', $received))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/received-ecfs/show')->where('receivedEcf.monto_total', '1180.00')->where('can.approve', true)->has('history'));
    $this->get(route('fiscal.received-ecfs.xml', $received))->assertOk()->assertDownload('101010101E310000000777.xml');
    $this->get(route('fiscal.received-ecfs.xml', [$received, 'file' => 'arecf']))->assertOk()->assertDownload('ARECF-101010101E310000000777.xml');
    $this->get(route('fiscal.received-ecfs.export', ['format' => 'xlsx']))->assertOk();
});
