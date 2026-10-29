# Gomez International — Sistema de Gestión y Control (ERP)

Fecha: 2026-10-29
Estado: borrador para aprobación

Convención: **[Decidido]** = lo dijo el usuario. **[Llamada de Claude]** = decisión tomada por Claude durante el diseño, el usuario puede revertirla. **[Al planificador]** = queda a criterio del planificador (Fable), que debe dejarlo explícito en el plan.

---

## 1. Intención y por qué

Gomez International es una empresa de **República Dominicana** que **importa marcas/productos y los vende a distribuidores locales**, con **almacén propio**. Hoy toda la operación vive dispersa en **correos de Google Workspace** y **archivos Excel** (contenido de los Excel aún no conocido). No hay una fuente de verdad: proformas, facturas, comisiones, cuentas y comunicaciones con clientes están en buzones y hojas sueltas.

Se necesita **un solo sistema** donde la dueña, vendedores, contadora y almacén operen todo el ciclo: compra en el exterior → importación → inventario → proforma → factura electrónica (e-CF) → despacho → cobro → comisión → contabilidad → reportes fiscales. Además, ver **toda la comunicación con cada cliente** (correos de todos los empleados + datos comerciales) en un lugar.

Hay **presión de tiempo**: el usuario quiere el sistema completo. Además, la empresa **debe empezar a emitir e-CF lo antes posible** (hoy no los emite).

**Éxito** = la empresa deja de depender de Excel y búsquedas en el correo; emite e-CF válidos ante la DGII; la contadora lleva la contabilidad completa dentro del sistema y saca 606/607/608 desde ahí; la dueña ve ventas, cobros, inventario y comisiones al día.

## 2. Usuarios

- Máximo **10 usuarios** **[Decidido]**.
- Roles **[Llamada de Claude]**:
  - **Super Admin (soporte)** **[Decidido]**: rol del desarrollador para administrar cualquier cosa y dar soporte. Acceso total, incluida la configuración técnica (integraciones, certificados, colas, bitácoras de errores, modo fiscal, importaciones) y la opción de "entrar como" otro usuario para reproducir problemas (registrado en la auditoría). Solo un Super Admin puede crear, editar o quitar a otro Super Admin; la Dueña no puede quitarlo **[Llamada de Claude]**.
  - **Dueña**: todo lo del negocio, incluida la configuración (e-CF, comisiones, usuarios), excepto la gestión de Super Admins.
  - **Gerencia**: todo excepto configuración del sistema y usuarios.
  - **Ventas (vendedor)**: sus clientes, sus proformas/facturas, su comisión; ve inventario disponible; no ve costos ni márgenes ni contabilidad.
  - **Contabilidad**: contabilidad, facturación, cobros, pagos, compras, fiscal, reportes; no administra usuarios.
  - **Almacén**: recepciones, despachos, inventario, transferencias; no ve precios de costo ni contabilidad.
- Un usuario puede tener varios roles. Permisos granulares por acción.

## 3. Objetivos (alcance completo)

Todos los módulos están en alcance **[Decidido: "necesito tener todo"]**. Se entregan por fases (sección 9), pero se diseñan todos desde el inicio.

### 3.1 Acceso y seguridad
- Login **solo con Google Workspace** de la empresa (OAuth), restringido al dominio de la empresa (validar el claim `hd` del token en servidor, no solo en el parámetro de la URL) **[Decidido: auth Google Workspace]**.
- Usuarios deben existir/estar activos en el sistema; el admin los invita por correo. Login con cuenta del dominio no invitada → rechazado.
- Excepción: lista configurable de correos de Google **fuera del dominio** autorizados explícitamente (para el Super Admin de soporte, cuyo correo no es del dominio de la empresa) **[Llamada de Claude]**.
- Bitácora de auditoría de toda creación/edición/anulación en documentos y datos maestros (quién, cuándo, qué cambió).
- Documentos fiscales emitidos **no se editan ni borran**; se corrigen con notas de crédito/débito.

