@AGENTS.md

# Instrucciones específicas para Claude Code
- AGENTS.md es la fuente común de instrucciones; no duplicarla aquí ni crear reglas contradictorias.
- Al iniciar o después de compactar contexto, leer docs/PROJECT_STATUS.md, el apartado activo de docs/ROADMAP.md y docs/ARCHITECTURE.md.
- Comprobar con /memory que la importación está cargada cuando esa función esté disponible. Si el cliente no interpreta imports, leer AGENTS.md explícitamente antes de editar.
- Usar la lista de tareas de la sesión para pasos cortos; persistir decisiones y pendientes en el repositorio, no solo en auto-memory.
- No ejecutar /init para regenerar o reemplazar estos archivos sin una necesidad explícita.
- Leer versiones/manifiestos y código antes de proponer APIs de Laravel o Livewire. No asumir que el scaffold ya contiene una app.
- No activar permisos irrestrictos ni modificar configuración global, hooks o MCP del usuario para completar una tarea.
- No delegar ni abrir agentes paralelos salvo solicitud explícita; si se autoriza, asignar archivos distintos y revisar la integración.
- Entregar resumen en español con cambios, pruebas realmente ejecutadas, limitaciones y próxima tarea.
