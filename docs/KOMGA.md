# Komga · piloto local

Komga 1.30.1 se prueba como lector de EPUB, PDF y cómics. La tarjeta del portal y la ruta de acceso exigen rol owner o admin. El servicio conserva su propia cuenta y su interfaz.

## Arranque y primera lectura

1. Actualiza esta rama y ejecuta `make up`. En desarrollo inicia Komga y detiene el stack de Kavita, conservando sus volúmenes.
2. Abre «Lectura · Komga» desde Multisuite o http://127.0.0.1:25600.
3. En el primer acceso crea la cuenta administradora de Komga desde su pantalla inicial.
4. En Komga crea una biblioteca llamada «Prueba de lectura» con carpeta `/data`. Activa escanear al arrancar y el escaneo periódico que prefieras.
5. Abre un EPUB que ya hubieras subido a las carpetas conectadas de Multisuite. Comprueba principio, una sección intermedia y final, cambia la tipografía y vuelve a entrar para comprobar el progreso.

El montaje /data incluye las carpetas personales de Lectura de todos los usuarios existentes. Este piloto es exclusivamente administrativo. La cuenta administradora de Komga puede ver todo ese montaje. El acceso del portal no protege la URL directa; la protección del servicio es su autenticación y el puerto publicado solo en loopback. Mantén exclusivamente la cuenta de prueba administradora en esta fase.

Los archivos ya conectados se leen desde el volumen Docker `multisuite-nas-dev`, en solo lectura. Las nuevas subidas a esas carpetas siguen publicándose automáticamente. Komga aplica su propio intervalo de escaneo. El piloto aún no solicita un escaneo inmediato a su API ni configura cuentas familiares o autologin.

Instalaciones nuevas sin carpetas conectadas de Lectura requieren completar el siguiente incremento de aprovisionamiento; este piloto está preparado para evaluar el contenido existente del entorno actual.

## Operación

- `make komga-status`, `make komga-logs`, `make komga-stop`.
- `make stop` y `make down` incluyen Komga. Sus datos viven en `multisuite-komga_komga-config`.
- `KOMGA_PORT` y `PORTAL_KOMGA_URL` deben cambiarse conjuntamente si el puerto 25600 está ocupado.
- Para reactivar Kavita: `make komga-stop`, después `make up READING_AUTO=1 KOMGA_AUTO=0`. Conserva estos argumentos en posteriores arranques mientras uses Kavita.
- Este Compose usa el NAS de desarrollo. Con `NAS_DEV=0`, Komga queda desactivado automáticamente. Los discos reales y el acceso remoto requieren su configuración de producción.

## Validación

Se prueban el ciclo Make, el acceso administrativo y, en CI con Docker, el arranque del Compose, la creación de biblioteca, el análisis de un EPUB sintético largo y la descarga con SHA-256 idéntico al original. La prueba utiliza volúmenes temporales con nombres únicos y contenido generado. La interfaz visual y el EPUB del usuario requieren QA en su navegador.

## Siguiente incremento

Aprovisionamiento con las credenciales del portal, bibliotecas privadas por persona y permisos comprobados entre dos usuarios, escaneo inmediato con reintentos y entrada automática. El progreso de Kavita permanece en su base de datos y no se ha migrado a Komga.