### 3.2 Datos maestros
- **Clientes (distribuidores)**: razón social, nombre comercial, RNC/cédula, tipo de contribuyente, dirección, contactos (varios, con correos), vendedor asignado, condiciones de pago (días de crédito), límite de crédito, lista de precios asignada, moneda preferida, estado. Validar RNC (9 dígitos) / cédula (11 dígitos) con dígito verificador.
- **Proveedores**: exterior y locales; país, moneda, contactos, condiciones de pago, RNC si es local.
- **Marcas** y **Productos**: SKU, código de barras, nombre, marca, categoría, unidad de medida, unidades por caja, peso, tasa de ITBIS (18% / 16% / exento), **impuestos adicionales** (ver abajo), indicador **"controla lotes y vencimiento"**, precio(s) de venta por lista de precios, código arancelario (opcional), activo/inactivo.
- **[Decidido]**: importan "de todo un poco"; el sistema debe soportar **cualquier tipo de producto**:
  - **ISC y otros impuestos selectivos** (alcoholes, tabaco, etc.): tabla de impuestos adicionales configurable (ISC específico por unidad/volumen/grado alcohólico, ISC ad valorem %, otros), asignables por producto; calculados por línea, incluidos en el XML del e-CF según la estructura DGII de impuestos adicionales, en PDFs, en contabilidad y en reportes fiscales. El planificador toma de la DGII las fórmulas y códigos vigentes.
  - Impuestos adicionales pagados en aduana entran al costo de importación o a cuentas de crédito fiscal según corresponda.
- **Listas de precios** (en DOP; opcionalmente USD).
- **Vendedores**: vinculados a usuario; reglas de comisión (3.8).
- **Almacenes** (al menos uno; soportar varios).
- **Monedas y tasas de cambio**: DOP (base) y USD; tasa diaria con **fuente configurable** **[Decidido]**: **manual** (predeterminado) o **servicio externo** (p. ej. tasa publicada por el Banco Central de la RD u otra API; implementado como conector intercambiable). Con servicio externo, una tarea programada la obtiene cada día; si falla, alerta y permite registro manual; el admin siempre puede corregir la tasa del día. Cada tasa guarda su origen.
- **Cuentas bancarias** y cajas.

### 3.3 Ventas
Flujo: **Proforma → (aprobada) → Factura (NCF tradicional o e-CF, ver 3.4.1) → Conduce/Despacho → Cobro**.
- **Proforma (cotización)**: cliente, vendedor, fecha, validez, moneda, líneas (producto, cantidad, precio, descuento %, ITBIS), totales, notas, estado (borrador, enviada, aprobada, rechazada, vencida, facturada). PDF con marca de la empresa. Enviar por correo desde el sistema (3.10). Duplicar proforma. Una proforma puede convertirse en factura total o parcialmente.
- **Factura**: se genera desde proforma o directa. Al confirmarla se emite el e-CF (3.4). Verifica límite de crédito (advertencia; admin/gerencia puede autorizar el exceso). Tipo de e-CF según cliente (E31 crédito fiscal si tiene RNC y lo solicita; E32 consumo en otro caso).
- **Conduce / despacho**: salida de almacén ligada a la factura; descuenta inventario; soporta despachos parciales; PDF de conduce.
- **Notas de crédito** (devoluciones, descuentos) y **notas de débito**, como e-CF E34/E33 referenciando la factura original; las devoluciones reingresan inventario.
- **Cobros**: recibos de ingreso aplicados a una o varias facturas; pago parcial; formas de pago (transferencia, cheque, efectivo, tarjeta); cuenta bancaria destino; retenciones que aplique el cliente (ITBIS/ISR retenido). Saldo a favor (anticipos).
- **Estado de cuenta del cliente** (PDF/Excel, enviable por correo) y **antigüedad de saldos** (0-30, 31-60, 61-90, +90).

