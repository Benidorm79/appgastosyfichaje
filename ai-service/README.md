# Servicio del asistente técnico

Servicio privado intermedio entre la aplicación PHP y OpenAI. La aplicación firma cada petición con HMAC; la clave de OpenAI solo existe como secreto del despliegue.

El despliegue es reproducible: `requirements.txt` fija versiones exactas, incluida
`openai==2.47.0`. La ruta `/health` expone la versión realmente instalada para
detectar de inmediato una revisión incorrecta.

Funciones:

- extraer texto y referencias de página de los PDF;
- detectar documentos escaneados y aplicar OCR local en español e inglés;
- crear y mantener un almacén vectorial independiente por marca;
- mantener la petición abierta hasta confirmar que el documento está indexado y publicado;
- devolver únicamente un resultado final de publicación o un error recuperable, sin estados intermedios indefinidos;
- recuperar primero los fragmentos relevantes y responder después;
- enrutar consultas documentales a Terra y proyectos o dimensionamientos a Sol;
- estructurar las filas de las tarifas para buscar por producto, referencia y precio;
- devolver las filas normalizadas a la aplicación para mantener un catálogo local exacto y sin coste de tokens;
- reforzar como respaldo la recuperación léxica de referencias y términos de tarifa;
- pedir los datos técnicos que falten antes de proponer material para un proyecto;
- devolver una lista estructurada de equipos, supuestos y cuestiones pendientes;
- generar al final de cada propuesta una lista copiable `PartNumber#unidades`;
- rechazar preguntas sin evidencia suficiente;
- devolver fuentes validadas, consumo y trazabilidad técnica.

Variables de modelo:

- `OPENAI_MODEL_STANDARD`: modelo para preguntas documentales normales; valor previsto `gpt-5.6-terra`.
- `OPENAI_MODEL_PROJECT`: modelo para proyectos, dimensionamiento y selección de material; valor previsto `gpt-5.6-sol`.

Rutas de ingesta:

- `POST /v1/vector-stores/ensure`: comprueba o crea el almacén de la marca antes de aceptar un lote.
- `POST /v1/documents/upload-binary`: extrae el PDF y ejecuta las fases crear, asociar y comprobar; solo confirma la publicación con estado terminal `completed`.
- `POST /v1/documents/index-status`: recupera cargas incompletas creadas por versiones anteriores.

El OCR utiliza Tesseract dentro del propio contenedor. Las cargas normales reconocen
automáticamente documentos escaneados pequeños; los manuales grandes se pueden
reprocesar de forma explícita desde Admin ELÍAS, sin utilizar Terra ni Sol.

La guía completa de despliegue está en `GUIA_PUESTA_EN_MARCHA.md`, en la raíz de la aplicación.
