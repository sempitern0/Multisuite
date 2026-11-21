# Diseño del portal
Nocturne comparte las mismas vistas Blade en móvil, tablet y escritorio. El color de fondo, panel, texto, texto secundario, borde, acento y los cuatro tonos de estado proceden de `nocturne-tokens.css`. El control de tema en Preferencias guarda Sistema, Claro u Oscuro en el dispositivo. El encabezado resuelve la elección antes de cargar CSS. Se usan fuentes del sistema.

Usa los componentes de `themes/nocturne/components` para campos, navegación, tarjetas, diálogo, tabla, avisos y estados vacíos. Conserva el nombre accesible del control mientras se procesa la acción. La ayuda y el error se asocian por ID. En pantallas táctiles los controles frecuentes tienen una superficie mínima de 44 px. Las transiciones del botón usan transform y respetan el movimiento reducido.

El estado visual pertenece al navegador. La búsqueda paginada, los permisos y la escritura pertenecen al servidor. Los componentes costosos deben medirse antes de diferirse. El recortador de avatar se descarga al seleccionar una imagen.

La revisión de diseño compara el build de referencia y el candidato en dos entornos desechables. El workflow design-review conserva capturas y JSON de Lighthouse y axe. Los informes de laboratorio no certifican conformidad WCAG ni sustituyen el recorrido con lector de pantalla.

El bundle común excluye los estilos de Flux. La entrada `otp.css` se solicita en el desafío de doble factor y los ajustes de seguridad, donde se renderiza `flux:otp`. Si se introduce otro control Flux en una pantalla, incluir su hoja al verificar el flujo.

En Servicios, las tarjetas pasan a una columna hasta 35 rem. El icono identifica cada fila y la flecha aparece cuando hay un destino. El estado conserva su texto y contraste en ambas paletas.