### 3.4 Factura electrónica DGII (e-CF)
**[Decidido]**: la empresa aún no emite e-CF; debe hacerlo cuanto antes; probablemente se integrará **directo con la DGII** para evitar mensualidad, pero se deja **también abierta la opción de un proveedor certificado (PSFE, p. ej. Alanube)** hasta que la dueña decida.

Diseño **[Llamada de Claude]**:
- Interfaz única `EcfProvider` (contrato) con implementaciones intercambiables por configuración:
  1. **`DgiiDirect`** — se construye completo primero.
  2. **`Psfe` (Alanube)** — se construye la estructura (clase, configuración, mapeo de datos) y se completa si se elige esa ruta.
  3. **`Fake`** — para desarrollo y pruebas automatizadas; simula respuestas aceptadas/rechazadas.
- El documento de venta se construye de forma independiente del proveedor; el proveedor solo convierte, firma/envía y devuelve: e-NCF, estado, código de seguridad, fecha de firma, URL/datos del QR, mensajes de error.
- **Tipos soportados**: E31 (crédito fiscal), E32 (consumo), E33 (nota de débito), E34 (nota de crédito). Además, para compras y gastos: E41 (compras, a proveedores informales), E43 (gastos menores), E47 (pagos al exterior) — estos se emiten desde el módulo de compras. E44/E45/E46 configurables pero no prioritarios.
- **Secuencias e-NCF** por tipo: rango autorizado por la DGII (desde/hasta, fecha de vencimiento), alerta al quedar <10% o al acercarse el vencimiento; nunca reutilizar un número.
- **`DgiiDirect`** debe implementar, según la **documentación técnica vigente de la DGII** (el planificador debe consultarla y citar versiones/URLs en el plan; no inventar formatos):
  - Gestión del **certificado digital** de la empresa (.p12) cargado por el admin, guardado cifrado; contraseña cifrada.
  - Autenticación por semilla (obtener semilla, firmarla, validarla, obtener token; reusar token hasta que venza).
  - Generación del XML del e-CF según el XSD oficial de cada tipo, validación contra XSD antes de enviar, firma XMLDSig.
  - Envío a recepción, almacenamiento del TrackId, consulta de estado (en cola con reintentos) hasta Aceptado / Aceptado condicional / Rechazado.
  - Facturas de consumo bajo el umbral que fije la DGII: flujo de Resumen (RFCE) correspondiente.
  - Ambientes configurables: pruebas, certificación, producción.
  - **Rol de receptor electrónico**: los emisores electrónicos también reciben e-CF de sus proveedores; implementar los servicios web de recepción, acuse de recibo y aprobación/rechazo comercial que exija la DGII, y registrar los e-CF recibidos para cargarlos como compras/gastos.
  - **Representación impresa** (PDF) con e-NCF, código de seguridad, fecha de firma y **código QR** según especificación DGII.
  - **Pantalla de certificación**: ayuda a ejecutar el set de pruebas que exige la DGII para autorizar al emisor (generar los documentos solicitados, ver resultados).
  - **Contingencia**: si la DGII no responde, la factura queda en estado "pendiente de envío" y se reintenta automáticamente; el usuario lo ve claramente.
- Guardar siempre el XML firmado enviado y las respuestas de la DGII (archivos inmutables).
- **Fuera del sistema** (tarea de la empresa, en paralelo): solicitar a la DGII la autorización como emisor electrónico, obtener el certificado digital, completar el proceso de certificación.

