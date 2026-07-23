# Actualización: gestor de rutas y saldo de vacaciones

## Base de datos
Ejecutar una sola vez `sql_vacaciones_saldos_y_rutas.sql` sobre la base de datos existente.

## Google Maps
Mantener `GOOGLE_MAPS_ROUTES_API_KEY` para el cálculo desde servidor.
Configurar `GOOGLE_MAPS_BROWSER_API_KEY` con una clave restringida al dominio de la aplicación para mostrar el mapa interactivo.

## Vacaciones
- Admin y Máster asignan los días anuales desde `admin/vacaciones_saldos.php`.
- Los usuarios consultan asignados, disfrutados, créditos y pendientes en la pestaña Vacaciones pendientes.
- Lunes a jueves admiten día completo o media jornada.
- Viernes computa siempre como día completo.
- Los rangos de varias fechas se registran como días completos.
- Un festivo o fin de semana trabajado suma un día cuando existe un fichaje con horas en esa fecha.
