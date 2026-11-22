/* Asistente de instalación — mejoras progresivas (todo funciona sin JS) */
(function () {
    'use strict';

    // Mostrar / ocultar contraseñas.
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-target'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    // Indicador de fortaleza de contraseña (orientativo; el servidor valida).
    document.querySelectorAll('[data-strength]').forEach(function (input) {
        var bar = document.querySelector(input.getAttribute('data-strength'));
        var text = document.getElementById('strength-text');
        if (!bar) { return; }
        input.addEventListener('input', function () {
            var v = input.value;
            var classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
            var score = 0;
            if (v.length >= 12) { score += 2; }
            if (v.length >= 16) { score += 1; }
            score += Math.max(0, classes - 1);
            var levels = [
                [0, 'bg-danger', 'Muy débil'],
                [2, 'bg-danger', 'Débil'],
                [3, 'bg-warning', 'Aceptable'],
                [4, 'bg-info', 'Buena'],
                [5, 'bg-success', 'Excelente']
            ];
            var level = levels[0];
            levels.forEach(function (l) { if (score >= l[0]) { level = l; } });
            bar.className = 'progress-bar ' + level[1];
            bar.style.width = v.length === 0 ? '0%' : Math.min(100, (score / 6) * 100) + '%';
            if (text) {
                text.textContent = v.length === 0
                    ? 'Mínimo 12 caracteres combinando mayúsculas, minúsculas, números o símbolos.'
                    : 'Fortaleza: ' + level[2] + (v.length < 12 ? ' · faltan ' + (12 - v.length) + ' caracteres' : '');
            }
        });
    });

    // Formato de la clave XXXX-XXXX-XXXX mientras se escribe.
    var key = document.getElementById('clave');
    if (key) {
        key.addEventListener('input', function () {
            var raw = key.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
            key.value = raw.replace(/(.{4})(?=.)/g, '$1-');
        });
    }

    // Spinner y bloqueo de doble envío en pasos lentos (conexión, instalación).
    document.querySelectorAll('form.needs-loading').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn) { return; }
            setTimeout(function () { btn.disabled = true; }, 0);
            var spinner = btn.querySelector('.spinner-border');
            if (spinner) { spinner.classList.remove('d-none'); }
        });
    });
})();
