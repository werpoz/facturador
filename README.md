# Facturador electrónico Perú (Laravel 13 + Filament 4)

Proyecto base para emitir facturas, boletas y notas de crédito/débito electrónicas a través de un PSE
(pensado para **Gior Technology**, con un proveedor **simulado** para desarrollar sin credenciales).

## Requisitos
- PHP 8.3+ con extensiones `intl`, `bcmath` o `gmp` (recomendado), `pdo_mysql`/`pdo_sqlite`
- Composer 2, Node 20+ (opcional, solo si personalizas el tema)
- MySQL/PostgreSQL en producción (SQLite viene configurado para desarrollo)

## Instalación
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # crea la empresa demo
php artisan storage:link
npm install && npm run build   # compila el tema visual
composer run dev               # servidor + cola + logs
```
Entra a <http://localhost:8000/admin>. Usuarios de demostración (contraseña `password`):

| Correo | Rol | Puede |
|---|---|---|
| `admin@demo.pe` | Administrador (y superadmin) | Todo |
| `vendedor@demo.pe` | Vendedor | Emitir facturas y boletas, registrar clientes |
| `contador@demo.pe` | Contador | Solo consultar (ventas, comprobantes, bitácora) |

En un servidor real **no uses el seeder**: crea tu usuario con
```bash
php artisan facturador:crear-admin tu@correo.com --nombre="Tu Nombre"
```

> La **cola** debe estar corriendo (`php artisan queue:work`) para que los comprobantes se envíen al PSE.
> `composer run dev` ya la levanta. En producción usa Supervisor o Laravel Horizon.

## Qué incluye
| Módulo | Detalle |
|---|---|
| Multiempresa | Cada usuario puede tener varias empresas (RUC). Registro guiado que crea series F001/B001/FC01/BC01/FD01/BD01 y el cliente "CLIENTES VARIOS" |
| Comprobantes | Factura, boleta, nota de crédito y nota de débito. Ítems con cálculo de IGV en vivo, gravado/exonerado/inafecto/gratuito, contado o crédito |
| Emisión | Validaciones SUNAT antes de enviar, correlativo sin saltos (bloqueo transaccional), envío en cola con reintentos (30s → 1h), idempotente |
| Estados | Borrador → Pendiente → Aceptado / Observado / Rechazado / Error (reintentar) |
| Impresión | Representación impresa A4 y ticket 80 mm con QR, hash y monto en letras |
| Dashboard | Ventas del día/mes, comparación con el mes anterior, comprobantes por atender |
| Auditoría | Tabla `logs_envio` con cada request/response al PSE |

## Punto de venta (POS)
`Ventas → Punto de venta` (o el acceso rápido **POS**). Pensado primero para celular:

1. Toca los productos (o búscalos por nombre o código; con *Enter* se agrega el primero, útil con lector de código de barras).
2. **Ver y cobrar**: elige boleta o factura, el cliente y el medio de pago (efectivo, Yape, Plin, tarjeta, transferencia).
3. En efectivo, toca el billete con el que te pagan y el sistema calcula el **vuelto**.
4. **Cobrar**: emite el comprobante, registra el pago y ofrece imprimir el ticket.

Reglas que garantiza (`app/Services/Ventas/VentaRapidaService.php`):
- **Todo o nada.** Si falla una regla SUNAT, un permiso o el monto recibido no alcanza, no queda ni comprobante, ni venta, ni pago, ni se consume número.
- **El precio sale siempre de la base de datos**, nunca del navegador: el vendedor no puede alterarlo.
- Con cliente con RUC cambia sola a factura; la factura no se puede cobrar sin RUC. En el Nuevo RUS solo hay boleta.
- Se puede registrar un cliente nuevo (DNI o RUC) sin salir de la venta.

Los pagos se guardan en la tabla `pagos` (medio, monto, recibido, vuelto, usuario), aparte del comprobante, y se ven en el detalle de la venta.

## Inventario (stock y kardex)
Cada producto puede llevar control de stock (los servicios no). **El stock es siempre la suma de su kardex**
(`movimientos_stock`): nada lo cambia sin dejar un movimiento, y un movimiento nunca se edita ni se borra.

| Qué pasa | Efecto en el stock |
|---|---|
| Se **emite** una factura o boleta (POS o formulario) | Sale |
| Se emite una nota de crédito por **devolución o anulación** (motivos 01, 02, 06, 07) | Vuelve a entrar |
| Nota de crédito por descuento, nota de débito, un borrador | No se mueve |
| SUNAT **rechaza** el comprobante | Se revierte solo; al emitir el corregido, vuelve a salir |
| Llega mercadería, hay una merma o se hace un conteo | Movimiento manual desde *Productos → Ingresar / ajustar stock* (solo administradores) |

- **Vender sin stock:** por defecto se permite (queda en negativo y el POS avisa), para no frenar una venta real por un
  inventario desactualizado. Con *Datos de la empresa → No permitir vender sin stock* la venta se bloquea.
- **Por reponer:** cada producto puede tener un mínimo; los agotados o bajos aparecen en el escritorio.
- **Kardex:** *Productos/Servicios → Kardex*, con el comprobante, el motivo y el usuario de cada movimiento.
- El stock se descuenta al **emitir** (el cliente ya se llevó el producto), no cuando SUNAT responde.

Probado con concurrencia real en MariaDB (`tests/Concurrencia/vender_stock_en_paralelo.php`): con 100 unidades y 8 procesos
intentando vender 200, se venden exactamente 100, sin stock negativo ni bloqueos.

## Auditoría independiente
El código pasó por tres revisiones independientes (lógica de negocio, seguridad, e integridad y concurrencia).
Lo corregido tiene su prueba de regresión en `tests/Feature/AuditoriaTest.php`. Reglas que quedaron de esa revisión:

- **Emisión con la fila bloqueada.** Dos clics (o dos usuarios) no emiten el mismo borrador dos veces; un reintento por
  bloqueo de la base no reutiliza números. Verificado en MariaDB con 8 procesos sobre el mismo borrador
  (`tests/Concurrencia/doble_emision.php`).
- **El envío y sus efectos son una sola transacción** (resultado, devolución de stock, estado de la venta).
- **Solo un rechazo explícito de SUNAT es "Rechazado".** Token vencido, límite de peticiones o una respuesta ilegible
  dejan el comprobante en "Error" y se reintenta con el mismo número.
- **Una devolución se controla por cantidad**, no solo por monto: no se devuelve más de lo vendido ni productos que no
  estaban en el comprobante, y el stock regresa solo si de verdad salió.
- **Datos del cliente congelados al emitir.** Editar un cliente no cambia lo ya emitido.
- **Quien no gestiona productos no fija precios**: ni en el POS ni en el formulario (el precio, la descripción y el IGV
  salen del catálogo). Lo que se vende por unidad solo admite cantidades enteras.
- **Un comprobante numerado con el simulador nunca va a un PSE real**, y al revés.
- **Usuarios por invitación.** El administrador no elige la contraseña de nadie: el usuario nuevo recibe un correo
  para crearla. Requiere configurar el envío de correo (`MAIL_*` en `.env`; en desarrollo el enlace queda en `storage/logs`).
- Un producto con historial y una empresa con registros no se pueden eliminar; el logo no acepta SVG;
  el seeder de demostración no corre en producción.

**Pendiente de esa auditoría (depende del PSE):** consultar el estado de un comprobante antes de reenviarlo,
por si el PSE lo aceptó pero la respuesta se perdió.

## Controles de seguridad y auditoría

**Roles por empresa** (`app/Enums/RolEmpresa.php`). Se administran en *Configuración → Usuarios y roles*.
Las notas de crédito y débito (anular, devolver, descontar) son solo para administradores: es la vía típica de fraude en caja.
Los permisos se verifican en la pantalla **y** en el servicio de emisión, así que no se pueden saltar.

**Registro cerrado.** No hay página pública de registro. Las empresas las da de alta un *superadmin*
(`facturador:crear-admin`) y cada empresa invita a sus usuarios por correo. Para abrirlo: `REGISTRO_ABIERTO=true`.

**Bitácora** (*Configuración → Bitácora*). Registra quién creó, modificó o eliminó clientes, productos, series,
usuarios, datos de la empresa y comprobantes, con el valor anterior y el nuevo. Los secretos (token del PSE) se
registran sin mostrar su valor. La bitácora no se puede modificar ni borrar.

**Inmutabilidad real.** Un comprobante con número asignado no se puede alterar ni eliminar, ni siquiera desde el
código (`ComprobanteInmutable`). Lo único que cambia es el resultado del envío. Lo emitido se corrige con otro comprobante.

**Modo simulado bajo control.**
- No funciona si `APP_ENV=production` (salvo `PSE_PERMITIR_SIMULADOR=true`).
- Lo emitido con el simulador queda marcado y se imprime con **"DOCUMENTO DE PRUEBA · SIN VALOR TRIBUTARIO"**, sin QR.
- Antes de emitir con el PSE real hay que **borrar los datos de prueba** (*Datos de la empresa*), para que la numeración real empiece en 1.

**Impresión segura.** Solo un comprobante aceptado por SUNAT lleva QR, hash y la leyenda de representación impresa.
Uno pendiente o rechazado se imprime con una advertencia visible.

**Vigilancia de plazos.** `php artisan facturacion:vigilar` corre cada 10 minutos: reencola los comprobantes atascados
y avisa a los administradores (campana del panel) cuando a uno le queda un día o menos, o ya venció. El listado muestra la columna *Plazo SUNAT*.
Requiere el cron del servidor:
```
* * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

