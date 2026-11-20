# Asistente y puente MCP

RFC del hito MCP-00 (2026-11-20), que sustituye al *Estudio de integración MCP* del 15 de noviembre de 2026 (hecho sobre `f633d5d`, PR #35) y lo pone al día con el estado actual y las librerías estables publicadas desde entonces.

**Estado:** MCP-01 implementado y validado en CI (ver § Implementado). No hay chat ni ruta MCP pública todavía.

## Objetivo
- Conectar uno o varios modelos de proveedores externos que puedan **consultar y actuar sobre Multisuite** como chatbot dentro del portal y, más adelante, desde clientes MCP externos (Claude, ChatGPT, clientes locales).
- **Solo la cuenta propietaria (`owner`) configura el asistente**: proveedores, modelos, herramientas activas, quién puede usarlo y límites. No existe un rol nuevo: «superadmin» es `owner`.
- Quien usa el asistente actúa **siempre con sus propios permisos**. El asistente no amplía derechos: el propietario tampoco ve archivos ajenos a través de él.

## Qué cambia respecto al estudio
| Estudio (15-nov) | Ahora | Motivo |
| --- | --- | --- |
| Evaluar `laravel/mcp` o `mcp/sdk` (experimental) | `laravel/mcp` 1.x, sin `mcp/sdk` | v1.0 estable desde el 15-sep-2026 con la revisión 2026-07-28 y respuesta 2025-11-25/2025-06-18 a clientes antiguos; v1.2.1 (17-nov) admite Laravel 13 y PHP 8.2+ |
| Adaptador de proveedores LLM propio | `laravel/ai` 1.x | Política «librería antes que código propio»: v1.0 estable (23-sep), Anthropic, OpenAI, Gemini y más, conversaciones guardadas, conmutación por error y **aprobación de herramientas** |
| Políticas owner/admin para configurar | Solo `owner` (gate `manage-assistant`) | Decisión del usuario 2026-11-20 |
| Bibliotecas de música y lectura comunes | Ya existen bibliotecas privadas (S04a en Navidrome; Komga en PR #41) | El catálogo futuro hereda ese aislamiento |
| Kavita como lector | Komga 1.30.1 activo; Kavita desactivado | PR #41 |
| Empezar por OAuth/Passport y el endpoint externo | Empezar por el chat interno y las herramientas de lectura; OAuth cuando exista red privada (P03) | El objetivo principal es el chatbot; exponer `/mcp` sin VPN/TLS no es aceptable |
| Registro dinámico de clientes OAuth | Client ID Metadata Documents (CIMD) y PKCE | La revisión 2026-07-28 deja obsoleto el registro dinámico; `laravel/mcp` 1.0 admite CIMD (por verificar si permite desactivar el registro dinámico) |
| Tablas `agent_jobs`, `agent_outbox`, `agent_chats` | Conversaciones de `laravel/ai`; `outbox`/trabajos se aplazan al indexado | Menos esquema propio |

## Arquitectura
```
Chat Livewire ──► laravel/ai (agente, proveedor elegido por el owner)   [MCP-02]
                        │ herramientas en el mismo proceso
                        ▼
      Herramientas laravel/mcp (app/Mcp/Tools)  ◄── cliente externo → /mcp + OAuth   [MCP-05]
                        │ AssistantAccess: acceso + interruptor + límite + registro
                        ▼
     PersonalFiles · StorageOverview · ServiceTiles · ServiceAccounts · Actions/*
```
- **Una sola definición por herramienta**, como herramienta de `laravel/mcp`, usada por el chat y por clientes externos. Ninguna contiene SQL de negocio: llaman a los mismos servicios y Actions que Livewire.
- **El chat no se llama a sí mismo por HTTP.** Comprobado en el código de `laravel/ai` v1.4.1 (`Tools/McpServerTool`): un agente acepta instancias de herramientas de `laravel/mcp` y llama a su `handle()` en el mismo proceso. Las herramientas de `app/Mcp/Tools` son por tanto la definición única; como ese camino no pasa por `shouldRegister()`, cada herramienta repite en `handle()` la comprobación de acceso, interruptor y presupuesto.
- **El servidor MCP no necesita modelo.** Funciona aunque no haya chat ni proveedor configurado.
- **Sin acceso directo** a volúmenes, base de datos, credenciales de servicio, socket Docker ni shell. El asistente no recibe rutas físicas ni URLs arbitrarias.

## Configuración (solo `owner`)
Página *Administración › Asistente*, protegida con el gate `manage-assistant` (solo `owner`) en cada petición:
- Activar o desactivar el asistente.
- **Proveedores y modelos** (MCP-02): uno o varios de los configurados por el operador en el entorno (D2), con modelo por defecto y lista de conmutación por error. Solo proveedores externos (D3).
- **Personas**: quién puede usarlo (el propietario siempre) y límite diario de consultas por persona; el de tokens llega con el chat.
- **Herramientas**: las de lectura activas; las de escritura se activan una a una. Las administrativas no existen en el MVP.
- **Privacidad** (MCP-02/03): si se permite enviar *contenido* de archivos al proveedor (D3) y la retención del historial.

## Autorización
Cada llamada a una herramienta exige:
```
autenticado ∧ verificado ∧ no suspendido ∧ contraseña inicial cambiada
∧ asistente activo ∧ persona autorizada por el owner
∧ herramienta activa ∧ (cliente externo: concesión vigente para esa capacidad)
∧ Policy/Gate del dominio ∧ elegibilidad del servicio (ServiceAccounts::entitled)
∧ presupuesto disponible ∧ (riesgo alto ⇒ aprobación humana válida)
```
- El sujeto sale de la sesión web o del token; **ninguna herramienta acepta `user_id`**, rutas ni URLs.
- En el chat, las herramientas que modifican algo usan las **aprobaciones de `laravel/ai`** (`Approvable`): el modelo propone, la persona aprueba, rechaza o corrige desde el portal autenticado. Nunca un `approved=true` puesto por el modelo. Antes de reanudar se comprueba que la conversación es suya (`conversationBelongsTo`).
- Para clientes externos (MCP-05), aprobación de un solo uso ligada a persona, cliente, herramienta, hash canónico de argumentos, caducidad y clave de idempotencia.
- `laravel/mcp` solo anuncia el scope OAuth `mcp:use`: las concesiones finas (`files:read`, `files:write`, `storage:read`, `services:read`…) son tablas propias, revocables y comprobadas en cada petición.
- Revocar la concesión, suspender a la persona, quitarle el asistente o desactivarlo corta la siguiente petición, sin esperar a que caduque el token.

## Catálogo de herramientas
Nombres con guion bajo: los proveedores (Anthropic, OpenAI) no admiten puntos en los nombres de herramienta.

| Herramienta | Tipo | Hito | Base existente |
| --- | --- | --- | --- |
| `identity_me` | lectura | MCP-01 | usuario, rol y estado; sin secretos |
| `services_list` | lectura | MCP-01 | `ServiceTiles` + elegibilidad |
| `storage_my_usage` | lectura | MCP-01 | cuota y uso; nunca rutas montadas |
| `files_list` / `files_search` | lectura | MCP-01 | `PersonalFiles` con cursor opaco propio (hoy `browse` corta en 2.000) |
| `files_read_excerpt` | lectura de contenido | MCP-03 | `PersonalFiles::readable` + `StoredBlob`; lista de tipos, máximo 32 KiB por llamada |
| `files_folder_create`, `files_rename`, `files_trash`, `files_restore` | escritura reversible | MCP-04 | métodos homónimos de `PersonalFiles`, con aprobación |
| `music_search`, `reading_search` | lectura de catálogo | MCP-06 | adaptadores nuevos para Navidrome y Komga con credenciales de la persona, nunca las administrativas |
| `files_purge`, cuotas, acceso a servicios, invitaciones | destructiva o administrativa | sin fecha | fuera del MVP; si llegan: owner, verificación adicional y aprobación |

Prompts previstos: `organize_my_files`, `explain_storage_usage`, `summarize_document`. Una plantilla no concede permisos.

## Amenazas y pruebas obligatorias
| Riesgo | Control | Prueba |
| --- | --- | --- |
| Fuga entre personas | Policy en cada consulta, ID y cursor; sin acceso implícito del owner | A no ve nada de B, ni siquiera el owner, ni por caché |
| Instrucciones dentro de archivos | Contenido marcado como dato no confiable; herramientas limitadas y escritura con aprobación | Un documento que ordena borrar no provoca escritura sin aprobación |
| Reutilización de token | OAuth 2.1 con PKCE, audiencia, caducidad, emisor (`iss`) y revocación inmediata | Token de otro recurso o revocado → 401 |
| Reenvío de credenciales | El token MCP nunca llega a Navidrome ni a Komga | El servicio rechaza la credencial MCP |
| Escritura no deseada | Aprobación humana ligada a los argumentos e idempotencia | Alterar argumentos o repetir no ejecuta dos veces |
| SSRF | URLs de proveedor y de servicio solo desde configuración del owner u operador | No se aceptan URLs en argumentos |
| Cuenta suspendida | Middleware propio en rutas MCP (el actual `EnsureAccountIsActive` es solo web) | Token emitido antes de suspender → 403 |
| Extracción masiva | Límites de bytes, llamadas y tokens por persona y día; auditoría | Petición grande rechazada y registrada |
| Éxito inventado | La herramienta solo responde éxito tras confirmar el cambio | Un fallo remoto se muestra como fallo |
| Disponibilidad | Tiempos máximos, colas y servicios opcionales | Un servicio o proveedor caído no bloquea el portal |
| Fuga al proveedor | Contenido de archivos solo con permiso del owner y aceptación de la persona (D3) | Sin permiso, `files_read_excerpt` se niega |

## Implementado (MCP-01)
- `laravel/mcp` v1.2.1. Servidor `App\Mcp\Servers\MultisuiteServer` sin ruta: se prueba con `MultisuiteServer::actingAs($user)->tool(...)`.
- Herramientas de solo lectura: `identity_me`, `services_list`, `storage_my_usage`, `files_list` (por carpeta, 50 archivos por página) y `files_search` (por nombre en todo Mis archivos). Devuelven datos estructurados; nunca contenido, rutas, identificadores de subida, correo ni direcciones de servicios.
- Base `AssistantTool`: la persona es siempre el usuario de la sesión, releído en cada llamada; comprueba asistente activo, correo verificado, no suspendido, contraseña inicial cambiada, acceso concedido por el owner y herramienta activa; reserva una consulta del límite diario bajo bloqueo; registra el resultado (`ok`, `error`, `denied`, `budget`). Un ID de otra persona responde «No existe o no es tuyo.».
- *Administración › Asistente* (solo owner, con confirmación de contraseña): activar, herramientas activas, límite diario por persona (50–1000) y quién puede usarlo, con consultas del día. Cambios en *Actividad*.
- Pruebas (`tests/Feature/Mcp/AssistantToolsTest`): sin activar no hay herramientas; solo el owner y las personas elegidas; A no ve archivos de B ni el owner los de nadie; suspensión y contraseña inicial cortan la siguiente llamada; herramienta desactivada y límite diario; argumentos inválidos como fallo; paginación; un admin no configura ni concede acceso.

## Datos nuevos
- Tablas de `laravel/ai` para conversaciones (`agent_conversations`, `agent_conversation_messages`), con retención configurable y borrado por la persona.
- `assistant_settings` (una fila: activo, herramientas, límite diario) y `assistant_members` (persona y quién concedió el acceso). Las claves de proveedor solo en el entorno (D2).
- `assistant_tool_calls`: herramienta, persona, resultado, duración y fecha; sirve de límite diario y de registro, y se purga a los 90 días (`model:prune`). Nunca argumentos, contenido, nombres de archivo, rutas ni respuestas del modelo. El cliente (chat o externo) se añadirá con MCP-05.
- MCP-05: `agent_clients`, `agent_grants`, `agent_approvals`.
- Todas entran en la copia de seguridad de la base de datos; las claves no (viven en `.env`).

## Plan por PR
Cada hito tiene su rama y su PR (apilada si la anterior no está fusionada), Pint, PHPStan nivel 7 y pruebas. Se fusiona solo con la aprobación del usuario.

| Hito | Alcance | Se cierra cuando |
| --- | --- | --- |
| **MCP-00** | Este RFC, prioridad en el ROADMAP, evaluación de dependencias | Decisiones D1–D4 tomadas (2026-11-20) |
| **MCP-01** Núcleo | `laravel/mcp`; gate `manage-assistant` y página de configuración; autorización, presupuesto y auditoría; herramientas de lectura de metadatos; servidor MCP probado con `Server::actingAs`, sin ruta pública | Las pruebas de A/B, owner sin acceso ajeno, suspensión y no-owner que no configura pasan en CI |
| **MCP-02** Chat | `laravel/ai`; página *Asistente* con respuesta en streaming, proveedores del owner, conmutación por error, historial propio (30 días) y presupuesto | Pruebas con los *fakes* de `laravel/ai`; QA visual del usuario con un proveedor real |
| **MCP-03** Contenido | `files_read_excerpt` (texto, Markdown y PDF con extractor aislado) y permiso de nube | Ninguna lectura supera los límites ni sale a la nube sin permiso |
| **MCP-04** Escritura | Crear carpeta, renombrar, papelera y restaurar con aprobación en el chat | Idempotencia y auditoría probadas; rechazar no cambia nada |
| **MCP-05** Clientes externos | Passport + `Mcp::oauthRoutes`, pantalla de consentimiento con `x-ui::`, concesiones, revocación y límites; compatibilidad 2026-07-28 y 2025-11-25 | Necesita P03 (VPN/TLS); probado con Claude y otro cliente |
| **MCP-06** Catálogos | Búsqueda en Navidrome y Komga con permisos reales de la persona | Sin duplicar catálogos; ACL real verificada en CI |
| **MCP-07** Búsqueda semántica | Indexado opcional por persona, embeddings del proveedor con el permiso de D3, eventos de cambio | Borrar o mandar a la papelera retira del índice; copia y restauración probadas |
| **MCP-08** Piloto | Dos personas, dos dispositivos, red privada | QA real y vuelta atrás ensayada |


## Decisiones del usuario (2026-11-20)
- **D1. Quién usa el chat:** el owner elige a las personas; por defecto, solo él.
- **D2. Claves de proveedores:** solo en variables de entorno (`.env` del operador). La página elige entre los proveedores configurados y nunca muestra ni guarda claves.
- **D3. Proveedores:** solo externos (Anthropic, OpenAI, Gemini…); no se ofrece modelo local porque en un VPS estándar no da resultados fiables. Los metadatos van al proveedor; el *contenido* de archivos (MCP-03) solo si el owner lo permite y la persona lo acepta.
- **D4. Historial:** 30 días; cada persona puede borrar sus conversaciones.

## Referencias
- Laravel MCP: https://laravel.com/docs/13.x/mcp · Laravel MCP 1.0: https://laravel-news.com/laravel-mcp-1-0
- Laravel AI SDK: https://laravel.com/docs/13.x/ai-sdk · v1.0: https://laravel.com/blog/introducing-laravel-ai-sdk-v1
- Especificación MCP 2026-07-28: https://modelcontextprotocol.io/specification/2026-07-28 · cambios: https://modelcontextprotocol.io/specification/2026-07-28/changelog
- Versiones consultadas en Packagist el 2026-11-20: `laravel/mcp` v1.2.1, `laravel/ai` v1.4.1, `laravel/passport` v13.12.0.
