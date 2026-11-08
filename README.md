# Multisuite
Portal privado Laravel + Livewire para acceder a servicios existentes. Cada aplicación conserva su interfaz y gestiona sus usuarios/datos: Navidrome para música; los demás servicios se incorporarán progresivamente.

**Estado:** portada de aplicaciones y redirecciones autenticadas implementadas; validación runtime y visual pendiente. Navidrome local preparado, aún pendiente de prueba Docker.

## Probar música sin instalar Laravel
Seguir [Navidrome local](docs/NAVIDROME.md): copiar music.env.example, crear carpetas y ejecutar:

~~~sh
docker compose --env-file music.env -f compose.music.yaml up -d
~~~

Con music.env preparado, `make up` arranca también Navidrome; `make stop/start/restart` gestionan ambos stacks. Para omitir música en una llamada: `make up MUSIC_AUTO=0`. Diagnóstico: `make music-status` y `make music-logs`.

Abrir http://127.0.0.1:4533. El archivo es autónomo: no combinar con compose.yaml.
Música en solo lectura, datos persistentes separados, puerto solo loopback y sin catálogo/reproductor Laravel duplicados.

## Continuar
- [Planning vigente P00–P05](docs/ROADMAP.md)
- [Estado y siguiente paso](docs/PROJECT_STATUS.md)
- [Análisis y rescate de private-nas](docs/PRIVATE_NAS_REVIEW.md)
- [Instrucciones comunes](AGENTS.md) y [Claude](CLAUDE.md)
- [Entornos](docs/ENVIRONMENTS.md) y [dependencias](docs/DEPENDENCIES.md)

## Portal y producción
Infraestructura heredada de [Laravel Foundry](https://github.com/sempitern0/laravel-foundry). Bootstrap previsto: make setup STARTER=livewire en checkout sin app configurada; Windows sin Make: scripts/setup.ps1 -Starter livewire.
Versionar app/ y lockfiles; nunca secretos o datos. No ejecutar reset para actualizar.
La portada autenticada está en /dashboard; /services/music es una redirección controlada. Activar PORTAL_MUSIC_ENABLED=true y configurar PORTAL_MUSIC_URL en app/.env; ejecutar make artisan CMD="config:clear". Servir realmente bajo /music requiere proxy y BaseUrl comprobados. No hay SSO implícito.
Producción exige VPN/TLS, cuentas por servicio y backups/restauración verificados; todavía no está desplegada. Ver [guía heredada](docs/DEPLOYMENT.md) y contrato de entornos vigente.
Librerías PHP/JS/CSS permitidas si estables, mantenidas y útiles; documentar compatibilidad/licencia.
Licencia del repositorio: [LICENSE](LICENSE). Los servicios conservan sus licencias.
