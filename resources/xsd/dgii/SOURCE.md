# Fuente de los XSD de la DGII

- Descargados el 2026-10-30 desde la Documentación Técnica (XSD) de la DGII:
  `https://dgii.gov.do/cicloContribuyente/facturacion/comprobantesFiscalesElectronicosE-CF/Documentacin%20sobre%20eCF/Documentaci%C3%B3n%20T%C3%A9cnica%20(XSD)/`
- Página de documentación: `https://dgii.gov.do/cicloContribuyente/facturacion/comprobantesFiscalesElectronicosE-CF/Paginas/documentacionSobreE-CF.aspx`
- Fechas de página (tabla de cabecera RES):
  - `e-CF 31`, `32`, `41`, `43`, `44`, `45`, `46`, `47`: 16/10/2025
  - `e-CF 33`, `34`: 01/04/2026
  - `RFCE 32`, `ARECF`, `ANECF`: 20/06/2023
  - `ACECF`: 21/12/2022
  - `Semilla`: 12/11/2020
- Copias textuales, sin modificar. Ver `docs/superpowers/research/2026-10-30-dgii-ecf.md` (§2.5).

## Local corrections

Two upstream typos prevent libxml from compiling the schemas. Corrected in place; no other edits.

1. `e-CF 31 v.1.0.xsd`, line 476
   - Original: `<xs:simpleType name=" IndicadorServicioTodoIncluidoType">`
   - Corrected: `<xs:simpleType name="IndicadorServicioTodoIncluidoType">`
   - Reason: upstream typo that prevents libxml from compiling the schema (leading space in the type name; the reference on line 18 cannot resolve, and every other e-CF XSD declares the name without the space).

2. `RFCE 32 v.1.0.xsd`, line 122
   - Original: `<xs:pattern value="\s*(3[01]|[12][$0-9]|0?[1-9])\-(1[012]|0?[1-9])\-((?:19|20)\d{2})\s*" />`
   - Corrected: `<xs:pattern value="\s*(3[01]|[12][0-9]|0?[1-9])\-(1[012]|0?[1-9])\-((?:19|20)\d{2})\s*" />`
   - Reason: upstream typo that prevents libxml from compiling the schema (`[$0-9]` is an invalid regex character class; the same date pattern in every other DGII XSD uses `[12][0-9]`).

3. `RFCE 32 v.1.0.xsd`, lines 122, 178, 219 and 227
   - Original: `(?:` (non-capturing group), e.g. `((?:19|20)\d{2})` on line 122 and `[0-9]{1,16}(?:.[0-9]{2})?` on lines 178, 219 and 227
   - Corrected: `(` (the same group without `?:`), e.g. `((19|20)\d{2})` on line 122 and `[0-9]{1,16}(.[0-9]{2})?` on lines 178, 219 and 227
   - Reason: XSD regex has no non-capturing groups; libxml rejects it. The group is not captured for use, so the match semantics are unchanged.

4. `ACECF v.1.0.xsd`, line 72
   - Original: `((?:20|20)\d{2})`
   - Corrected: `((20|20)\d{2})`
   - Reason: XSD regex has no non-capturing groups; libxml rejects it. The group is not captured for use, so the match semantics are unchanged.