#### 3.4.1 NCF tradicionales (transición)
**[Decidido]**: hoy la empresa factura con **NCF tradicionales**. El sistema debe facturar con ellos desde su primer día en producción y pasar a e-CF cuando la DGII autorice.
- Modo fiscal de la empresa configurable por el admin: **`ncf` (tradicional)** o **`ecf` (electrónico)**, con fecha de cambio. Un documento conserva para siempre el modo con el que se emitió.
- Tipos tradicionales: B01 (crédito fiscal), B02 (consumo), B03 (nota de débito), B04 (nota de crédito), B14 (regímenes especiales), B15 (gubernamental); para compras/gastos: B11 (comprobante de compras), B13 (gastos menores), B17 (pagos al exterior). El planificador verifica la lista vigente en la DGII.
- Secuencias autorizadas por tipo (formato B + 2 dígitos + 8 dígitos), con fecha de vencimiento y alertas, igual que las e-NCF.
- Impresión en PDF con NCF y fecha de vencimiento de la secuencia según los requisitos de la DGII (no hay envío a la DGII; se reporta en 607/608).
- Implementación **[Llamada de Claude]**: un servicio de numeración fiscal (`FiscalNumberingService`) que, según el modo, asigna un NCF tradicional o delega en el `EcfProvider`. La factura, notas y reportes 606/607/608 soportan ambos tipos desde el inicio.
- Notas de crédito/débito pueden referenciar facturas con NCF tradicional aun después del cambio a e-CF (según lo que permita la DGII; el planificador lo verifica).
- Urgencia: según la Ley 32-23, los plazos de adopción del e-CF habrían vencido para todos los contribuyentes; la contadora debe confirmarlo. Esto no cambia el diseño, solo hace prioritaria la certificación.

### 3.5 Compras e importaciones
- **Órdenes de compra** a proveedores (normalmente en USD): líneas, precios, estado (borrador, enviada, confirmada, en tránsito, recibida parcial/total, cerrada). PDF y envío por correo.
- **Expediente de importación (embarque)**: agrupa una o varias órdenes de compra; datos de embarque (BL, contenedor, naviera, fechas estimadas/reales, puerto), documentos adjuntos (factura comercial, BL, declaración aduanal, packing list).
- **Costos de importación**: flete, seguro, aranceles/gravámenes, ITBIS de aduana (crédito fiscal, no costo), agente aduanal, almacenaje portuario, transporte local, otros. Cada costo con proveedor, moneda y tasa.
- **Liquidación del expediente**: prorratea costos adicionales a cada línea → costo unitario aterrizado en DOP. Método de prorrateo seleccionable por expediente: **por valor FOB (predeterminado)**, por peso, por cantidad **[Llamada de Claude]**.
- **Recepción en almacén** desde el expediente (parcial o total); al liquidar se ajusta el costo del inventario.
- **Facturas de proveedores / cuentas por pagar**: registrar facturas de proveedores locales (con NCF/e-NCF del proveedor) y del exterior; pagos (parciales), retenciones de ITBIS/ISR cuando aplique; antigüedad de cuentas por pagar.
- **Gastos** (no inventario): registro con NCF del suplidor, categoría contable, ITBIS, retenciones.

### 3.6 Inventario
- Existencias por producto y almacén; movimientos (entrada por compra, salida por despacho, devolución, ajuste, transferencia entre almacenes) con kardex por producto.
- Valoración por **costo promedio ponderado** **[Llamada de Claude]**.
- Inventario **reservado** por facturas no despachadas; **disponible** = existencia − reservado.
- Conteo físico (toma de inventario) con ajuste y asiento contable automático.
- Alertas de stock mínimo por producto.
- **Lotes y vencimientos** **[Decidido: cualquier tipo de producto]**: para productos marcados "controla lotes", cada entrada registra lote y fecha de vencimiento; existencias por lote y almacén; despacho sugiere **FEFO** (primero en vencer, primero en salir) y permite elegir lote; alerta de productos próximos a vencer (días configurables); reporte de inventario por lote/vencimiento; trazabilidad lote → facturas/clientes. Productos sin esa marca funcionan sin lotes.

