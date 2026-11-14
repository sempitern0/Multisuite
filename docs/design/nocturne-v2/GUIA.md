# Multisuite · Nocturne v2

**Actualización de diseño (14/11/2026).** Prototipo navegable independiente, oscuro y sin dependencias externas. Parte de la reconstrucción de Nocturne anterior. **No modifica el repositorio Multisuite ni tiene autenticación real:** usuarios, estados, mensajes y servicios mostrados son ejemplos de interfaz.

## Decisiones de producto aplicadas

1. **Idioma comprensible:** mensajes breves sobre acciones y resultados. Fuera de la interfaz quedan los términos de arquitectura, permisos internos, proveedores técnicos o explicaciones para desarrolladores.
2. **Autenticación independiente:** «Iniciar sesión» conserva su vista propia; «Confirmar contraseña» se añade como segunda pantalla autónoma, y «Nueva contraseña» completa el recorrido de recuperación. No se agrupan todos los pasos en un modal.
3. **Inicio por perfil:** los usuarios normales llegan a `Servicios` (índice 6, número 7 en selector). El administrador puede acceder a `Resumen` (índice 5, número 6) con cifras generales. Los miembros no ven estadísticas ni enlaces de administración.
4. **Servicios como protagonista:** tarjetas con iconografía SVG universal y de trazo uniforme (música, carpeta, documento, paquete, notas, vídeo). Una sola palabra como nombre y un estado breve; nunca títulos internos como «Navidrome» en el catálogo.
5. **Navegación orientada a prioridades:** «Servicios» primero; administración a continuación, solo cuando se tiene permiso; notificaciones y actividad después; perfil, seguridad, preferencias y cierre de sesión fijos abajo.
6. **Móvil:** menú lateral desplegable, dos columnas de servicios, botones con estados de foco, contraste oscuro y consideración de movimiento reducido.

## Ver el prototipo

Abre `index.html` directamente en el navegador; no necesitas servidor. En la franja superior de **vista previa** hay dos menús:

- **Usuario / Administrador**: simula lo que verá cada perfil. Al elegir `Usuario` se inicia en `Tus servicios`; con `Administrador` se abre `Resumen`.
- **Pantalla**: lleva directamente a cualquiera de las 28 vistas. Las páginas de gestión aparecen deshabilitadas en el selector de «Usuario».

Los botones de acceso y las tarjetas permiten recorrer el diseño. Las operaciones simulan mensajes y no modifican datos.

## Pantallas (28)

| N.º | Pantalla | Visible para |
|---:|---|---|
| 01 | Bienvenida | Sin sesión |
| 02 | Iniciar sesión | Sin sesión |
| 03 | Crear cuenta por invitación | Sin sesión |
| 04 | Recuperar acceso | Sin sesión |
| 05 | Código de seguridad | Sin sesión |
| 06 | Resumen | Administración |
| 07 | Servicios | Todos los usuarios |
| 08 | Música | Usuarios autorizados |
| 09 | Información del servicio | Usuarios autorizados |
| 10 | Abriendo servicio | Usuarios autorizados |
| 11 | En mantenimiento | Usuarios autorizados |
| 12 | No disponible | Usuarios autorizados |
| 13 | Mi perfil | Todos los usuarios |
| 14 | Preferencias | Todos los usuarios |
| 15 | Seguridad | Todos los usuarios |
| 16 | Notificaciones | Todos los usuarios |
| 17 | Actividad | Todos los usuarios |
| 18 | Personas | Administración |
| 19 | Invitar persona | Administración |
| 20 | Crear usuario | Administración |
| 21 | Permisos | Administración |
| 22 | Accesos | Administración |
| 23 | Ajustes generales | Administración |
| 24 | Avisos del sistema | Administración |
| 25 | Ventanas de confirmación | Vista de ejemplo |
| 26 | Mensajes breves | Vista de ejemplo |
| 27 | Confirmar contraseña | Autenticación / seguridad |
| 28 | Nueva contraseña | Autenticación / recuperación |

