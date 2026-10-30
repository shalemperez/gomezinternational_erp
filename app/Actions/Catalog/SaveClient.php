<?php

namespace App\Actions\Catalog;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClient
{
    public function __construct(private readonly ApplyTaxIdOverride $applyOverride, private readonly ReplaceContacts $replaceContacts) {}

    /** @param array<string, mixed> $data validated StoreClientRequest/UpdateClientRequest data */
    public function handle(array $data, User $actor, ?Client $client = null): Client
    {
        if (! $actor->can('catalog.clients.view-all')) {
            $salesperson = $actor->salesperson ?? throw ValidationException::withMessages(['salesperson_id' => 'Tu usuario no está vinculado a un vendedor.']);
            $data['salesperson_id'] = $salesperson->id;
        }

        return DB::transaction(function () use ($data, $actor, $client): Client {
            $client ??= new Client;
            $attributes = Arr::except($data, ['contacts', 'tax_id_override', 'tax_id_override_reason']);
            if (blank($attributes['code'] ?? null)) {
                unset($attributes['code']); // blank code on update keeps the existing one
            }
            $client->fill($attributes);
            if (! $client->exists && blank($client->code)) {
                // Reserve the id from the table's own sequence so the code is known before the single INSERT (race-safe, one audit row).
                /** @var int<1, max> $reservedId */
                $reservedId = (int) DB::scalar("select nextval(pg_get_serial_sequence('clients', 'id'))");
                $client->id = $reservedId;
                $client->code = 'CLI-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT);
            }
            $client->save();
            $this->applyOverride->handle($client, $data, $actor, 'clients');
            $this->replaceContacts->handle($client->contacts(), $data['contacts'] ?? []);

            return $client;
        });
    }
}
