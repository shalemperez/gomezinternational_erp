<?php

use App\Enums\EcfEnvironment;
use App\Enums\EcfStatus;
use App\Enums\NcfType;

test('electronic ncf types map from the traditional series and expose their dgii code', function () {
    expect(NcfType::B01->electronicCounterpart())->toBe(NcfType::E31)
        ->and(NcfType::B02->electronicCounterpart())->toBe(NcfType::E32)
        ->and(NcfType::B03->electronicCounterpart())->toBe(NcfType::E33)
        ->and(NcfType::B04->electronicCounterpart())->toBe(NcfType::E34)
        ->and(NcfType::B14->electronicCounterpart())->toBe(NcfType::E44)
        ->and(NcfType::B15->electronicCounterpart())->toBe(NcfType::E45)
        ->and(NcfType::E31->electronicCounterpart())->toBe(NcfType::E31)
        ->and(NcfType::E31->ecfCode())->toBe('31')->and(NcfType::B01->ecfCode())->toBeNull()
        ->and(NcfType::E31->isElectronic())->toBeTrue()->and(NcfType::B01->isElectronic())->toBeFalse()
        ->and(NcfType::E31->label())->toBe('Factura de Crédito Fiscal Electrónica')
        ->and(NcfType::E34->label())->toBe('Nota de Crédito Electrónica');
});

test('electronic consumo and credit notes never expire and sequences have ten digits', function () {
    expect(NcfType::E32->expires())->toBeFalse()->and(NcfType::E34->expires())->toBeFalse()
        ->and(NcfType::E31->expires())->toBeTrue()->and(NcfType::E33->expires())->toBeTrue()
        ->and(NcfType::E31->sequenceDigits())->toBe(10)->and(NcfType::B01->sequenceDigits())->toBe(8)
        ->and(NcfType::E31->maxSequence())->toBe(9999999999)->and(NcfType::B01->maxSequence())->toBe(99999999)
        ->and(NcfType::E44->isSalesType())->toBeTrue()->and(NcfType::E41->isSalesType())->toBeFalse();
});

test('ecf environment segments and status codes follow the dgii documentation', function () {
    expect(EcfEnvironment::Test->dgiiSegment())->toBe('testecf')->and(EcfEnvironment::Cert->dgiiSegment())->toBe('certecf')->and(EcfEnvironment::Prod->dgiiSegment())->toBe('ecf')
        ->and(EcfStatus::fromDgiiCode(1))->toBe(EcfStatus::Accepted)->and(EcfStatus::fromDgiiCode(2))->toBe(EcfStatus::Rejected)
        ->and(EcfStatus::fromDgiiCode(3))->toBe(EcfStatus::InProcess)->and(EcfStatus::fromDgiiCode(4))->toBe(EcfStatus::ConditionallyAccepted)
        ->and(EcfStatus::fromDgiiCode(0))->toBe(EcfStatus::Sent)
        ->and(EcfStatus::Rejected->isFinal())->toBeTrue()->and(EcfStatus::Sent->isFinal())->toBeFalse()
        ->and(EcfStatus::ConditionallyAccepted->isValidForTax())->toBeTrue()->and(EcfStatus::Rejected->isValidForTax())->toBeFalse()
        ->and(EcfStatus::Signed->label())->toBe('Firmado, pendiente de envío');
});
