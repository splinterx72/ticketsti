/* Firma dibujada (mouse, lápiz o dedo) para los formatos, sin librerías. */
(function () {
    'use strict';

    document.querySelectorAll('[data-imprimir]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });

    // En pantalla la hoja carta se escala para caber en su columna (al imprimir, tamaño real).
    function escalarHojas() {
        document.querySelectorAll('.hoja-scroll').forEach(function (cont) {
            var hoja = cont.querySelector('.hoja-carta');
            if (!hoja) { return; }
            hoja.style.zoom = '';
            var escala = Math.min(1, (cont.clientWidth - 2) / hoja.offsetWidth);
            if (escala < 1) { hoja.style.zoom = String(escala); }
        });
    }
    escalarHojas();
    window.addEventListener('resize', escalarHojas);
    window.addEventListener('beforeprint', function () {
        document.querySelectorAll('.hoja-scroll .hoja-carta').forEach(function (h) { h.style.zoom = ''; });
    });
    window.addEventListener('afterprint', escalarHojas);

    document.querySelectorAll('form[data-firma-form]').forEach(function (form) {
        var canvas = form.querySelector('[data-firma-canvas]');
        var dato = form.querySelector('[data-firma-dato]');
        if (!canvas || !dato) { return; }
        var ctx = canvas.getContext('2d');
        var trazado = false;
        var dibujando = false;

        function ajustar() {
            var r = canvas.getBoundingClientRect();
            var escala = window.devicePixelRatio || 1;
            canvas.width = Math.round(r.width * escala);
            canvas.height = Math.round(r.height * escala);
            ctx.setTransform(escala, 0, 0, escala, 0, 0);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0b1f4d';
            trazado = false;
        }
        function punto(ev) {
            var r = canvas.getBoundingClientRect();
            return { x: ev.clientX - r.left, y: ev.clientY - r.top };
        }
        canvas.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            canvas.setPointerCapture(ev.pointerId);
            dibujando = true;
            var p = punto(ev);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(p.x + 0.1, p.y + 0.1);
            ctx.stroke();
        });
        canvas.addEventListener('pointermove', function (ev) {
            if (!dibujando) { return; }
            ev.preventDefault();
            var p = punto(ev);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            trazado = true;
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) {
            canvas.addEventListener(t, function () { dibujando = false; });
        });
        var limpiar = form.querySelector('[data-firma-limpiar]');
        if (limpiar) {
            limpiar.addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); trazado = false; });
        }
        form.addEventListener('submit', function (ev) {
            if (!trazado) {
                ev.preventDefault();
                canvas.classList.add('is-invalid');
                canvas.focus();
                window.alert('Dibuja la firma en el recuadro antes de enviar.');
                return;
            }
            dato.value = canvas.toDataURL('image/png');
        });
        // "El usuario firma también como responsable": pide el motivo.
        var mismo = form.querySelector('[data-mismo-usuario]');
        var motivo = form.querySelector('[data-motivo-mismo]');
        var ficha = form.querySelector('[data-ficha-firmante]');
        if (mismo && motivo) {
            mismo.addEventListener('change', function () {
                motivo.classList.toggle('d-none', !mismo.checked);
                if (ficha) { ficha.disabled = mismo.checked; }
            });
        }
        ajustar();
        window.addEventListener('resize', function () { if (!trazado) { ajustar(); } });
    });
})();
