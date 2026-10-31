<?php

namespace App\Support\Ecf;

final class CertificationChecklist
{
    /**
     * Ordered DGII certification steps for the given provider (RES §10). Each step is done by the company ('empresa')
     * or by the ERP ('sistema').
     *
     * @return list<array{key: string, title: string, description: string, who: 'empresa'|'sistema'}>
     */
    public static function steps(string $provider): array
    {
        return $provider === 'alanube' ? self::psfeSteps() : self::dgiiSteps();
    }

    /** @return list<array{key: string, title: string, description: string, who: 'empresa'|'sistema'}> */
    private static function dgiiSteps(): array
    {
        return [
            ['key' => 'solicitud', 'title' => 'Solicitud en Oficina Virtual (formulario FI-GDF-016)', 'description' => 'La empresa presenta la solicitud para ser emisor electrónico en la Oficina Virtual de la DGII y recibe las credenciales del Portal de Certificación.', 'who' => 'empresa'],
            ['key' => 'postulacion', 'title' => 'Postulación en el Portal de Certificación (URLs de recepción/aprobación)', 'description' => 'La empresa crea la postulación como emisor electrónico, registra las URLs de recepción y aprobación, firma el archivo generado y lo sube al portal.', 'who' => 'empresa'],
            ['key' => 'pruebas_datos', 'title' => 'Pruebas de datos (set de pruebas e-CF y aprobaciones comerciales)', 'description' => 'El sistema genera los e-CF del set de pruebas de la DGII, los envía a CerteCF y completa el set de aprobaciones y rechazos comerciales.', 'who' => 'sistema'],
            ['key' => 'pruebas_simulacion', 'title' => 'Pruebas de simulación (e-CF con datos reales)', 'description' => 'El sistema emite e-CF representativos con datos reales de la empresa para verificar el formato antes de la certificación.', 'who' => 'sistema'],
            ['key' => 'ri', 'title' => 'Validación de la representación impresa (PDF ≤ 10 MB)', 'description' => 'La empresa sube a la DGII los PDF de la representación impresa (≤ 10 MB) para su validación de formato.', 'who' => 'empresa'],
            ['key' => 'comunicacion', 'title' => 'Pruebas de comunicación (recepción de e-CF y aprobaciones de la DGII)', 'description' => 'La DGII envía e-CF a la URL de recepción de la empresa, que responde con ARECF, y luego envía los ACECF que el sistema debe registrar.', 'who' => 'sistema'],
            ['key' => 'url_produccion', 'title' => 'URL de servicios de producción', 'description' => 'La empresa registra en el portal las URLs de servicios de producción de su software de facturación.', 'who' => 'empresa'],
            ['key' => 'declaracion', 'title' => 'Declaración jurada firmada', 'description' => 'El representante de la empresa firma la declaración jurada en XML y la presenta en la DGII.', 'who' => 'empresa'],
            ['key' => 'certificacion', 'title' => 'Certificación otorgada y menú Facturación Electrónica habilitado', 'description' => 'La DGII otorga la certificación y habilita el menú Facturación Electrónica en la Oficina Virtual de la empresa.', 'who' => 'empresa'],
        ];
    }

    /** @return list<array{key: string, title: string, description: string, who: 'empresa'|'sistema'}> */
    private static function psfeSteps(): array
    {
        return [
            ['key' => 'solicitud', 'title' => 'Solicitud en Oficina Virtual (formulario FI-GDF-016)', 'description' => 'La empresa presenta la solicitud para ser emisor electrónico en la Oficina Virtual de la DGII y recibe las credenciales del Portal de Certificación.', 'who' => 'empresa'],
            ['key' => 'postulacion_psfe', 'title' => 'Postulación indicando el proveedor certificado', 'description' => 'La empresa crea la postulación como emisor electrónico indicando el PSFE certificado como tipo de software y sus datos de proveedor.', 'who' => 'empresa'],
            ['key' => 'pruebas_simulacion', 'title' => 'Pruebas de simulación (e-CF con datos reales)', 'description' => 'El sistema emite e-CF representativos con datos reales a través del PSFE, incluidos los RFCE para consumo menor a RD$250,000.', 'who' => 'sistema'],
            ['key' => 'url_produccion', 'title' => 'URL de servicios de producción', 'description' => 'La empresa registra en el portal las URLs de servicios de producción de su software de facturación.', 'who' => 'empresa'],
            ['key' => 'declaracion', 'title' => 'Declaración jurada firmada', 'description' => 'El representante de la empresa firma la declaración jurada en XML y la presenta en la DGII.', 'who' => 'empresa'],
            ['key' => 'certificacion', 'title' => 'Certificación otorgada y menú Facturación Electrónica habilitado', 'description' => 'La DGII otorga la certificación y habilita el menú Facturación Electrónica en la Oficina Virtual de la empresa.', 'who' => 'empresa'],
        ];
    }
}
