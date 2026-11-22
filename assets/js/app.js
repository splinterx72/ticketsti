/* Mesa de Ayuda TI — comportamiento ligero del lado del cliente */
(function () {
    'use strict';

    var form = document.getElementById('gecaForm');
    if (!form) {
        return;
    }

    var input = document.getElementById('archivo');
    var button = document.getElementById('gecaSubmit');
    var wait = document.getElementById('gecaWait');

    form.addEventListener('submit', function (event) {
        var file = input && input.files && input.files[0];
        if (!file) {
            return;
        }

        var ext = file.name.split('.').pop().toLowerCase();
        var allowed = (input.getAttribute('accept') || '').replace(/\./g, '').split(',');
        if (allowed.indexOf(ext) === -1) {
            event.preventDefault();
            alert('Formato no permitido. Usa ' + input.getAttribute('accept'));
            return;
        }

        var max = parseInt(input.getAttribute('data-max-bytes') || '0', 10);
        if (max > 0 && file.size > max) {
            event.preventDefault();
            alert('El archivo excede el tamaño máximo permitido (' + Math.floor(max / 1048576) + ' MB).');
            return;
        }

        if (!confirm('¿Procesar "' + file.name + '"? Los equipos que no aparezcan en el archivo quedarán como no vigentes.')) {
            event.preventDefault();
            return;
        }

        // Evita doble envío mientras el servidor procesa.
        button.disabled = true;
        button.querySelector('.spinner-border').classList.remove('d-none');
        button.querySelector('.label').textContent = 'Procesando…';
        if (wait) {
            wait.classList.remove('d-none');
        }
    });
})();
