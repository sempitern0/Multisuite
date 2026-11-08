# Registro de dependencias y decisiones
Se autoriza incorporar librerías PHP/JavaScript/CSS que aporten velocidad, calidad de interfaz o fiabilidad bajo los criterios de AGENTS.md.

## Registro obligatorio por incorporación
| Campo | Contenido requerido |
| --- | --- |
| Fecha y tarea | Hito e ID |
| Paquete / upstream | Nombre y enlace oficial |
| Versión / licencia | Versión estable fijada, compatibilidad y licencia |
| Beneficio | Problema concreto y alternativa existente considerada |
| Mantenimiento | Última release, actividad reciente, comunidad, respuesta a incidencias |
| Seguridad | Avisos conocidos, dependencias transitivas y riesgo |
| Impacto | Tamaño frontend, recursos backend, coste y accesibilidad |
| Validación | Tests/build/auditoría ejecutados y limitaciones |
| Retirada | Cómo exportar datos o sustituirlo |

No hay nuevas librerías instaladas por el hito documental H00. Los paquetes del starter se inventariarán en H01, con sus versiones reales.

## Decisiones de arquitectura iniciales
- D001: producto en este repositorio, infraestructura Foundry heredada y app/ versionable.
- D002: Laravel + Livewire; estado visual inmediato en JS/Alpine cuando aporte valor.
- D003: PostgreSQL y colas database inicialmente. Redis requiere necesidad medible.
- D004: motores existentes para música/lectura, módulos propios para experiencia común y notas.
- D005: cuentas individuales y permisos por recurso; sin SaaS multi-tenant complejo ni cobros automatizados.
- D006: VPN inicial, operador responsable de servidor/backups. Tailscale es candidato del piloto; confirmar antes de desplegar.
- D007: descargas independientes para offline; edición offline y sincronización avanzada fuera del MVP.
Para cambiar una decisión registrar fecha, motivo, alternativas, consecuencias y tareas afectadas; no reescribir la historia como si siempre hubiera sido así.