### 3.7 Contabilidad
**[Decidido]**: la contadora (empleada) llevará **toda la contabilidad dentro del sistema**. Hoy no se sabe cómo la lleva.
- **Catálogo de cuentas** jerárquico, con plantilla inicial típica dominicana (activos, pasivos, patrimonio, ingresos, costos, gastos), editable.
- **Partida doble**; asientos **automáticos** desde: facturas, notas de crédito/débito, cobros, compras, liquidación de importación, pagos, gastos, despachos (costo de venta), ajustes de inventario, comisiones, diferencia cambiaria. **Asientos manuales** con adjuntos.
- Mapeo configurable de cuentas contables por evento (cuenta de ventas, ITBIS por pagar, ITBIS adelantado, CxC, CxP, inventario, costo de venta, etc.).
- **Multimoneda**: transacciones en USD con tasa de la fecha; diferencia cambiaria realizada al cobrar/pagar.
- **Períodos fiscales** mensuales: cierre de mes (bloquea registrar en períodos cerrados salvo admin), cierre anual.
- **Bancos**: movimientos por cuenta, conciliación bancaria (marcar movimientos conciliados contra el estado del banco; importar estado en Excel/CSV).
- Reportes: libro diario, mayor general, balanza de comprobación, **balance general**, **estado de resultados**, flujo de efectivo básico.
- **Fiscal DGII**: generación de archivos **606** (compras), **607** (ventas), **608** (comprobantes anulados), **609** (pagos al exterior) en el formato TXT vigente de la DGII; resumen para el **IT-1** (ITBIS) e **IR-17** (retenciones). El planificador debe tomar los formatos de la documentación oficial vigente.
- **Saldos iniciales**: herramienta para cargar balance de apertura y saldos de clientes/proveedores (fecha de inicio a definir con la contadora).

### 3.8 Comisiones
**[Decidido]**: configurables; no se sabe cómo se calculan hoy.
- Reglas: porcentaje por vendedor, con posible porcentaje distinto por marca (la regla más específica gana: vendedor+marca > vendedor > global).
- **[Decidido: todo configurable]** Momento: **al cobrar** (predeterminado) o al facturar. Base: **monto sin impuestos** (predeterminado), monto con ITBIS, o monto con todos los impuestos; opción de restar descuentos. Configurable a nivel global y, opcionalmente, por regla (vendedor/marca). Notas de crédito restan comisión. Un cambio de configuración aplica desde su fecha de vigencia, sin recalcular comisiones ya liquidadas.
- Liquidación periódica (mensual): reporte por vendedor con detalle de facturas/cobros, aprobación por admin/gerencia, marcar como pagada, asiento contable.
- El vendedor ve su comisión acumulada y pendiente.

### 3.9 Ficha 360° del cliente (CRM ligero)
**[Decidido]**: "necesito poder ver todas las comunicaciones con el cliente, correos y datos".
- Una pantalla por cliente con: datos y contactos, saldo y antigüedad, proformas, facturas, cobros, notas de crédito, despachos, productos más comprados, **línea de tiempo de correos** (de todos los usuarios de la empresa con cualquier contacto/dominio del cliente), notas internas y adjuntos.
- Lo mismo para proveedores (órdenes, expedientes, facturas, pagos, correos).

### 3.10 Integración con Gmail
- **Enviar** proformas, facturas (PDF + XML del e-CF), estados de cuenta y órdenes de compra **desde el Gmail del usuario que envía** (Gmail API), con plantillas editables; el correo enviado queda registrado en la ficha.
- **Leer** correos: sincronización periódica (en cola) de mensajes de los buzones de los usuarios de la empresa cuyo remitente/destinatario coincide con correos o dominios de clientes/proveedores registrados; guardar metadatos, extracto y enlace al hilo de Gmail; adjuntos bajo demanda.
- Requiere **delegación de dominio** (cuenta de servicio con acceso autorizado por el administrador de Google Workspace) para leer los buzones de todos los usuarios. **[Decidido]**: el usuario hoy no tiene acceso de administrador, pero puede conseguirlo. El sistema debe funcionar sin esto (login por OAuth normal) y activar la lectura de correos cuando se configure.
- Configuración de qué buzones se sincronizan (excluir buzones personales sensibles si la dueña lo pide).

