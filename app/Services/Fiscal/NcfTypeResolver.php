<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Enums\TaxpayerType;
use App\Models\Client;

class NcfTypeResolver
{
    /** Spec §3.3: crédito fiscal when the client has an RNC/cédula and is a persona jurídica/física; régimen especial → B14; gubernamental → B15; otherwise consumo. */
    public function forSale(Client $client): NcfType
    {
        return match ($client->taxpayer_type) {
            TaxpayerType::RegimenEspecial => NcfType::B14,
            TaxpayerType::Gubernamental => NcfType::B15,
            TaxpayerType::Juridica, TaxpayerType::Fisica => $client->tax_id ? NcfType::B01 : NcfType::B02,
            default => NcfType::B02,
        };
    }

    public function forCreditNote(): NcfType
    {
        return NcfType::B04;
    }

    public function forDebitNote(): NcfType
    {
        return NcfType::B03;
    }
}
