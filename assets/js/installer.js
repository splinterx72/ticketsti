/* Asistente de instalación — complementos propios (lo genérico vive en app.js) */
(function () {
    'use strict';

    // Formato de la clave XXXX-XXXX-XXXX mientras se escribe.
    var key = document.getElementById('clave');
    if (key) {
        key.addEventListener('input', function () {
            var raw = key.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
            key.value = raw.replace(/(.{4})(?=.)/g, '$1-');
        });
    }
})();
