<?php
require_once __DIR__ . "/../admin_guard.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/auditoria.php";

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Madrid');

function redirectUsuarios($message, $type = "success") {
  header("Location: usuarios.php?type=" . urlencode($type) . "&msg=" . urlencode($message));
  exit;
}

function redirectNuevo($message) {
  header("Location: usuario_nuevo.php?msg=" . urlencode($message));
  exit;
}

function redirectEditar($id, $message) {
  header("Location: usuario_editar.php?id=" . intval($id) . "&msg=" . urlencode($message));
  exit;
}

function usuarioGuardarAuditar($conn, $data = []) {
  if (!function_exists('auditoriaRegistrar')) {
    return;
  }

  try {
    auditoriaRegistrar($conn, $data);
  } catch (Throwable $e) {
    error_log("No se pudo registrar auditoría en usuario_guardar.php: " . $e->getMessage());
  }
}

function usuarioGuardarFetchUsuario($conn, $id) {
  $sql = "SELECT id, username, comercial, email, role, activo, created_at, ultimo_login
          FROM users
          WHERE id = ?
          LIMIT 1";

  $stmt = $conn->prepare($sql);

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param("i", $id);
  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function usuarioGuardarFetchUsuarioPorUsername($conn, $username, $exceptId = 0) {
  if ($exceptId > 0) {
    $sql = "SELECT id
            FROM users
            WHERE username = ?
              AND id <> ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return null;
    }

    $stmt->bind_param("si", $username, $exceptId);
  } else {
    $sql = "SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return null;
    }

    $stmt->bind_param("s", $username);
  }

  $stmt->execute();

  $result = $stmt->get_result();

  if (!$result) {
    return null;
  }

  return $result->fetch_assoc();
}

function usuarioGuardarExisteOtroMaster($conn, $exceptId = 0) {
  $exceptId = (int)$exceptId;

  if ($exceptId > 0) {
    $sql = "SELECT id FROM users WHERE role = 'master' AND id <> ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return true;
    }

    $stmt->bind_param("i", $exceptId);
  } else {
    $sql = "SELECT id FROM users WHERE role = 'master' LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      return true;
    }
  }

  $stmt->execute();
  $result = $stmt->get_result();

  return $result && $result->num_rows > 0;
}

function usuarioGuardarActivoTexto($activo) {
  return (int)$activo === 1 ? 'activo' : 'inactivo';
}

function usuarioGuardarCambios($anterior, $nuevo, $passwordCambiada = false) {
  $campos = ['username', 'comercial', 'email', 'role', 'activo'];
  $cambios = [];

  foreach ($campos as $campo) {
    $valorAnterior = $anterior[$campo] ?? null;
    $valorNuevo = $nuevo[$campo] ?? null;

    if ((string)$valorAnterior !== (string)$valorNuevo) {
      $cambios[$campo] = [
        'anterior' => $valorAnterior,
        'nuevo' => $valorNuevo
      ];
    }
  }

  if ($passwordCambiada) {
    $cambios['password'] = [
      'anterior' => 'sin mostrar',
      'nuevo' => 'modificada'
    ];
  }

  return $cambios;
}

function usuarioGuardarRolesPermitidos() {
  return ['user', 'admin', 'master'];
}

$accion = $_POST['accion'] ?? '';
$localNow = date('Y-m-d H:i:s');
$rolSesion = $_SESSION['role'] ?? 'user';
$esMasterSesion = $rolSesion === 'master';
$usuarioSesionId = (int)($_SESSION['user_id'] ?? 0);

