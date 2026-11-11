# S01 — Validación de bibliotecas personales y globales

Estado: preparado, pendiente de investigación y laboratorio. Base: main 808cfb3, tras fusionar PR #7. Rama: feat/s01-library-isolation. No afirmar privacidad hasta obtener evidencia real.

## Objetivo y alcance de la PR
Determinar si la versión de Navidrome fijada en compose.music.yaml puede satisfacer contenido personal por usuario y biblioteca global publicada por administrador. Resultado esperado: informe de capacidades con fuentes oficiales versionadas, laboratorio desechable reproducible y pruebas negativas automatizadas. No implementar aún subidas, cuotas, publicación ni migrar contenido existente.

## Orden de ejecución
1. Leer AGENTS.md, CONTENT_STORAGE.md, configuración musical y adaptador de cuentas. Registrar versión fijada y comprobar documentación/código de esa versión, sin usar documentación de latest como prueba de compatibilidad.
2. Identificar soporte de bibliotecas, asignación a usuarios, escaneo, APIs de archivos y cambios de permisos. Anotar rutas de código y referencias concretas. Ausencia de documentación no demuestra ausencia de función.
3. Preparar Compose separado con nombre de proyecto propio, datos desechables y puerto loopback configurable. No usar music.env, volúmenes, contraseñas ni redes de la instalación habitual. Nunca montar bibliotecas reales.
4. Generar archivos de audio sintéticos identificables A/B/G y crear administrador y dos usuarios normales mediante mecanismos soportados. Fijar credenciales exclusivamente de prueba.
5. Ejecutar la matriz. Guardar resultados sanitizados; no publicar tokens ni respuestas con contraseñas. Distinguir no soportado, no probado y fallo de aislamiento.
6. Registrar decisión y límites; solo entonces proponer arquitectura de S02. Si la versión falla, comparar alternativas sin actualizarla silenciosamente.

## Matriz de aceptación
Todos los casos están pendientes. Probar A y B simétricamente cuando corresponda.

| Caso | Resultado exigido | Evidencia a recoger |
| --- | --- | --- |
| Listado/búsqueda A | Ve A y G, no ve B | API nativa y Subsonic |
| Consulta de ID conocido de B | No devuelve metadatos privados | Código y respuesta sanitizada |
| Stream/descarga de B por A | No devuelve bytes del archivo | Estado y tamaño/hash de respuesta |
| Portada de B por A | No revela arte privado | Petición directa, sin depender de enlaces visibles |
| Publicación de A | B accede solo tras autorización administrativa | Estado antes/después y archivo sin duplicar |
| Retirada de global | Nuevas peticiones de B dejan de acceder | Sesión/token previos y cliente nuevo |
| Permisos modificados por miembro | Rechazo y permisos sin cambios | API administrativa directa |
| Listas con contenido revocado | No permiten recuperar archivos ajenos | Lista preexistente y peticiones directas |
| Reinicio/reescaneo | Mantiene permisos e identidad/referencias | Comparación antes/después |
| Subida/cuotas nativas | Capacidad confirmada o limitación documentada | API/documentación y ensayo si existe |
| Cliente móvil | Respeta aislamiento y cambios | Cliente/versión, resultado manual |

Contenido ya descargado o reproducido no puede retirarse del dispositivo mediante permisos del servidor; separar ese límite de nuevas solicitudes autorizadas incorrectamente. Decidir explícitamente qué ocurre con streams ya abiertos.

## Entregables y cierre
- Informe de capacidades para la versión exacta, con fuentes oficiales y fecha.
- Laboratorio aislado, instrucciones Linux/Windows y limpieza solo de sus propios recursos.
- Pruebas de web/API/Subsonic y casos de fallos; integración en CI proporcional, sin activar el laboratorio por cada cambio documental.
- Decisión: compatible; compatible con restricciones explícitas; o incompatible y alternativas a evaluar.
- No marcar S01 completo sin ejecución real. Si Docker no está disponible para el agente, preparar ejecución en CI y dejar cliente móvil/manual claramente pendiente.

## Dependencias
PR #6 de auditoría fusionada en main def9466 e incorporada a esta rama. El contrato de eventos se utilizará al implementar operaciones en hitos posteriores. S01 sigue centrado en investigar y probar el aislamiento.

## Investigación inicial — Navidrome 0.64.2
Fuentes oficiales leídas el 2026-11-11, fijadas a la versión del Compose:
- [Modelo Library](https://github.com/navidrome/navidrome/blob/v0.64.2/model/library.go): bibliotecas con ruta y opción defaultNewUsers.
- [Servicio Library](https://github.com/navidrome/navidrome/blob/v0.64.2/core/library.go): asignación por biblioteca; administradores con acceso total; usuarios normales requieren al menos una biblioteca. La creación valida ruta y dispara escaneo.
- [API de asignaciones](https://github.com/navidrome/navidrome/blob/v0.64.2/server/nativeapi/library.go) y [router](https://github.com/navidrome/navidrome/blob/v0.64.2/server/nativeapi/native_api.go): CRUD de bibliotecas y asignaciones protegido por middleware administrativo.
- [Pruebas upstream](https://github.com/navidrome/navidrome/blob/v0.64.2/server/subsonic/e2e/subsonic_multilibrary_test.go): filtrado de búsquedas, listas y bibliotecas.
- [Stream/download](https://github.com/navidrome/navidrome/blob/v0.64.2/server/subsonic/stream.go): lectura a través de repositorios; requiere comprobación runtime de IDs ajenos.

Conclusión de código: existe una base de autorización por biblioteca; no implica que el despliegue actual de Multisuite tenga contenido privado. El Compose habitual monta una biblioteca común. Publicar un archivo individual sin duplicar/moverlo, preservando referencias, NO queda demostrado por conceder una biblioteca completa. Tampoco se ha establecido una API de subida musical o cuotas por usuario; las opciones de subida de imágenes no equivalen a subida de audio. Administradores Navidrome acceden a todo: privado significa aislamiento entre miembros, no frente al operador.

## Laboratorio implementado
Requisitos: Python 3 y Docker en ejecución. Linux: `python3 tests/isolation/navidrome.py`; Windows: `py -3 tests/isolation/navidrome.py`. Descarga la imagen fijada si falta. No usa .env ni music.env y no admite URL de un servidor existente.

Cada invocación crea tres WAV/portadas sintéticos, directorios temporales, un contenedor aleatorio, puerto efímero limitado a 127.0.0.1 y credenciales aleatorias solo en memoria. La biblioteca por defecto apunta a una carpeta vacía. Configura bibliotecas A/B/G por API, usuarios normales A+G y B+G, y prueba listado, búsqueda, IDs directos, descarga/stream/portadas, escalada, concesión/retirada usando JWT previo, playlists y reinicio. Los permisos por defecto de nuevos usuarios y su ventana de aprovisionamiento requieren diseño específico antes de producción.

Elimina únicamente su propio contenedor al finalizar y borra sus fixtures temporales; no monta música real ni usa volúmenes compartidos. No imprimir logs completos ni tokens. Si se mata el proceso de forma abrupta, revisar exclusivamente contenedores con etiqueta `multisuite.lab=s01` antes de limpiar manualmente.

Resultado sanitizado: `s01-report.json` (no versionar). CI específico Library isolation se activa únicamente por cambios del laboratorio/workflow o ejecución manual. Un caso negativo sin control positivo no demuestra aislamiento. Este laboratorio no sustituye QA navegador/cliente móvil, pruebas de streams abiertos ni publicación individual/movimientos. No modificar permisos del servicio habitual hasta completar la decisión.