**Numeración a prueba de concurrencia.** Probado en MySQL/MariaDB con 8 procesos emitiendo a la vez
(`tests/Concurrencia/emitir_en_paralelo.php`): 200 comprobantes, sin duplicados, saltos ni bloqueos.

## Venta y comprobante: los dos mundos
El sistema separa el **hecho comercial** del **documento tributario**:

| | Venta (`ventas`) | Comprobante (`comprobantes`) |
|---|---|---|
| Qué es | Lo que pasó en el negocio | La factura, boleta o nota enviada a SUNAT |
| Número | Interno: `V-000001` | Oficial: `F001-00000001` |
| ¿Cambia? | Sí: su estado y saldo se recalculan solos | No: una vez enviado es inmutable |

```
VENTA V-000001 ── Factura F001-1 (original)  ── aceptada ──► venta Confirmada
              └── Nota de crédito FC01-1     ── aceptada ──► venta Con devolución / Anulada
```
- Una factura o boleta **crea** su venta. Una nota de crédito/débito pertenece a la venta del comprobante que modifica.
- El estado y los montos de la venta (`total`, `acreditado`, `debitado`, `neto`) **nunca se editan a mano**: `VentaService` los recalcula cada vez que cambia un comprobante.
- Solo cuentan los comprobantes **aceptados por SUNAT**. Un borrador o un rechazado no altera la venta.
- Si SUNAT rechaza y se corrige, el comprobante nuevo queda en **la misma venta**.