### 3.11 Reportes y exportación
- **Toda tabla** del sistema exportable a **Excel (.xlsx)** y **CSV**; documentos y reportes clave también a **PDF** **[Decidido: exportación de datos]**.
- Reportes mínimos: ventas por período / cliente / vendedor / marca / producto; margen bruto por producto/marca/cliente (solo roles con acceso a costos); cuentas por cobrar y por pagar con antigüedad; inventario valorizado y kardex; rotación de inventario; costo por expediente de importación; comisiones; estados financieros; reportes fiscales (3.7).
- Filtros por fechas, cliente, vendedor, marca, almacén, moneda.
- **Panel principal** (dashboard) por rol: ventas del mes vs. mes anterior, cobros, cuentas por cobrar vencidas, stock bajo, expedientes en tránsito, e-CF rechazados/pendientes, comisiones pendientes.
- Reportes pesados se generan en cola y se notifica al terminar.

### 3.12 Migración de datos
- Importadores desde Excel/CSV para: clientes, proveedores, productos, listas de precios, existencias iniciales con costo, saldos iniciales de CxC/CxP, catálogo de cuentas y balance de apertura.
- Plantillas descargables, vista previa con validación y errores por fila antes de confirmar.
- **Arranque con documentos previos** **[Llamada de Claude — el usuario aún no tiene el dato; se elige la opción más completa]**: se soportan las dos formas y la contadora escoge al migrar:
  - **Facturas abiertas una por una** (recomendado): tipo de documento "factura histórica registrada externamente" con su NCF original, cliente, vendedor, fecha, vencimiento, montos e ITBIS; no se reemite ni se envía a la DGII ni entra al 607 (ya fue reportada fuera del sistema); permite cobrarla, aplicarle retenciones, notas de crédito y comisión al cobrar.
  - **Saldo único por cliente/proveedor** como alternativa.
  - Lo mismo para facturas de proveedores pendientes de pago.
  - La fecha de corte puede ser cualquier día; si cae a mitad de mes, la contadora puede registrar en el sistema los comprobantes del mes emitidos fuera (marcados como externos) para que el 606/607/608 de ese mes salga completo.
- **Pendiente**: el usuario conseguirá los Excel actuales y ejemplos de proformas/facturas; cuando estén en el repositorio se revisan para ajustar campos y plantillas de importación.

### 3.13 Reglas menores fijadas
- Precios de listas y líneas **sin ITBIS**; el ITBIS se calcula por línea según la tasa del producto **[Llamada de Claude]**.
- "Proforma aprobada" = el cliente la aceptó; el vendedor marca la aprobación en el sistema (con fecha y, opcionalmente, el correo o documento adjunto) **[Llamada de Claude]**.
- Correos sincronizados en la ficha 360°: los ve el vendedor asignado al cliente, Gerencia y Admin; Contabilidad ve los de clientes y proveedores; nunca se muestran correos de buzones excluidos **[Llamada de Claude]**.

### 3.14 Generales
- Interfaz en **español**; código, nombres de tablas y clases en **inglés** **[Llamada de Claude]**.
- Zona horaria `America/Santo_Domingo`; moneda base DOP; formato de números dominicano.
- Adjuntos en cualquier documento.
- Numeración interna propia para proformas, órdenes de compra, recibos, conduces (independiente del e-NCF).
- Responsive (usable en tablet/celular para vendedores y almacén), pero optimizado para escritorio.

