# Asistente y puente MCP

RFC del hito MCP-00 (2026-11-20). Parte del *Estudio de integración MCP* del 15 de noviembre de 2026, que se hizo sobre `main` en `f633d5d` (PR #35). Este documento lo sustituye y lo pone al día con el estado actual (PR #41) y con las librerías estables publicadas desde entonces. **Todavía no hay nada implementado.**

## Objetivo
- Conectar uno o varios modelos (locales o en la nube) que puedan **consultar y actuar sobre Multisuite** como chatbot dentro del portal y, más adelante, desde clientes MCP externos (Claude, ChatGPT, clientes locales).
- **Solo la cuenta propietaria (`owner`) configura el asistente**: proveedores, modelos, herramientas activas, quién puede usarlo y límites. No existe un rol nuevo: «superadmin» es `owner`.
- Quien usa el asistente actúa **siempre con sus propios permisos**. El asistente no amplía derechos: el propietario tampoco ve archivos ajenos a través de él.

## Qué cambia respecto al estudio
| Estudio (15-nov) | Ahora | Motivo |
| --- | --- | --- |
| Evaluar `laravel/mcp` o `mcp/sdk` (experimental) | `laravel/mcp` 1.x, sin `mcp/sdk` | v1.0 estable desde el 15-sep-2026 con la revisión 2026-07-28 y respuesta 2025-11-25/2025-06-18 a clientes antiguos; v1.2.1 (17-nov) admite Laravel 13 y PHP 8.2+ |
| Adaptador de proveedores LLM propio | `laravel/ai` 1.x | Política «librería antes que código propio»: v1.0 estable (23-sep), Ollama, OpenAI-compatible, Anthropic, OpenAI, Gemini…, conversaciones guardadas, conmutación por error y **aprobación de herramientas** |
| Políticas owner/admin para configurar | Solo `owner` (gate `manage-assistant`) | Decisión del usuario 2026-11-20 |
| Bibliotecas de música y lectura comunes | Ya existen bibliotecas privadas (S04a en Navidrome; Komga en PR #41) | El catálogo futuro hereda ese aislamiento |
| Kavita como lector | Komga 1.30.1 activo; Kavita desactivado | PR #41 |
| Empezar por OAuth/Passport y el endpoint externo | Empezar por el chat interno y las herramientas de lectura; OAuth cuando exista red privada (P03) | El objetivo principal es el chatbot; exponer `/mcp` sin VPN/TLS no es aceptable |
| Registro dinámico de clientes OAuth | Client ID Metadata Documents (CIMD) y PKCE | La revisión 2026-07-28 deja obsoleto el registro dinámico; `laravel/mcp` 1.0 admite CIMD (por verificar si permite desactivar el registro dinámico) |
| Tablas `agent_jobs`, `agent_outbox`, `agent_chats` | Conversaciones de `laravel/ai`; `outbox`/trabajos se aplazan al indexado | Menos esquema propio |

## Arquitectura
```
Chat Livewire ──► laravel/ai (agente, proveedor elegido por el owner)
                        │ herramientas
                        ▼
              Capacidades del asistente (app/Assistant)  ◄── laravel/mcp (servidor /mcp, después)
                        │ autorización + auditoría + límites
                        ▼
     PersonalFiles · StorageOverview · ServiceTiles · ServiceAccounts · Actions/*
```
- **Una sola definición por herramienta.** Cada capacidad (esquema, permisos, ejecución) vive una vez y tiene dos adaptadores finos: herramienta de `laravel/ai` para el chat y herramienta de `laravel/mcp` para clientes externos. Ninguna contiene SQL de negocio: llaman a los mismos servicios y Actions que Livewire.
- **El chat no se llama a sí mismo por HTTP.** `laravel/ai` puede usar herramientas de un servidor `laravel/mcp` (cliente MCP). *Hipótesis a comprobar en MCP-01:* si las usa en el mismo proceso sin red, el servidor MCP es la definición única; si no, se mantiene el doble adaptador.
- **El servidor MCP no necesita modelo.** Funciona aunque no haya chat ni proveedor configurado.
- **Sin acceso directo** a volúmenes, base de datos, credenciales de servicio, socket Docker ni shell. El asistente no recibe rutas físicas ni URLs arbitrarias.

## Configuración (solo `owner`)
Página *Administración › Asistente*, protegida con el gate `manage-assistant` (solo `owner`) en cada petición:
- Activar o desactivar el asistente.
- **Proveedores y modelos**: uno o varios, con modelo por defecto y lista de conmutación por error. Proveedor local (Ollama u OpenAI-compatible) recomendado por defecto. Las claves nunca vuelven al navegador (ver decisión D2).
- **Personas**: quién puede usar el chat (por defecto, solo el propietario) y presupuesto diario de tokens y de llamadas por persona.
- **Herramientas**: las de lectura activas; las de escritura se activan una a una. Las administrativas no existen en el MVP.
- **Privacidad**: si se permite enviar *contenido* de archivos a proveedores en la nube (D3) y la retención del historial.

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
| Herramienta | Tipo | Hito | Base existente |
| --- | --- | --- | --- |
| `identity.me` | lectura | MCP-01 | usuario, rol y estado; sin secretos |
| `services.list` | lectura | MCP-01 | `ServiceTiles` + elegibilidad |
| `storage.my_usage` | lectura | MCP-01 | cuota y uso; nunca rutas montadas |
| `files.list` / `files.search` | lectura | MCP-01 | `PersonalFiles` con cursor opaco propio (hoy `browse` corta en 2.000) |
| `files.read_excerpt` | lectura de contenido | MCP-03 | `PersonalFiles::readable` + `StoredBlob`; lista de tipos, máximo 32 KiB por llamada |
| `files.folder.create`, `files.rename`, `files.trash`, `files.restore` | escritura reversible | MCP-04 | métodos homónimos de `PersonalFiles`, con aprobación |
| `music.search`, `reading.search` | lectura de catálogo | MCP-06 | adaptadores nuevos para Navidrome y Komga con credenciales de la persona, nunca las administrativas |
| `files.purge`, cuotas, acceso a servicios, invitaciones | destructiva o administrativa | sin fecha | fuera del MVP; si llegan: owner, verificación adicional y aprobación |

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
| Fuga a la nube | Proveedor local por defecto; contenido a la nube solo con permiso explícito | Sin permiso, `read_excerpt` se niega con un proveedor remoto |

## Datos nuevos
- Tablas de `laravel/ai` para conversaciones (`agent_conversations`, `agent_conversation_messages`), con retención configurable y borrado por la persona.
- `assistant_settings` (una fila) y `assistant_members` (persona, presupuesto). Las claves de proveedor, según D2.
- `assistant_tool_calls`: auditoría con lista blanca (correlación, herramienta, persona, cliente, resultado, duración, contadores). Nunca argumentos completos, contenido, nombres de archivo, rutas ni respuestas del modelo.
- MCP-05: `agent_clients`, `agent_grants`, `agent_approvals`.
- Todas en la copia de seguridad, salvo las claves si viven en `.env`.

## Plan por PR
Cada hito tiene su rama y su PR (apilada si la anterior no está fusionada), Pint, PHPStan nivel 7 y pruebas. Se fusiona solo con la aprobación del usuario.

| Hito | Alcance | Se cierra cuando |
| --- | --- | --- |
| **MCP-00** | Este RFC, prioridad en el ROADMAP, evaluación de dependencias | El usuario resuelve las decisiones abiertas |
| **MCP-01** Núcleo | `laravel/mcp` y `laravel/ai`; gate `manage-assistant` y página de configuración; capas de autorización y auditoría; herramientas de lectura de metadatos; servidor MCP probado con `Server::actingAs`, sin ruta pública | Las pruebas de A/B, owner sin acceso ajeno, suspensión y no-owner que no configura pasan en CI |
| **MCP-02** Chat | Página *Asistente* con respuesta en streaming, proveedores del owner, conmutación por error, historial propio y presupuesto | Pruebas con los *fakes* de `laravel/ai`; QA visual del usuario con un modelo local |
| **MCP-03** Contenido | `files.read_excerpt` (texto, Markdown y PDF con extractor aislado) y permiso de nube | Ninguna lectura supera los límites ni sale a la nube sin permiso |
| **MCP-04** Escritura | Crear carpeta, renombrar, papelera y restaurar con aprobación en el chat | Idempotencia y auditoría probadas; rechazar no cambia nada |
| **MCP-05** Clientes externos | Passport + `Mcp::oauthRoutes`, pantalla de consentimiento con `x-ui::`, concesiones, revocación y límites; compatibilidad 2026-07-28 y 2025-11-25 | Necesita P03 (VPN/TLS); probado con Claude y otro cliente |
| **MCP-06** Catálogos | Búsqueda en Navidrome y Komga con permisos reales de la persona | Sin duplicar catálogos; ACL real verificada en CI |
| **MCP-07** Búsqueda semántica | Indexado opcional por persona, embeddings locales, eventos de cambio | Borrar o mandar a la papelera retira del índice; copia y restauración probadas |
| **MCP-08** Piloto | Dos personas, dos dispositivos, red privada | QA real y vuelta atrás ensayada |

Opcional, sin fecha: perfil `compose.ai.yaml` con Ollama, después de medir el hardware real del servidor.

## Decisiones abiertas (usuario)
- **D1. Quién usa el chat.** Recomendado: el owner elige personas; por defecto, solo él.
- **D2. Claves de proveedores.** (a) Solo en `.env`, como pide hoy AGENTS.md para las credenciales administrativas, y la página elige entre los proveedores configurados; o (b) introducidas en la página, cifradas en la base de datos y nunca devueltas al navegador, lo que exige ampliar la regla de AGENTS.md. Recomendado: (a) en MCP-01, (b) si resulta incómodo.
- **D3. Contenido privado y nube.** Recomendado: modelo local por defecto; con un proveedor en la nube, metadatos sí y contenido de archivos solo si el owner lo permite y la persona lo acepta.
- **D4. Retención del historial.** Recomendado: 30 días; cada persona puede borrar sus conversaciones.

## Referencias
- Laravel MCP: https://laravel.com/docs/13.x/mcp · Laravel MCP 1.0: https://laravel-news.com/laravel-mcp-1-0
- Laravel AI SDK: https://laravel.com/docs/13.x/ai-sdk · v1.0: https://laravel.com/blog/introducing-laravel-ai-sdk-v1
- Especificación MCP 2026-07-28: https://modelcontextprotocol.io/specification/2026-07-28 · cambios: https://modelcontextprotocol.io/specification/2026-07-28/changelog
- Versiones consultadas en Packagist el 2026-11-20: `laravel/mcp` v1.2.1, `laravel/ai` v1.4.1, `laravel/passport` v13.12.0.
