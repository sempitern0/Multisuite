# Estado de Multisuite

Instantánea del 18 de noviembre de 2026.

## Integrado en main

Portal privado Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos, cuotas, almacenamiento y auditoría. Navidrome y Kavita disponen de acceso integrado. El usuario validó ambos autologins. PR #38 fusionada con la corrección del montaje de lectura en Docker Desktop.

## En revisión · piloto de Komga

Decisión del usuario: deshabilitar Kavita y evaluar Komga. PR #39 (backup de Kavita) y #40 (sincronización) cerradas sin fusionar. Sus ramas conservan el trabajo.

La rama de Komga incorpora los cambios de subida y sincronización de #40: organización de archivos sueltos, comprobación SHA-256 por fragmento desde HTTPS, offset confirmado y reintentos de sincronización. Se añade Komga 1.30.1 para administradores, sobre los archivos de Lectura existentes. Make inicia el piloto y detiene Kavita; sus datos se conservan para reactivarlo.

La tarjeta se llama Lectura. Gateway con regreso en navegación nativa y escaneo
por cambios desde la sesión administrativa de Komga. Los archivos continúan
en las carpetas conectadas existentes, con una revisión opaca para evitar
escaneos repetidos. No se alteran EPUB, bibliotecas existentes ni progreso.

Validado localmente: lógica JS de sincronización y ciclo Make simulado.
PHP y Docker no están disponibles en el entorno del agente; pruebas reales
en CI pendientes para este incremento. El usuario validó el lector del piloto,
no este último gateway ni el flujo visual de regreso.

## Límites del piloto

Cuenta Komga propia, solo administrador. El montaje contiene archivos
personales de todas las cuentas existentes. El escaneo inmediato depende
de abrir Komga con su sesión administrativa; el espejo NAS se sincroniza
desde Multisuite independientemente del navegador. Ver KOMGA.md.

## Próximos pasos

Validar regreso y actualización de libros/cómics en el entorno del usuario.
Después integrar identidad, bibliotecas privadas y escaneo desde el servidor.
El backup de Kavita y su ensayo siguen preservados en la rama cerrada.