if ($accion === 'crear') {
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $comercial = trim($_POST['comercial'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $role = $_POST['role'] ?? 'user';
  $activo = intval($_POST['activo'] ?? 1);

  if ($username === '' || $password === '' || $comercial === '') {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'accion' => 'intento_crear_usuario_datos_obligatorios_incompletos',
      'descripcion' => 'Intento de crear usuario sin informar usuario, contraseña o comercial.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ]
    ]);

    redirectNuevo("Usuario, contraseña y comercial son obligatorios.");
  }

  if (!in_array($role, usuarioGuardarRolesPermitidos(), true)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'accion' => 'intento_crear_usuario_rol_no_valido',
      'descripcion' => 'Intento de crear usuario con un rol no válido.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role
      ]
    ]);

    redirectNuevo("Rol no válido.");
  }

  if ($role === 'master' && !$esMasterSesion) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'accion' => 'intento_crear_usuario_master_sin_permiso',
      'descripcion' => 'Intento bloqueado de crear un usuario Máster sin ser Máster.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'role' => $role,
        'modificado_por' => $usuarioSesionId
      ]
    ]);

    redirectNuevo("Solo el usuario Máster puede crear otro usuario Máster.");
  }

  if ($role === 'master' && usuarioGuardarExisteOtroMaster($conn, 0)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'accion' => 'intento_crear_segundo_usuario_master',
      'descripcion' => 'Intento bloqueado de crear un segundo usuario Máster.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'role' => $role,
        'modificado_por' => $usuarioSesionId
      ]
    ]);

    redirectNuevo("Ya existe un usuario Máster. Solo puede existir uno.");
  }

  if (!in_array($activo, [0, 1], true)) {
    $activo = 1;
  }

  if ($role === 'master') {
    $activo = 1;
  }

  if (!appPasswordMeetsPolicy($password)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'accion' => 'intento_crear_usuario_password_corta',
      'descripcion' => 'Intento de crear usuario con una contraseña inferior a 10 caracteres.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ]
    ]);

    redirectNuevo("La contraseña debe tener al menos 10 caracteres.");
  }

  $exists = usuarioGuardarFetchUsuarioPorUsername($conn, $username);

  if ($exists) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => (int)($exists['id'] ?? 0),
      'accion' => 'intento_crear_usuario_duplicado',
      'descripcion' => 'Intento de crear un usuario con un nombre de usuario ya existente.',
      'estado_nuevo' => 'error',
      'datos' => [
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo,
        'usuario_existente_id' => (int)($exists['id'] ?? 0)
      ]
    ]);

    redirectNuevo("Ya existe un usuario con ese nombre.");
  }

  $passwordHash = appPasswordHash($password);
  if (!is_string($passwordHash) || $passwordHash === '') redirectNuevo(appPublicError());

  $stmt = $conn->prepare("INSERT INTO users (username, password, comercial, role, email, activo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");

  if (!$stmt) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'usuario',
      'accion' => 'error_preparar_alta_usuario',
      'descripcion' => 'Error SQL preparando el alta de usuario.',
      'estado_nuevo' => 'error',
      'datos' => [
        'mysql_error' => $conn->error,
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ]
    ]);

    redirectNuevo("Error SQL preparando alta: " . $conn->error);
  }

  $stmt->bind_param("sssssis", $username, $passwordHash, $comercial, $role, $email, $activo, $localNow);

  if (!$stmt->execute()) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'usuario',
      'accion' => 'error_crear_usuario',
      'descripcion' => 'Error SQL al crear usuario.',
      'estado_nuevo' => 'error',
      'datos' => [
        'mysql_error' => $stmt->error,
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ]
    ]);

    redirectNuevo("Error al crear usuario: " . $stmt->error);
  }

  $nuevoUsuarioId = (int)$stmt->insert_id;

  usuarioGuardarAuditar($conn, [
    'tipo_evento' => 'usuario',
    'entidad' => 'usuario',
    'entidad_id' => $nuevoUsuarioId,
    'accion' => 'usuario_creado',
    'descripcion' => 'Usuario creado desde el panel de administración.',
    'estado_anterior' => null,
    'estado_nuevo' => usuarioGuardarActivoTexto($activo),
    'datos' => [
      'usuario_id' => $nuevoUsuarioId,
      'username' => $username,
      'comercial' => $comercial,
      'email' => $email,
      'role' => $role,
      'activo' => $activo,
      'password_inicial_definida' => true,
      'creado_por' => $usuarioSesionId,
      'created_at' => $localNow
    ]
  ]);

  redirectUsuarios("Usuario creado correctamente.");
}

