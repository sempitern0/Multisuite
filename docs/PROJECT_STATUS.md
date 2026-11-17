# Estado de Multisuite

Instantánea del 17 de noviembre de 2026.

## Integrado en main

Portal privado Laravel/Livewire con personas, invitaciones, cuentas de servicio, Mis archivos, cuotas, almacenamiento y auditoría. Navidrome y Kavita disponen de acceso integrado. El usuario validó ambos autologins. PR #38 fusionada con la corrección del montaje de lectura en Docker Desktop.

## En revisión · piloto de Komga

Decisión del usuario: deshabilitar Kavita y evaluar Komga. PR #39 (backup de Kavita) y #40 (sincronización) cerradas sin fusionar. Sus ramas conservan el trabajo.

La rama de Komga incorpora los cambios de subida y sincronización de #40: organización de archivos sueltos, comprobación SHA-256 por fragmento desde HTTPS, offset confirmado y reintentos de sincronización. Se añade Komga 1.30.1 para administradores, sobre los archivos de Lectura existentes. Make inicia el piloto y detiene Kavita; sus datos se conservan para reactivarlo.

Validado localmente: ciclo Make simulado de música, lectura y Komga, sintaxis JavaScript y Python, diff. PHP y Docker ausentes en el entorno del agente. CI tiene prueba de integración con Komga real y EPUB sintético; sus resultados están pendientes. QA del lector con el EPUB del usuario pendiente.

## Límites del piloto

Cuenta Komga propia, biblioteca /data configurada desde su GUI y escaneo periódico del servicio. Acceso familiar aislado, aprovisionamiento y autologin pendientes. El montaje contiene archivos personales de todas las cuentas existentes y está destinado exclusivamente al administrador de la prueba. Se documentan operación y reactivación en KOMGA.md.

## Próximos pasos

Validar lectura completa, comodidad y progreso en Komga. Después integrar identidad, bibliotecas privadas y escaneo automático inmediato. El backup de Kavita y su ensayo de recuperación siguen preservados en la rama cerrada.
