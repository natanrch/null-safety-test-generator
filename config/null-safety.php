<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Missing request parameter status
    |--------------------------------------------------------------------------
    |
    | HTTP status expected when a generated test intentionally omits a request
    | parameter used to load a model. The default follows the convention that
    | a missing resource should result in a 404 response.
    |
    */
    'missing_parameter_status' => env(
        'NULL_SAFETY_MISSING_PARAMETER_STATUS',
        404
    ),
];
