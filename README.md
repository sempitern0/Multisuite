# Multisuite

Suite privada para música, vídeos, libros, cómics, notas y documentación, pensada para el propietario, familiares y conocidos. Interfaz Laravel + Livewire, servicios especializados reutilizados y archivos exportables.

**Estado: planificación y scaffold de infraestructura. La aplicación todavía debe generarse; no está desplegada ni lista para uso familiar.**

## Continuar el trabajo
1. Leer [AGENTS.md](AGENTS.md).
2. Consultar [estado y siguiente tarea](docs/PROJECT_STATUS.md).
3. Ejecutar el hito correspondiente del [planning detallado](docs/ROADMAP.md).
4. Revisar el [contrato local/producción](docs/ENVIRONMENTS.md) y registrar nuevas [dependencias/decisiones](docs/DEPENDENCIES.md).

[CLAUDE.md](CLAUDE.md) importa las instrucciones comunes y añade pautas para Claude Code.

## Arquitectura prevista
- Laravel + Livewire, PostgreSQL y colas database.
- Navidrome para música; Komga para lectura; yt-dlp/FFmpeg en procesamiento aislado.
- Notas Markdown, biblioteca, permisos, favoritos y experiencia común propios.
- Acceso privado mediante VPN, cuentas individuales y backups externos.
- Descargas locales para uso offline; una PWA no sustituye sincronización ni backup.

## Desarrollo local
Infraestructura heredada de [Laravel Foundry](https://github.com/sempitern0/laravel-foundry). Este repositorio sí es el producto: versionar app/, migraciones y lockfiles sin secretos.

Requisitos: Docker Engine o Docker Desktop con contenedores Linux, Compose v2 y Git; Make opcional.
Bootstrap previsto, solo cuando no exista una aplicación configurada:

~~~sh
make setup STARTER=livewire
~~~

Windows sin Make:

~~~powershell
powershell -NoProfile -File scripts/setup.ps1 -Starter livewire
~~~

Elegir PostgreSQL y colas database. Estos flujos se validarán para Multisuite en H01.
No usar reset-project para iniciar o actualizar: elimina datos.

## Producción
El objetivo es desplegar sin editar código, aportando variables, dominio/TLS, VPN, secretos y almacenamiento. La configuración heredada requiere adaptación y validación en H02.

- [Contrato de entornos](docs/ENVIRONMENTS.md): requisitos específicos del producto.
- [Guía de despliegue heredada](docs/DEPLOYMENT.md): imágenes y promoción.
- [Reconstrucción portable](docs/PROJECT_REBUILD.md): fuente, lockfiles y configuración.
- [Proveedores](docs/HOSTING_PROVIDERS.md): referencia, no proveedor elegido.

## Dependencias y calidad
Se permiten librerías PHP, JavaScript y CSS estables, mantenidas y utilizadas por la comunidad que aceleren el trabajo o mejoren la experiencia. Revisar compatibilidad/licencia/seguridad, registrar la decisión y versionar lockfiles.
Agrupar cambios, ejecutar checks proporcionales y evitar ejecuciones de CI redundantes. No marcar hitos como completados sin pruebas.

## Licencia
Consultar [LICENSE](LICENSE). Las dependencias y servicios conservan sus propias licencias.