| Estado de la venta | Significa |
|---|---|
| Borrador | El comprobante aún no se emite |
| En proceso | Enviándose a SUNAT (o reintentando) |
| Confirmada | Comprobante aceptado |
| Con devolución | Tiene notas de crédito parciales |
| Anulada | Acreditada por completo |
| Rechazada | SUNAT rechazó el comprobante: hay que corregirlo |

**Dónde enganchar stock y caja:** el evento `App\Events\ComprobanteAceptado` se dispara una sola vez, cuando SUNAT acepta.
Los efectos del negocio deben colgarse de ahí (un *listener*), para que un comprobante rechazado nunca mueva el inventario.

**Acciones en lenguaje simple** (pantalla de la venta): *Devolver productos*, *Aplicar descuento* y *Anular venta* abren la nota de crédito
ya armada con el motivo SUNAT correcto (07, 04 y 01).

**Régimen tributario:** cada empresa indica su régimen. En el **Nuevo RUS** el sistema oculta la factura, no crea series F y bloquea su emisión.

## Diseño
El tema visual está en `resources/css/filament/admin/theme.css` (paleta al inicio del archivo: `--qf-primario`, `--qf-acento`, etc.).
Los elementos agregados al panel están en `resources/views/filament/hooks/`:
- `accesos-rapidos.blade.php`: botones F / B / NC / PRO de la barra superior
- `estado-conexion.blade.php`: indicador de modo (Simulado/Beta/Producción) y campana de pendientes
- `login-aside.blade.php`: panel izquierdo del login

Después de cambiar el CSS ejecuta `npm run build` (o `npm run dev` mientras desarrollas).

## Estructura
```
app/
├─ Enums/                         Catálogos SUNAT (01, 03, 06, 07, 09) y estados
├─ Models/                        Empresa, Serie, Cliente, Producto, Comprobante, ComprobanteItem, LogEnvio
├─ Services/Facturacion/
│  ├─ CalculadoraTotales.php      IGV y redondeos con precisión decimal (brick/math)
│  ├─ EmisionService.php          Validar → correlativo → cola → guardar respuesta
│  ├─ PayloadBuilder.php          Comprobante → arreglo neutro con los datos SUNAT
│  ├─ PseProvider.php             Interfaz común de proveedores
│  └─ Providers/
│     ├─ FakeProvider.php         Simulado (acepta todo)
│     └─ GiorProvider.php         ← ADAPTAR con la documentación de Gior
├─ Jobs/EnviarComprobanteJob.php
├─ Filament/                      Panel: recursos, páginas de empresa y widgets
└─ Support/NumeroALetras.php
config/facturacion.php            Tasa IGV, proveedores, URLs del PSE
```

