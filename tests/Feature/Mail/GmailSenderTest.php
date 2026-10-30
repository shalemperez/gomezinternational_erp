<?php

use App\Actions\Mail\SendDocumentEmail;
use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\Client;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Mail\LogGmailSender;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new EmailTemplateSeeder)->run());

test('log driver is bound outside production and returns a message id', function () {
    expect(app(GmailSender::class))->toBeInstanceOf(LogGmailSender::class);
    Log::shouldReceive('channel')->with('mail')->andReturnSelf();
    Log::shouldReceive('info')->once();
    $result = app(GmailSender::class)->send(User::factory()->create(['gmail_connected_at' => now()]), new OutgoingEmail(['a@b.test'], [], 'Asunto', '<p>Hola</p>', []));
    expect($result->messageId)->toStartWith('log-');
});

test('send document email renders the template, logs the sent email and the document activity', function () {
    $sender = User::factory()->create(['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    $client = Client::factory()->create(['business_name' => 'Cliente SRL']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');

    $sent = app(SendDocumentEmail::class)->handle($sender, $client, 'proforma', ['c@x.test'], ['cc@x.test'], ['cliente' => 'Cliente SRL', 'numero' => 'COT-000001', 'empresa' => 'Gomez', 'total' => 'RD$ 100.00', 'fecha' => '29/10/2026', 'vendedor' => 'Ana', 'usuario' => 'Ana'], [['name' => 'COT-000001.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 x']]);

    expect($sent->subject)->toBe('Proforma COT-000001 de Gomez')
        ->and($sent->body_html)->toContain('Estimado/a Cliente SRL')
        ->and($sent->to)->toBe(['c@x.test'])->and($sent->cc)->toBe(['cc@x.test'])->and($sent->attachments)->toBe(['COT-000001.pdf'])
        ->and($sent->status)->toBe('sent')->and($sent->gmail_message_id)->toStartWith('log-')->and($sent->mailable_id)->toBe($client->id)
        ->and(Activity::inLog('clients')->where('event', 'emailed')->where('subject_id', $client->id)->exists())->toBeTrue()
        ->and(SentEmail::count())->toBe(1);
});

test('sending without a connected gmail is refused', function () {
    $sender = User::factory()->create(['gmail_connected_at' => null]);
    expect(fn () => app(SendDocumentEmail::class)->handle($sender, Client::factory()->create(), 'invoice', ['a@b.test'], [], [], []))
        ->toThrow(GmailNotConnectedException::class, 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
});

test('a rejected gmail token is reported as a reconnect request instead of a server error', function () {
    expect((new GmailNotConnectedException)->getMessage())->toBe('Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    $this->app->instance(GmailSender::class, new class implements GmailSender
    {
        public function send(User $sender, OutgoingEmail $email): SentEmailResult
        {
            throw new GmailNotConnectedException('Google rechazó la conexión con Gmail (invalid_grant). Reconecta tu cuenta de Gmail.');
        }
    });
    $client = Client::factory()->create(['email' => 'cliente@x.test']);
    $this->post(route('sales.statements.send', $client), ['to' => ['cliente@x.test']])->assertRedirect()->assertSessionHas('error', 'Google rechazó la conexión con Gmail (invalid_grant). Reconecta tu cuenta de Gmail.');
    expect(SentEmail::firstOrFail()->status)->toBe('failed');
});
