# Mensajería interna

## Instalación

1. Ejecutar `sql_chat_interno.sql` en phpMyAdmin.
2. Verificar que PHP puede escribir en `storage/chat`.
3. Abrir el icono de mensajería situado en la cabecera de la aplicación.

## Funcionamiento

- Todos los usuarios activos pueden iniciar conversaciones privadas.
- Solo los perfiles Admin y Máster pueden crear grupos.
- ELÍAS aparece como contacto virtual y abre el asistente técnico dentro del mismo panel de Mensajería. La marca, el historial técnico, las fuentes y la nueva conversación se gestionan desde su barra superior.
- Los mensajes y archivos solo pueden consultarse por miembros de la conversación.
- Los archivos se almacenan con nombres aleatorios y se sirven mediante `chat_archivo.php`, que valida la sesión y la pertenencia al chat.
- Se admiten texto, imágenes, vídeos, audio, PDF, documentos y archivos habituales. Se bloquean ejecutables, scripts y contenido activo peligroso.
- Cada archivo tiene un máximo de 20 MB y cada envío admite hasta cinco archivos.
- Un check indica mensaje guardado, dos checks indican entregado y dos checks azules indican leído por todos los destinatarios.
- La burbuja del icono muestra mensajes pendientes. Las notificaciones del navegador requieren conceder permiso y funcionan mientras la aplicación permanece abierta.
- La papelera retira una conversación humana únicamente de la lista del usuario que la elimina. Los demás participantes conservan el chat.
- Un chat privado eliminado reaparece si se vuelve a abrir o si llega un mensaje nuevo. Al eliminar un grupo, el usuario sale de él y no se reincorpora automáticamente.
- Los chats eliminados de ELÍAS dejan de mostrarse, pero conservan internamente su trazabilidad para recuperación y auditoría.

## Vacaciones y agenda

- Las actividades pueden editarse por su creador; Admin y Máster también pueden editar actividades del equipo.
- Las ausencias pueden editarse por el usuario propietario.
- Los festivos o fines de semana trabajados suman un día aunque no exista fichaje. Si existe, se marca como verificado.