## Guía de integración Laravel + Livewire

El HTML permite revisar el diseño pero **no debe incorporarse sin adaptar los controles de seguridad y el ciclo de autenticación**. Para implantarlo:

### 1. Mantener y rediseñar las vistas que existen

- Localizar las rutas y pantallas existentes de inicio de sesión y confirmación de contraseña. En Laravel suelen estar relacionadas con las rutas nombradas `login` y `password.confirm`, pero deben **verificarse dentro del repositorio**.
- Crear el layout de autenticación Nocturne y aplicar su apariencia a las vistas actuales, sin reemplazar los controladores, middleware, validaciones, manejo de errores ni lógica de Fortify/Breeze/Jetstream si se utilizan.
- Conservar también el restablecimiento por correo, la invitación y, si existe, la verificación en dos pasos; cada flujo debe mantener su validación y sus protecciones actuales.

### 2. Definir el inicio por permisos en el servidor

- En usuario estándar, establecer la ruta principal hacia el catálogo de servicios autorizados.
- En administrador, establecer la ruta hacia el resumen, o permitir cambiar entre resumen y servicios. El prototipo coloca «Servicios» primero en ambos casos.
- El acceso a datos agregados, gestión de usuarios y configuración debe protegerse mediante políticas/gates/middleware en Laravel. El simple hecho de ocultar entradas del menú **no protege una ruta**.
- Las métricas del administrador se obtendrán de consultas reales y filtradas; los valores `3/6`, `12`, `3` y `0` son ejemplos.

### 3. Aplicar el catálogo minimalista

- Diseñar un componente Blade/Livewire `service-tile` con icono, nombre, estado y destino permitido por el servidor. No exponer información de despliegue, cuentas externas ni nombres internos al usuario final.
- La tarjeta Música debe continuar el acceso existente a Navidrome. No crear un segundo reproductor ni saltar las comprobaciones de identidad o sesión externa.
- En servicios no disponibles, abrir una pantalla de estado legible; en los no asignados, no mostrar el enlace ni permitir acceso por URL.
- Configurar la disponibilidad real desde el servidor, no desde las constantes del prototipo.

### 4. Navegación y componentes

- Mantener tres áreas: acceso principal, administración (condicional), y cuenta fijada en el pie del menú.
- Convertir botones, tarjetas, campos, mensajes y diálogos en componentes reutilizables; mantener foco de teclado y `prefers-reduced-motion`.
- Los avisos de éxito deben mostrarse **solo después** de que el servidor confirme un cambio; los mensajes de ejemplo del HTML no representan acciones ejecutadas.
- Evitar incorporar la barra «Vista previa» a la aplicación final: es exclusiva para revisar diseños y alternar perfiles de prueba.

## Paleta y diseño

Se mantiene Nocturne (solo oscuro): fondo `#0d100e`, tarjetas `#171c18`, línea `#303a31`, texto `#edf1e9`, acento `#dfec70`. Tipografía de sistema compatible con Inter / Segoe UI. Iconos SVG integrados sin solicitudes externas. Animaciones breves con reducción de movimiento compatible.

## Comprobaciones realizadas

- Renderizado de las 28 vistas, con comprobación de errores JavaScript.
- Inicio de usuario sin estadísticas; inicio de administrador con métricas visibles.
- Recorrido inicio de sesión → código de seguridad → pantalla principal por rol.
- Vista independiente de confirmación de contraseña → retorno a Seguridad.
- Recuperación de acceso y validación básica de coincidencia de contraseñas (solo en maqueta).
- Menú móvil expandible, navegación por «Seguridad», sin desplazamiento horizontal a 390 px.
- Modal cerrable con Escape y acceso limitado a páginas administrativas en la simulación.

**Estado:** diseño visual aprobado para revisión, **no desplegado** en Multisuite.
