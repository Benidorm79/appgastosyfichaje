# Guía interna de uso - Fichaje horario

## Acceso

El fichaje se realiza desde el botón **Área Fichaje** de la portada.
La consulta de registros se realiza desde **Área Gestión > Gestión de fichaje**.

## Registro de jornada

La pantalla de fichaje muestra un único botón principal:

- **Registrar entrada**
- **Registrar salida**

El sistema decide automáticamente cuál corresponde según la última marca registrada.

La hora se toma siempre de forma automática con horario local de España y formato 24 horas `HH:MM`.
El usuario no puede introducir ni modificar horas manualmente.

## Salidas durante la jornada

Cuando se registra una salida, debe indicarse el motivo:

- Fin de jornada
- Comida / pausa
- Médico
- Personal
- Otro

Si se selecciona **Otro**, puede añadirse una nota breve.

## Cálculo de horas

El sistema calcula el total trabajado sumando los tramos entrada/salida cerrados.
El objetivo diario es:

- Lunes a jueves: 08:30
- Viernes: 06:00

La diferencia se muestra en positivo o negativo.

## Viernes

El viernes no tiene jornada ordinaria de tarde.
Si un viernes queda abierto y el siguiente día laborable el usuario ficha una nueva entrada, el sistema cerrará automáticamente el viernes a las 15:00.

## Gestión de fichajes

La gestión es solo de consulta. No se pueden editar ni eliminar fichajes.

- Cada usuario ve solo sus registros.
- Admin y Máster pueden consultar registros de todos los usuarios.

La pantalla permite filtrar por mes y año. Admin y Máster pueden filtrar además por usuario/comercial.

## Exportación

Desde Gestión de fichajes se puede exportar el registro mensual detallado, incluyendo todas las marcas reales: entradas, salidas, motivos, notas y firmas técnicas.

También se añade en el área de descargas la opción **Descargar registro de jornada**.

## Calendario laboral Barcelona

La tarjeta **Objetivo mensual** de `gestion_fichajes.php` se calcula con el calendario laboral guardado en la tabla `fichaje_festivos`.

El sistema descuenta automáticamente:

- sábados
- domingos
- festivos nacionales
- festivos autonómicos de Cataluña
- festivos locales de Barcelona

Cada año se debe revisar el calendario laboral oficial y añadir los festivos correspondientes con sentencias como esta:

```sql
INSERT IGNORE INTO fichaje_festivos (anio, fecha, descripcion, ambito, municipio, activo) VALUES
(2027, '2027-01-01', 'Año Nuevo', 'nacional', 'Barcelona', 1),
(2027, '2027-09-24', 'La Mercè', 'local', 'Barcelona', 1);
```

Si un festivo cambia o no debe computar, se puede desactivar sin borrarlo:

```sql
UPDATE fichaje_festivos
SET activo = 0
WHERE fecha = '2027-09-24';
```

La jornada objetivo se calcula así:

- lunes a jueves: 08:30
- viernes: 06:00
- sábados, domingos y festivos: 00:00

## Vacaciones y días libres personales

Cada usuario puede registrar sus propias vacaciones o días libres desde `fichaje_ausencias.php`.

Estos días son individuales por usuario. No afectan al calendario general ni al resto de trabajadores.

Cuando un día se registra como vacaciones o día libre:

- no computa como jornada prevista del usuario;
- se descuenta del objetivo mensual de Gestión de fichajes;
- se mantiene guardado en base de datos;
- no se comparte con otros usuarios;
- si ya existía un fichaje de ese día, se recalcula el objetivo y la diferencia del registro.

Para activar esta función en instalaciones existentes, ejecutar el archivo:

```text
sql_fichaje_ausencias.sql
```

## Vacaciones compartidas y calendario de Outlook/Teams

La página `fichaje_ausencias.php` mantiene dos funciones simultáneas:

- Cada usuario registra únicamente sus propias vacaciones y días libres. Esas fechas descuentan jornada prevista solo a ese usuario.
- Todos los usuarios pueden consultar en el calendario compartido las vacaciones y días libres del resto del equipo.

Los periodos se almacenan por rango en `fichaje_ausencias_periodos` y por día en `fichaje_ausencias_usuario`, manteniendo sin cambios el cálculo existente del objetivo mensual.

Para publicar los periodos en Outlook/Teams hay que configurar en `config.php`:

```php
define('MAKE_WEBHOOK_VACACIONES_CALENDARIO', 'URL_DEL_WEBHOOK');
define('VACACIONES_CALENDAR_TARGET', 'calendario-compartido@empresa.com');
```

El webhook recibe `accion=crear` o `accion=eliminar`, fechas de inicio/fin, fecha final exclusiva para eventos de día completo, usuario, comercial, tipo de ausencia y el identificador del evento si ya fue creado. El escenario debe devolver `calendar_event_id`, `event_id` o `id` para que la aplicación pueda solicitar posteriormente su eliminación.

## Agenda compartida de actividades

La pestaña Calendario del equipo combina vacaciones, días libres y actividades. Todos los usuarios pueden crear actividades y consultar las del equipo. Cada usuario puede eliminar sus propias actividades; Admin y Máster pueden eliminar cualquiera. El panel lateral muestra la agenda del día seleccionado y las ausencias de día completo.

Para activar la sincronización opcional con Outlook/Teams, configura `MAKE_WEBHOOK_AGENDA_CALENDARIO` en `config.php`. La agenda funciona en la base de datos aunque ese webhook no esté configurado.

Ejecuta `sql_agenda_compartida.sql` una sola vez para crear la tabla necesaria.
