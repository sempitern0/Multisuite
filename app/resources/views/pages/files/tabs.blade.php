@props(['active'])
{{-- Sections of Mis archivos, shared by the explorer, the trash and the ZIP downloads. active: files|trash|exports. --}}
<x-ui::subnav label="Mis archivos" :items="[
    ['label' => 'Archivos', 'href' => route('files.index'), 'active' => $active === 'files'],
    ['label' => 'Papelera', 'href' => route('files.trash'), 'active' => $active === 'trash'],
    ['label' => 'Descargas ZIP', 'href' => route('files.exports.index'), 'active' => $active === 'exports'],
]" />
