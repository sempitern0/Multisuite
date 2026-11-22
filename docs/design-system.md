# Diseño del portal
Nocturne comparte las mismas vistas Blade en móvil, tablet y escritorio. El color de fondo, panel, texto, texto secundario, borde, acento y los cuatro tonos de estado proceden de `nocturne-tokens.css`. El botón sol/luna de la barra superior (y de las pantallas de acceso) alterna Claro y Oscuro en el dispositivo; Preferencias guarda además «Según el sistema». El encabezado resuelve la elección antes de cargar CSS. Se usan fuentes del sistema.

## Color
Paleta definida en OKLCH y convertida a hexadecimal en `nocturne-tokens.css`:
- **Neutros** pizarra fríos (tono 255, croma ≤ 0,015). Son complementarios divididos del lima de la marca: el acento destaca por contraste simultáneo en vez de fundirse con un gris verdoso.
- **Marca** lima (tono 118). Un amarillo verdoso oscurecido vira a oliva, así que los tonos de acento oscuros (texto en claro, superficies teñidas en oscuro) giran hacia verde (tono 138).
- **Dos papeles del acento:** `--ui-accent` rellena superficies con `--ui-accent-ink` encima (botón principal, marca, selección); `--ui-accent-text` colorea texto, iconos, bordes, foco y barras de progreso sobre el fondo. En oscuro coinciden; en claro el segundo es verde oscuro.
- **Estados** (`ok`, `warn`, `bad`, `info`) con tonos fijos (150, 70-85, 28, 255). Avisos, etiquetas, toasts y botón de peligro derivan fondo, borde y texto con `color-mix()` a partir de `--tone`, así que funcionan en los dos temas sin colores propios.
- **Contraste** medido en el navegador para ambos temas: texto ≥ 4,5:1 en cualquier panel (muted 4,8:1 en claro), bordes de campo y foco ≥ 3:1.
- **Roles:** `x-ui::role` pinta el rol con el color de su papel (propietario ámbar, administrador azul, miembro verde) y barras según cuánto puede hacer (`User::roleLevel()`, 3/2/1). Contraste del texto ≥ 6,5:1.
- `nocturne.css` no lleva colores escritos a mano (lo comprueba `DesignSystemTest`); solo negro y blanco para superposiciones sobre imágenes, QR y PDF, y la escala fija `zinc`/`teal` de `flux:otp`.

Usa los componentes de `themes/nocturne/components` para campos, navegación, tarjetas, diálogo, tabla, avisos y estados vacíos. Conserva el nombre accesible del control mientras se procesa la acción. La ayuda y el error se asocian por ID. En pantallas táctiles los controles frecuentes tienen una superficie mínima de 44 px. Las transiciones del botón usan transform y respetan el movimiento reducido.

El estado visual pertenece al navegador. La búsqueda paginada, los permisos y la escritura pertenecen al servidor. Los componentes costosos deben medirse antes de diferirse. El recortador de avatar se descarga al seleccionar una imagen.

La revisión de diseño compara el build de referencia y el candidato en dos entornos desechables. El workflow design-review conserva capturas y JSON de Lighthouse y axe. Los informes de laboratorio no certifican conformidad WCAG ni sustituyen el recorrido con lector de pantalla.

El código de verificación es `x-ui::otp`: un solo campo numérico con `autocomplete="one-time-code"` (lo rellenan gestores de contraseñas y SMS, y los lectores de pantalla lo anuncian como un campo), cifras espaciadas por CSS. Ya no hay hoja aparte ni Flux.

En Servicios, las tarjetas pasan a una columna hasta 35 rem. El icono identifica cada fila y la flecha aparece cuando hay un destino. El estado conserva su texto y contraste en ambas paletas.

## Casillas e interruptores
`x-ui::checkbox` es una fila completa, como en los ajustes de GitHub, Linear o Stripe: toda la fila marca o desmarca, mide al menos 44 px, texto de 14 px, casilla de 20 px y fondo teñido cuando está marcada, para repasar una lista de un vistazo. Con `switch` se muestra como interruptor al final de la fila (`role="switch"`), para un ajuste que se enciende o apaga entero; sin él, para elegir opciones de una lista. `description` añade una línea de explicación anunciada como descripción (`aria-describedby`), no como parte del nombre. Las listas van en `<fieldset class="ui-check-group">`.

## Tablas
Una fila se marca como elegida cuando contiene un control con `aria-current="true"` (por ejemplo, el mes cuyo detalle se muestra debajo): fondo teñido y barra de acento a la izquierda, sin estilos en la vista. Las celdas `nowrap` (tamaños, fechas, importes) y los valores de `x-ui::stats` usan cifras de ancho fijo para que se alineen por dígito.

## Cifras, gráficos y columnas
- `x-ui::metrics`: tarjetas de cifras principales con tono (`ok`, `bad`, `accent`), símbolo de moneda opcional y una línea secundaria; dos por fila en el móvil.
- `x-ui::month-chart`: barras emparejadas (ingresos/gastos) por periodo; cada periodo es un botón con nombre accesible que incluye los importes, y el elegido lleva `aria-current`. La tabla equivalente va en un desplegable para quien prefiera leer cifras.
- `x-ui::breakdown`: reparto de un total con barra de proporción y porcentaje.
- `x-ui::columns`: contenido principal y columna lateral fija al desplazarse en pantallas de 1180 px o más; debajo en las estrechas.
- `x-ui::field prefix`: signo fijo delante del valor (moneda). `x-ui::uploader` admite `accept`, `multiple`, `capture` y `variant`.
- Los iconos de los botones no encogen (`flex: none`): antes, un botón de solo icono dentro de `x-ui::segmented` dejaba el icono en 6 px.
