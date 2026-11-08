# Navidrome independiente: prueba local
Adaptado de private-nas, rama feat/music-library-player, commit a97d48ee8872dfcd36ada9b6be364428bc4a90ff. Este stack no necesita Laravel, PostgreSQL, Redis ni generar app/.

## Qué incluye
compose.music.yaml es un Compose autónomo con proyecto propio: no combinarlo con compose.yaml. Publica solo 127.0.0.1:4533, conserva /data y monta música en solo lectura. La app de Navidrome es la interfaz de música.
Se fija navidrome/navidrome:0.64.2, versión estable publicada consultada el 2026-11-08; revisar actualizaciones y fijar digest tras probar la imagen. No reutiliza datos ni migra el antiguo 0.55.2 automáticamente.
No se crean usuarios/passwords en Git ni se guardan credenciales de Navidrome en Laravel.

## Arranque integrado con Make
Una vez creado music.env y preparadas las carpetas, make up levanta también Navidrome. No copia ni reemplaza configuración o datos. Si music.env no existe, solo arranca Laravel.

| Comando | Resultado |
| --- | --- |
| make up / make up-detached | Arranca ambos stacks |
| make up-wait | Espera el arranque de ambos; Navidrome no tiene healthcheck HTTP, comprobar navegador |
| make start | Reanuda Laravel y crea/reanuda Navidrome |
| make stop / make restart | Detiene/reanuda ambos |
| make down | Retira ambos stacks sin borrar carpetas de música/data |
| make up MUSIC_AUTO=0 | Arranca solo Laravel; no detiene Navidrome si ya estaba activo |
| make music-up / music-stop | Controla únicamente Navidrome |
| make music-status / music-logs | Estado y logs de Navidrome |
| make music-config | Valida su Compose sin arrancarlo |

music.env es el opt-in del ciclo de vida; PORTAL_MUSIC_ENABLED solo controla la tarjeta del portal. Mantener music.env mientras se quiera gestionar el stack con Make. No cambiar MUSIC_PROJECT_NAME ni las rutas de datos de una instalación existente sin plan de migración.
make up-logs arranca música en segundo plano y sigue logs de Laravel; usar make music-logs para música. make setup/build/rebuild y reset-project siguen dedicados a Laravel. Los comandos Docker directos también continúan siendo independientes.
Si Navidrome falla, Make devuelve error; Laravel puede haber arrancado ya. Consultar make music-logs y corregir configuración, sin resetear datos.

## Arranque desde la raíz del repositorio
Requiere Docker y Compose v2. Linux/macOS:

~~~sh
cp music.env.example music.env
mkdir -p local/navidrome/music local/navidrome/data
id -u
id -g
~~~

Editar NAVIDROME_UID/GID en music.env con los valores reales del propietario de data; debe poder escribir data y leer music. No usar chmod 777. Para biblioteca existente usar ruta absoluta aprobada; no montar todo home.
Windows PowerShell:

~~~powershell
Copy-Item music.env.example music.env
New-Item -ItemType Directory -Force local/navidrome/music, local/navidrome/data
~~~

Con Docker Desktop, compartir la unidad/carpeta si se solicita. Si usas rutas Windows en music.env, escribir barras /, por ejemplo C:/Users/tuusuario/Music. Verificar permisos efectivos; en WSL usar UID/GID del propietario Linux.
Copiar unos MP3/FLAC propios a music. En ambos entornos:

~~~sh
docker compose --env-file music.env -f compose.music.yaml config --quiet
docker compose --env-file music.env -f compose.music.yaml up -d
docker compose --env-file music.env -f compose.music.yaml ps
docker compose --env-file music.env -f compose.music.yaml logs --tail=80 navidrome
~~~

Abrir http://127.0.0.1:4533 y crear el administrador inicial antes de compartir acceso. Crear cuentas normales individuales para familiares. El navegador de otro dispositivo no puede usar este localhost; necesitará URL VPN/proxy del servidor.
Añadir/quitar archivos desde el host o una herramienta de importación externa; no se implementa un uploader Laravel. Navidrome escanea cada cinco minutos.
Parar con stop; volver con start o up -d. down elimina contenedor/red, conserva carpetas bind. No borrar data para actualizar.

## QA pendiente de ejecución Docker
- [ ] Acceder, crear admin y cuenta normal; contraseña equivocada rechazada.
- [ ] Escanear muestra MP3/FLAC y reproducir/pausar/buscar dentro de canción.
- [ ] Crear favorito/playlist desde Navidrome.
- [ ] Recrear contenedor y comprobar usuarios, listas y biblioteca.
- [ ] Comprobar que el contenedor no puede escribir música y sí escribir data.
- [ ] Verificar escucha solo en loopback y ausencia de acceso desde otra máquina sin túnel.
- [ ] Restaurar copia de data y música en carpetas/proyecto de prueba.
Esta entrega valida estructura YAML, no arranque, permisos o reproducción: el entorno de trabajo no dispone de Docker.

## Ruta de acceso en Multisuite
Primero una tarjeta Música con URL configurada por el administrador. Nunca aceptar destinos de redirección libres desde parámetros de usuario.
Contrato futuro: /services/music redirige a una URL permitida, por ejemplo http://127.0.0.1:4533 en desarrollo. No es un proxy PHP ni inicia sesión en Navidrome.
Para servir realmente bajo /music/, configurar ND_BASEURL=/music y proxy que preserve ese prefijo; probar assets, login, streaming/Range y clientes Subsonic. No usar strip-prefix sin ajustar el contrato.
Subdominio privado es alternativa si un servicio no funciona bien bajo subruta. No incrustar interfaces mediante iframe por defecto.
El portal no protege automáticamente las rutas del servicio: conservar autenticación propia y restricciones de VPN. Ocultar una tarjeta no revoca una cuenta.

## Producción y recuperación
La configuración mantiene loopback como base segura para un proxy/VPN en el host. Un proxy en otro contenedor no accede a este localhost: diseñar una red explícita y conexión a navidrome:4533 sin publicar el puerto.
Completar TLS, VPN, backups y pruebas antes de compartir. No activar autenticación por cabeceras confiables sin un diseño comprobado.
Para copia consistente sencilla, detener Navidrome, copiar data completa y la colección de música a destino independiente, y arrancar. Restaurar sobre una copia vacía con misma versión/permisos; luego probar actualización. No copiar SQLite activo como si fuera un backup consistente.
Antes de actualizar, respaldar y revisar notas de versión. Una imagen anterior puede no aceptar una DB migrada: recuperar copia compatible si es necesario.
Fuentes: https://www.navidrome.org/docs/installation/docker/ y https://github.com/navidrome/navidrome/releases/tag/v0.64.2

## Tarjeta en el portal
En app/.env configurar PORTAL_MUSIC_ENABLED=true y PORTAL_MUSIC_URL=http://127.0.0.1:4533. Ejecutar `make artisan CMD="config:clear"` y abrir /dashboard autenticado. El enlace abre la UI independiente; no proporciona SSO. Esta URL solo sirve en el mismo ordenador.
