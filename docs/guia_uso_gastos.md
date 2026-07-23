# Guía interna de uso — Módulo de gastos

Versión: **V1.0 - Gastos estable**

## 1. Objetivo

El módulo de gastos permite que cada comercial registre sus gastos de viaje, adjunte justificantes cuando existan y presente el cierre mensual para revisión administrativa.

## 2. Uso por parte del comercial

### Registrar un gasto con justificante

1. Entrar en la aplicación.
2. Seleccionar la opción de alta de gasto o nota de gastos.
3. Indicar viaje, motivo, fecha e importe cuando corresponda.
4. Adjuntar el ticket o justificante.
5. Enviar el formulario y esperar a que finalice el proceso.

El sistema puede procesar el gasto mediante IA y automatización. Durante ese proceso se muestra una pantalla de “Procesando”.

### Registrar un gasto sin justificante

Se puede registrar un gasto manual sin justificante cuando sea necesario. En este caso, administración deberá revisarlo y autorizarlo si procede.

### Cierre mensual

Al finalizar el mes, el comercial debe presentar su cierre mensual indicando el importe de banco. Una vez validado por administración, el periodo queda bloqueado y no se puede modificar desde la pantalla del comercial.

## 3. Uso por parte de administración

### Gestión de gastos

Desde la gestión de gastos se pueden revisar gastos con error, corregir datos erróneos, reintentar procesamientos, marcar como revisados o descartar registros cuando proceda.

### Autorización de gastos sin justificante

Si un gasto manual no tiene justificante pero es correcto, un usuario admin o master puede entrar en el detalle del gasto y autorizarlo administrativamente. Al autorizarlo, deja de aparecer como error.

### Cierres mensuales

Administración debe revisar el cierre mensual presentado por cada comercial. Si el importe de banco coincide con el importe registrado en la aplicación, el cierre puede validarse. Si no coincide, debe marcarse con diferencia o rechazarse según proceda.

## 4. Centro de control

El Centro de control agrupa las pantallas de supervisión:

- Estado del sistema.
- Gastos a revisar.
- Registro de actividad.
- Eventos sensibles recientes.
- Copias y exportaciones.

## 5. Auditoría

La auditoría registra acciones importantes como accesos, cambios de estado, modificaciones, revisiones, errores y operaciones administrativas. Los eventos pueden marcarse como normales, revisados, corregidos o anulados.

## 6. Backup mensual

El backup mensual permite descargar una copia CSV de control del periodo seleccionado. Debe usarse como respaldo administrativo, especialmente al cierre de cada mes.

## 7. Estados habituales

- **Pendiente:** gasto recibido o pendiente de tratamiento.
- **Procesado:** gasto correcto y computable.
- **Editado:** gasto corregido manualmente y computable.
- **Error:** gasto que requiere revisión.
- **Eliminado/descartado:** gasto retirado de la gestión ordinaria.

## 8. Roles

- **Usuario:** registra y consulta sus propios gastos y cierres.
- **Admin:** gestiona gastos, cierres, incidencias y revisiones administrativas.
- **Master:** rol superior con acceso completo y protección frente a modificaciones por otros usuarios.

## 9. Recomendación operativa

Antes de usar el sistema en producción, se recomienda probar un ciclo completo con un usuario normal, un admin y el master: alta de gastos, gasto sin justificante, autorización, edición, descarga, cierre mensual, validación y backup.

## Firmas digitales de cierre mensual

El módulo de cierre mensual puede generar tres firmas técnicas independientes:

- Firma del comercial: se crea al confirmar el cierre mensual.
- Firma de administración: se crea cuando administración valida el cierre.
- Firma de contabilidad: se crea cuando el envío de integración del cierre pasa a estado enviado.

Estas firmas se almacenan en la tabla `cierre_firmas` y se envían al webhook `MAKE_WEBHOOK_FIRMA_CIERRE` para que Make pueda insertarlas en el Excel correspondiente.

La firma es técnica, no manuscrita. Tiene formato similar a `COM-XXXXXXXXXXXX`, `ADM-XXXXXXXXXXXX` o `CON-XXXXXXXXXXXX`.

Antes de usarlo, ejecutar `sql_cierre_firmas.sql` en phpMyAdmin y configurar en `config.php`:

```php
define('MAKE_WEBHOOK_FIRMA_CIERRE', 'URL_DEL_WEBHOOK');
define('CIERRE_SIGNATURE_SECRET', 'CADENA_PRIVADA_LARGA');
```

Si el webhook falla, el cierre no se bloquea. La firma queda guardada en base de datos con estado de webhook `error` para poder revisar el problema sin romper el flujo principal.