## 4. No-objetivos (esta versión)
- App móvil nativa.
- Portal de clientes / tienda en línea B2B (rol Cliente: catálogo con sus precios, pedidos que llegan como proforma al vendedor, facturas y estado de cuenta) **[Decidido: fuera por ahora, posible fase futura]**. El diseño no debe impedirlo: los clientes ya existen como entidad y el login debe poder extenderse a usuarios externos.
- Tienda pública para consumidores finales.
- Nómina / recursos humanos.
- Lectura automática de pedidos desde correos con conversión a proformas.
- Integración bancaria automática (APIs de bancos); la conciliación es con importación de archivos.
- Multiempresa.

## 5. Anti-objetivos (sería un fracaso aunque "funcione")
- Emitir un e-CF incorrecto o duplicar/saltar un e-NCF; permitir editar/borrar documentos fiscales emitidos.
- Asientos contables descuadrados o contabilidad que no cuadra con facturación/inventario.
- Que un vendedor vea costos, márgenes, contabilidad o clientes de otros vendedores.
- Que alguien fuera del dominio de la empresa pueda entrar.
- Perder datos (sin respaldos) o guardar el certificado digital / tokens en texto plano.
- Que sea más lento o engorroso que el Excel para facturar: crear proforma y convertirla en factura debe tomar pocos pasos.

## 6. Restricciones
- **País**: República Dominicana; normativa DGII (e-CF, NCF, 606/607/608/609, IT-1, IR-17, ITBIS 18%/16%/exento, retenciones).
- **Plazo**: presión de tiempo; e-CF prioritario.
- **Autenticación**: Google Workspace **[Decidido]**.
- **Datos actuales**: en Gmail y Excel; contenido de Excel desconocido.
- **Acceso de admin de Google Workspace**: aún no disponible; se conseguirá.
- **Proceso de desarrollo [Decidido por el usuario]**:
  - La **planificación la hace un agente Fable**. El plan debe ser **100% explícito**: rutas de archivos, nombres de clases/métodos, esquemas de tablas con columnas/tipos/índices, reglas de negocio con fórmulas, rutas, permisos, textos de UI, comandos a ejecutar y pruebas exactas con resultados esperados. **Nada** queda a decisión de quien implementa.
  - La **implementación la hacen solo agentes Haiku**, siguiendo el plan sin improvisar.
  - Fuentes de agentes, en orden: cuenta personal de Claude (`claude --personal`), cuenta de Antigravity (`agy`), y solo si se agotan los tokens, esta cuenta. Los programadores siempre Haiku.
  - Usar **Laravel Boost** al 100% (MCP y guías) durante planificación e implementación.

## 7. Decisiones de cómo se construye

- **Backend**: Laravel (última versión estable) **[Llamada de Claude; el usuario preguntó Astro vs Laravel y se recomendó Laravel por: auth, colas, PDFs, Excel, permisos, contabilidad y ecosistema maduro; Astro no es adecuado como backend de un ERP]**.
- **Frontend**: React con TypeScript vía **Inertia** (kit oficial de inicio de Laravel con React), Tailwind y componentes shadcn/ui **[Llamada de Claude]**.
- **Base de datos**: PostgreSQL **[Llamada de Claude]**.
- **Dinero**: columnas decimales (montos 2 decimales, cantidades y costos unitarios hasta 4, tasas de cambio 6); nunca float; cálculos con aritmética decimal exacta **[Llamada de Claude]**.
- **Colas**: para envío e-CF, consulta de estado DGII, sincronización Gmail, reportes pesados, correos.
- **Pruebas**: Pest; pruebas de cada regla de negocio, cálculos fiscales, contables y del contrato `EcfProvider` con `Fake`; validación de XML contra XSD oficiales.
- **Alojamiento**: servidor VPS (DigitalOcean o similar) administrado con **Laravel Forge**, HTTPS, respaldos diarios de base de datos y archivos fuera del servidor **[Llamada de Claude]**. Endpoints públicos HTTPS requeridos para el rol de receptor e-CF.
- **Repositorio**: git en este directorio (`/Users/shalemperez/www/gomezintsystem`).

