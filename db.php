<?php
require_once __DIR__ . "/config.php";

date_default_timezone_set(APP_TIMEZONE);

$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
  appLogError('No se pudo iniciar la conexión principal', $conn->connect_error);
  http_response_code(503);
  die('No se ha podido iniciar la aplicación. Inténtalo de nuevo más tarde.');
}

$conn->set_charset("utf8mb4");

/*
  Ajustamos la zona horaria de MySQL a la zona horaria de la app.
  Usamos offset dinámico para respetar horario de verano/invierno.
*/
$timezone = new DateTimeZone(APP_TIMEZONE);
$now = new DateTime('now', $timezone);
$offsetSeconds = $timezone->getOffset($now);
$offsetHours = intdiv($offsetSeconds, 3600);
$offsetMinutes = abs(($offsetSeconds % 3600) / 60);

$mysqlOffset = sprintf('%+03d:%02d', $offsetHours, $offsetMinutes);
$conn->query("SET time_zone = '" . $conn->real_escape_string($mysqlOffset) . "'");
?>