if ($accion === 'editar') {
  $id = intval($_POST['id'] ?? 0);
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $comercial = trim($_POST['comercial'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $role = $_POST['role'] ?? 'user';
  $activo = intval($_POST['activo'] ?? 1);

  if ($id <= 0) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_id_no_valido',
      'descripcion' => 'Intento de editar usuario con ID no válido.',
      'estado_nuevo' => 'error',
      'datos' => [
        'post' => $_POST
      ]
    ]);

    redirectUsuarios("Usuario no válido.", "error");
  }

  if ($username === '' || $comercial === '') {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_datos_obligatorios_incompletos',
      'descripcion' => 'Intento de editar usuario sin informar usuario o comercial.',
      'estado_nuevo' => 'error',
      'datos' => [
        'usuario_id' => $id,
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ]
    ]);

    redirectEditar($id, "Usuario y comercial son obligatorios.");
  }

  if (!in_array($role, usuarioGuardarRolesPermitidos(), true)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_rol_no_valido',
      'descripcion' => 'Intento de editar usuario con un rol no válido.',
      'estado_nuevo' => 'error',
      'datos' => [
        'usuario_id' => $id,
        'role' => $role
      ]
    ]);

    redirectEditar($id, "Rol no válido.");
  }

  if (!in_array($activo, [0, 1], true)) {
    $activo = 1;
  }

  $usuarioAnterior = usuarioGuardarFetchUsuario($conn, $id);

  if (!$usuarioAnterior) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_inexistente',
      'descripcion' => 'Intento de editar un usuario inexistente.',
      'estado_nuevo' => 'error'
    ]);

    redirectUsuarios("Usuario no encontrado.", "error");
  }

  $rolAnterior = $usuarioAnterior['role'] ?? 'user';

  if (!$esMasterSesion && in_array($rolAnterior, ['admin', 'master'], true) && $id !== $usuarioSesionId) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_admin_editar_admin_o_master',
      'descripcion' => 'Intento bloqueado de un administrador para modificar otro administrador o el usuario Máster.',
      'estado_anterior' => $rolAnterior,
      'estado_nuevo' => $role,
      'datos' => [
        'usuario_anterior' => $usuarioAnterior,
        'modificado_por' => $usuarioSesionId
      ]
    ]);

    redirectUsuarios("Solo el usuario Máster puede modificar administradores o el usuario Máster.", "error");
  }

  if ($rolAnterior === 'master') {
    $role = 'master';
    $activo = 1;
  }

  if ($role === 'master' && !$esMasterSesion) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_asignar_master_sin_permiso',
      'descripcion' => 'Intento bloqueado de asignar rol Máster sin ser Máster.',
      'estado_anterior' => $rolAnterior,
      'estado_nuevo' => $role,
      'datos' => [
        'usuario_id' => $id,
        'modificado_por' => $usuarioSesionId
      ]
    ]);

    redirectEditar($id, "Solo el usuario Máster puede asignar el rol Máster.");
  }

  if ($role === 'master' && usuarioGuardarExisteOtroMaster($conn, $id)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_asignar_segundo_master',
      'descripcion' => 'Intento bloqueado de asignar un segundo usuario Máster.',
      'estado_anterior' => $rolAnterior,
      'estado_nuevo' => $role,
      'datos' => [
        'usuario_id' => $id,
        'modificado_por' => $usuarioSesionId
      ]
    ]);

    redirectEditar($id, "Ya existe un usuario Máster. Solo puede existir uno.");
  }

  if ($id === $usuarioSesionId) {
    $activo = 1;
  }

  if ($password !== '' && !appPasswordMeetsPolicy($password)) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_password_corta',
      'descripcion' => 'Intento de editar usuario con una nueva contraseña inferior a 10 caracteres.',
      'estado_nuevo' => 'error',
      'datos' => [
        'usuario_id' => $id,
        'username' => $username,
        'comercial' => $comercial
      ]
    ]);

    redirectEditar($id, "La nueva contraseña debe tener al menos 10 caracteres.");
  }

  $exists = usuarioGuardarFetchUsuarioPorUsername($conn, $username, $id);

  if ($exists) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'seguridad',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'intento_editar_usuario_username_duplicado',
      'descripcion' => 'Intento de editar usuario usando un nombre de usuario ya existente en otro registro.',
      'estado_nuevo' => 'error',
      'datos' => [
        'usuario_id' => $id,
        'username_solicitado' => $username,
        'usuario_existente_id' => (int)($exists['id'] ?? 0)
      ]
    ]);

    redirectEditar($id, "Ya existe otro usuario con ese nombre.");
  }

  $nuevoUsuario = [
    'id' => $id,
    'username' => $username,
    'comercial' => $comercial,
    'email' => $email,
    'role' => $role,
    'activo' => $activo
  ];

  $passwordCambiada = $password !== '';

  if ($passwordCambiada) {
    $passwordHash = appPasswordHash($password);
    if (!is_string($passwordHash) || $passwordHash === '') redirectEditar($id, appPublicError());

    $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, comercial = ?, role = ?, email = ?, activo = ? WHERE id = ?");

    if (!$stmt) {
      usuarioGuardarAuditar($conn, [
        'tipo_evento' => 'sistema',
        'entidad' => 'usuario',
        'entidad_id' => $id,
        'accion' => 'error_preparar_edicion_usuario',
        'descripcion' => 'Error SQL preparando la edición de usuario con cambio de contraseña.',
        'estado_anterior' => usuarioGuardarActivoTexto($usuarioAnterior['activo'] ?? 0),
        'estado_nuevo' => usuarioGuardarActivoTexto($activo),
        'datos' => [
          'mysql_error' => $conn->error,
          'usuario_anterior' => $usuarioAnterior,
          'usuario_nuevo' => $nuevoUsuario,
          'password_cambiada' => true
        ]
      ]);

      redirectEditar($id, "Error SQL preparando edición: " . $conn->error);
    }

    $stmt->bind_param("sssssii", $username, $passwordHash, $comercial, $role, $email, $activo, $id);
  } else {
    $stmt = $conn->prepare("UPDATE users SET username = ?, comercial = ?, role = ?, email = ?, activo = ? WHERE id = ?");

    if (!$stmt) {
      usuarioGuardarAuditar($conn, [
        'tipo_evento' => 'sistema',
        'entidad' => 'usuario',
        'entidad_id' => $id,
        'accion' => 'error_preparar_edicion_usuario',
        'descripcion' => 'Error SQL preparando la edición de usuario.',
        'estado_anterior' => usuarioGuardarActivoTexto($usuarioAnterior['activo'] ?? 0),
        'estado_nuevo' => usuarioGuardarActivoTexto($activo),
        'datos' => [
          'mysql_error' => $conn->error,
          'usuario_anterior' => $usuarioAnterior,
          'usuario_nuevo' => $nuevoUsuario,
          'password_cambiada' => false
        ]
      ]);

      redirectEditar($id, "Error SQL preparando edición: " . $conn->error);
    }

    $stmt->bind_param("ssssii", $username, $comercial, $role, $email, $activo, $id);
  }

  if (!$stmt->execute()) {
    usuarioGuardarAuditar($conn, [
      'tipo_evento' => 'sistema',
      'entidad' => 'usuario',
      'entidad_id' => $id,
      'accion' => 'error_guardar_usuario',
      'descripcion' => 'Error SQL al guardar cambios de usuario.',
      'estado_anterior' => usuarioGuardarActivoTexto($usuarioAnterior['activo'] ?? 0),
      'estado_nuevo' => usuarioGuardarActivoTexto($activo),
      'datos' => [
        'mysql_error' => $stmt->error,
        'usuario_anterior' => $usuarioAnterior,
        'usuario_nuevo' => $nuevoUsuario,
        'password_cambiada' => $passwordCambiada
      ]
    ]);

    redirectEditar($id, "Error al guardar usuario: " . $stmt->error);
  }

  $cambios = usuarioGuardarCambios($usuarioAnterior, $nuevoUsuario, $passwordCambiada);

  if ($id === $usuarioSesionId) {
    $_SESSION['user'] = $username;
    $_SESSION['comercial'] = $comercial;
    $_SESSION['role'] = $role;
  }

  usuarioGuardarAuditar($conn, [
    'tipo_evento' => 'usuario',
    'entidad' => 'usuario',
    'entidad_id' => $id,
    'accion' => empty($cambios) ? 'usuario_guardado_sin_cambios' : 'usuario_actualizado',
    'descripcion' => empty($cambios)
      ? 'Se guardó la ficha del usuario sin cambios relevantes.'
      : 'Usuario actualizado desde el panel de administración.',
    'estado_anterior' => usuarioGuardarActivoTexto($usuarioAnterior['activo'] ?? 0),
    'estado_nuevo' => usuarioGuardarActivoTexto($activo),
    'datos' => [
      'usuario_id' => $id,
      'usuario_anterior' => [
        'username' => $usuarioAnterior['username'] ?? '',
        'comercial' => $usuarioAnterior['comercial'] ?? '',
        'email' => $usuarioAnterior['email'] ?? '',
        'role' => $usuarioAnterior['role'] ?? '',
        'activo' => (int)($usuarioAnterior['activo'] ?? 0)
      ],
      'usuario_nuevo' => [
        'username' => $username,
        'comercial' => $comercial,
        'email' => $email,
        'role' => $role,
        'activo' => $activo
      ],
      'cambios' => $cambios,
      'password_cambiada' => $passwordCambiada,
      'modificado_por' => $usuarioSesionId,
      'modificado_at' => $localNow
    ]
  ]);

  redirectUsuarios("Usuario actualizado correctamente.");
}

usuarioGuardarAuditar($conn, [
  'tipo_evento' => 'seguridad',
  'entidad' => 'usuario',
  'accion' => 'accion_usuario_no_valida',
  'descripcion' => 'Se recibió una acción no válida en usuario_guardar.php.',
  'estado_nuevo' => 'error',
  'datos' => [
    'accion' => $accion,
    'post' => $_POST
  ]
]);

redirectUsuarios("Acción no válida.", "error");
?>
