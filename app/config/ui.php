<?php

return [
    // Active visual theme: resources/views/themes/{theme}/ (Blade components under the
    // `x-ui::` namespace, including the app and auth layouts) plus
    // resources/css/themes/{theme}.css. Views never reference a theme directly.
    'theme' => env('UI_THEME', 'nocturne'),
];