## Conectar Gior Technology
1. Pide a Gior la documentación de su API y credenciales **beta**.
2. Llena en `.env`: `GIOR_URL_BETA`, `GIOR_URL_PRODUCCION`, `GIOR_ENDPOINT_EMITIR`.
3. Edita **solo** `app/Services/Facturacion/Providers/GiorProvider.php`:
   - `transformar()`: convierte el arreglo de `PayloadBuilder` al JSON que pide Gior.
   - `interpretar()`: lee su respuesta (código SUNAT, mensaje, hash, enlaces PDF/XML/CDR).
   - Si su autenticación no es `Bearer token`, cámbiala en `enviar()`.
4. En el panel: **Empresa → Datos de la empresa → Conexión con el PSE**, elige *Gior Technology*, entorno *Beta* y pega el token (se guarda cifrado).
5. Emite comprobantes de prueba y revisa `logs_envio`.

## Pruebas
```bash
php artisan test
```
121 pruebas automáticas (819 verificaciones), que pasan en SQLite y en MySQL/MariaDB. En GitHub corren solas en cada cambio (`.github/workflows/tests.yml`).

| Archivo | Qué cubre |
|---|---|
| `tests/Unit/*` | Cálculo de IGV y redondeos, monto en letras |
| `tests/Feature/FlujosTest.php` | Reglas de negocio: correlativos, reglas SUNAT, notas de crédito, PSE caído/rechazos/reintentos, aislamiento entre empresas |
| `tests/Feature/AuditoriaTest.php`, `ReintentoEmisionTest.php` | Regresiones de la auditoría independiente: una prueba por cada hallazgo corregido |
| `tests/Feature/StockTest.php` | Inventario: kardex, ventas y devoluciones, rechazo de SUNAT, venta sin stock, ajustes manuales, POS |
| `tests/Feature/PosTest.php` | Punto de venta: cobro con vuelto, medios de pago, todo-o-nada ante errores, precios desde la base, roles, Nuevo RUS |
| `tests/Feature/ControlesTest.php` | Impresión segura, simulador, inmutabilidad, roles, usuarios, bitácora, numeración y vigilancia de plazos |
| `tests/Feature/VentasTest.php` | Ciclo de vida de la venta: devoluciones parciales y totales, notas de débito, rechazo y corrección, régimen Nuevo RUS, pantallas de ventas |
| `tests/Feature/PanelFlujosTest.php` | Los mismos flujos a través de las pantallas: alta de empresa, factura → error → corrección → emisión, nota de crédito precargada, búsqueda, validaciones de clientes y series |

### Reglas que se validan antes de enviar al PSE
- **Cliente:** factura (y sus notas) solo a RUC; boleta mayor a S/ 700 debe identificar al cliente; el cliente debe ser de la misma empresa.
- **Fechas:** no futura; un borrador con más de 3 días debe actualizar su fecha; en crédito, vencimiento posterior a la emisión.
- **Ítems:** al menos uno; cantidad mayor a 0; precio 0 solo si es gratuito.
- **Serie:** del tipo correcto, activa y de la misma empresa.
- **Notas de crédito/débito:** referencia aceptada por SUNAT, misma letra de serie (F/B), mismo cliente, misma moneda, motivo y sustento obligatorios. Una nota de crédito **no puede superar el saldo** (total menos las notas ya emitidas).
- **Sin efectos a medias:** si falla una validación no se consume número; un comprobante aceptado no se reenvía ni se edita.

### Comportamiento ante errores del PSE
| Situación | Resultado |
|---|---|
| PSE caído / timeout / error 5xx | Estado **Error**, se reintenta solo (30s → 1h) conservando el mismo número |
| SUNAT rechaza | Estado **Rechazado** con código y mensaje; botón **"Corregir y volver a emitir"** crea un borrador nuevo |
| Validación local falla | Queda en **borrador** y se abre el formulario de edición con el motivo |

## Pendientes sugeridos
- [ ] Consulta de RUC/DNI para autocompletar clientes (`ClienteResource::campos()`, hay un TODO)
- [ ] Comunicación de baja / resumen de anulación de boletas (según soporte Gior)
- [ ] Cuotas para ventas al crédito (SUNAT exige monto y fecha de cada cuota)
- [ ] Detracciones, retenciones, percepciones e ICBPER
- [ ] Envío del comprobante por correo/WhatsApp al cliente
- [ ] Reporte de ventas en Excel / registro de ventas para el contador
- [ ] Guía de remisión electrónica