## 8. Integraciones externas
| Integración | Uso | Requisito |
|---|---|---|
| Google OAuth (Workspace) | Login restringido al dominio | Proyecto en Google Cloud; cliente OAuth |
| Gmail API | Enviar como usuario; leer buzones | Delegación de dominio aprobada por admin de Workspace |
| DGII e-CF | Emisión/recepción directa | Autorización como emisor electrónico; certificado digital; certificación |
| PSFE (Alanube u otro) | Alternativa de emisión | Contrato y credenciales API (si se elige) |

## 9. Orden de entrega (fases)
**[Decidido]**: sin fecha límite aún, pero "lo antes posible". **[Llamada de Claude]**: cada fase sale a producción al terminarse y debe ser usable por sí sola; el plan define el comportamiento intermedio de cada fase. Orden:
1. **Base**: proyecto, Laravel Boost, auth Google, usuarios/roles/permisos, auditoría, datos maestros (incluidos impuestos adicionales y lotes), monedas/tasas, importadores Excel de maestros.
2. **Ventas con NCF tradicional + inventario básico** (primera salida a producción para facturar): proformas, facturas, notas, cobros, estados de cuenta, numeración NCF tradicional, PDFs, envío por correo (Gmail API de envío), almacenes, existencias iniciales (con lotes), reservas, despachos/conduces, devoluciones, importación de facturas abiertas históricas. Registro de eventos contables para generar asientos al activar la fase 4.
3. **e-CF**: `EcfProvider` (`Fake` + `DgiiDirect` + estructura `Psfe`), rol de receptor, pantalla de certificación, cambio de modo `ncf` → `ecf`. (Puede avanzar en paralelo con la fase 2 si hay agentes disponibles.)
4. **Compras e importaciones**: órdenes de compra, expedientes, liquidación, recepciones, ajustes y conteo de inventario, CxP, gastos, comprobantes de compras (B11/B13/B17 y E41/E43/E47), registro de e-CF recibidos.
5. **Contabilidad y fiscal**: catálogo, asientos automáticos (incluida generación retroactiva desde los eventos registrados) y manuales, períodos, bancos/conciliación, estados financieros, 606/607/608/609, IT-1/IR-17, saldos iniciales.
6. **Comisiones**.
7. **Ficha 360° y lectura de Gmail**.
8. **Reportes y paneles**.

## 10. Lo que queda al planificador (Fable)
- Estructura de módulos/carpetas, esquema completo de base de datos, nombres, rutas y componentes — **todo debe quedar explícito en el plan** para los Haiku.
- Selección exacta de paquetes (p. ej. permisos, auditoría, Excel, PDF, firma XML, Google API) y sus versiones, verificando compatibilidad con la versión de Laravel.
- Formatos exactos de XML e-CF, servicios web, QR y archivos 606/607/608/609 tomados de la documentación oficial vigente de la DGII (citando fuente).
- Plantilla inicial del catálogo de cuentas y mapeo contable por evento.
- División de cada fase en tareas pequeñas, independientes y verificables, con pruebas.
- Diseño visual de la interfaz dentro del kit React/shadcn.

## 11. Pendientes externos (no bloquean el diseño)
- Excel actuales y ejemplos de proformas/facturas → ajustar importadores y PDFs.
- Acceso de administrador de Google Workspace → delegación de dominio.
- Trámites DGII de la empresa (emisor electrónico, certificado digital).
- Decisión final: DGII directo vs. PSFE.
- Cómo calcula hoy las comisiones y cómo lleva la contabilidad la contadora (fecha de corte para saldos iniciales).
- Forma de migrar facturas abiertas (una por una vs. saldo) — ambas soportadas.
- Confirmar con la contadora el estado ante la Ley 32-23 (plazos e-CF).
