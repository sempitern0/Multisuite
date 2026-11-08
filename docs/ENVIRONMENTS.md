# Entornos de Multisuite
Contrato de implementación para H01/H02. La infraestructura heredada es una referencia: este documento no certifica un despliegue ejecutado.

## Objetivo
Trabajar localmente y desplegar la misma versión sin cambios de código. El operador solo debe aportar host, dominio/resolución DNS, configuración VPN/TLS, secretos, almacenamiento y backup. No se pueden preconfigurar credenciales reales ni prometer producción sin estas entradas.

| Aspecto | Desarrollo | Paridad local | Staging / producción |
| --- | --- | --- | --- |
| Código | Bind mount, Vite opcional | Imágenes de release | Mismos digests probados |
| Datos | Sintéticos | Sintéticos, volúmenes separados | Independientes por entorno |
| DB | PostgreSQL, misma major validada | Mismo motor/major | Mismo motor/major |
| Colas | Database con worker | Worker y scheduler reales | Worker y scheduler supervisados |
| Debug | Permitido localmente | Desactivado | Desactivado |
| Entrada | Loopback | Loopback y TLS local confiado | VPN y TLS confiable |
| Herramientas dev | Perfiles opt-in | Ausentes | Ausentes |
| Secretos | Locales ignorados por Git | Propios | Propios, provisionados fuera de Git |

## Base observada y brechas
El scaffold de Foundry contiene compose.yaml, compose.production.yaml, ejemplos de variables, Makefile, scripts Unix/PowerShell y workflow de release.
La app aún debe generarse y verificarse. El Compose de producción heredado utiliza PostgreSQL y no incorpora Redis; conserva exposición por defecto en 0.0.0.0 y worker de 90 segundos. No usarlo sin adaptar la entrada privada, checks y trabajos largos. La red llamada internal no es por sí misma aislamiento de egress.

## Entregables exigidos en H02
- Compose de producción y ejemplos completos/sin secretos, más perfiles opcionales para motores especializados.
- Un procedimiento probado de instalación inicial y otro de actualización; comandos documentados que realmente existan.
- Preflight automatizado: variables obligatorias, placeholders, espacio, permisos, imágenes y puertos. Fallar de forma segura.
- Healthchecks que distingan proceso arrancado de aplicación lista, con readiness y errores visibles.
- Backup/restore externo y runbook de recuperación sin depender del panel.
- Procedimiento de revisión y promoción de la misma imagen entre paridad, staging y producción.

## Configuración del operador
Inventariar variables reales y alinear ejemplos/scripts/configuración, sin crear variables decorativas.
1. Proyecto y entorno: PROJECT_NAME, APP_ENV, APP_URL, APP_DEBUG=false, APP_KEY persistente por entorno.
2. Imágenes: APP_IMAGE y WEB_IMAGE con digest inmutable.
3. Base de datos: nombre, usuario y secreto propios; env de aplicación y DB coherentes.
4. Entrada: PUBLIC_BIND_IP y puertos configurados de forma privada; verificar interfaz VPN disponible antes de iniciar.
5. DNS/TLS: escoger terminación HTTPS en VPN o proxy con certificados confiables. Para dominio privado con ACME, diseñar validación DNS cuando proceda; no abrir HTTP público solo para imitar desarrollo.
6. Archivos: ruta/volumen persistente, capacidad, permisos y política de cuotas.
7. Correo: SMTP operativo para invitación/recuperación o mecanismo alternativo documentado; Mailpit nunca como correo de producción.
8. Backup: repositorio externo cifrado, retención y clave recuperable fuera del servidor.
9. Servicios: URL interna y credenciales independientes, con acceso mínimo por usuario cuando corresponda.

## Flujo local previsto
En checkout limpio, comprobar requisitos Docker/Compose y ejecutar make setup STARTER=livewire. Sin Make en Windows usar scripts/setup.ps1 -Starter livewire. Seleccionar PostgreSQL y colas database; no activar herramientas innecesarias.
Después de configurar y versionar app/, usar los comandos de reconstrucción existentes y lockfiles. Validar instalación limpia sin regenerar claves de una instalación ya usada.
No ejecutar reset-project para arrancar, actualizar o resolver conflictos.

## Flujo de release previsto
1. Tests del alcance y build con lockfiles; construir una vez y registrar digests.
2. Configurar paridad local con ejemplos rellenados y volúmenes nuevos; nunca copiar datos reales.
3. Validar Compose y ejecutar smoke tests de login, permisos, subidas, persistencia y jobs.
4. Promover las mismas imágenes a staging; probar integraciones y restore.
5. Antes de producción, comprobar autorización, backup reciente y compatibilidad de migraciones.
6. Aplicar migraciones en fase controlada, actualizar servicios y reiniciar workers correctamente.
7. Verificar acceso VPN/HTTPS, jobs, archivos y ausencia de exposición pública.
8. Ante fallo, restaurar imagen previa solo si esquema compatible; la recuperación de datos sigue un procedimiento separado.

## Aislamiento y tareas multimedia
Base de datos y archivos de servicios en volúmenes propios. Montar bibliotecas de solo lectura en lectores cuando sea posible.
Procesador de descargas sin root/socket Docker, sin secretos de Laravel ni acceso lateral a bases de datos. Egress permitido solo según necesidad.
Configurar timeout de proceso, timeout de job, retry_after y terminación del contenedor de manera coherente. Cancelación debe detener el trabajo real y limpiar temporales. No copiar el timeout genérico de 90 segundos.
No introducir Kubernetes, Redis o almacenamiento S3 por defecto; añadirlos por evidencia.

## Continuidad y costes
Objetivo inicial propuesto: RPO 24 horas para notas/metadatos y RTO 4 horas, sujeto a validar con volumen real y disponibilidad del operador. Conservar backups diarios y revisar retención/coste; probar restauración antes del piloto y tras cambios de esquema/backup.
Medir disco, memoria, CPU, cola y tráfico. Un VPS pequeño puede servir al piloto sin transcodificación intensiva; no garantiza concurrencia multimedia.
La VPN no garantiza disponibilidad sin Internet. Los apagones requieren contenido descargado y energía local.
