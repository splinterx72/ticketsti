/* Mesa de Ayuda TI — mejoras progresivas del lado del cliente (todo funciona sin JS) */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    //  Mostrar / ocultar contraseñas
    // ------------------------------------------------------------------
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

    // ------------------------------------------------------------------
    //  Medidor de fortaleza (orientativo; el servidor valida)
    // ------------------------------------------------------------------
    document.querySelectorAll('[data-strength]').forEach(function (input) {
        var bar = document.querySelector(input.getAttribute('data-strength'));
        var text = document.getElementById('strength-text');
        var min = parseInt(input.getAttribute('data-min') || '10', 10);
        var initial = text ? text.textContent : '';
        if (!bar) { return; }
        input.addEventListener('input', function () {
            var v = input.value;
            var classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
            var score = 0;
            if (v.length >= min) { score += 2; }
            if (v.length >= min + 4) { score += 1; }
            score += Math.max(0, classes - 1);
            var levels = [[0, 'bg-danger', 'Muy débil'], [2, 'bg-danger', 'Débil'], [3, 'bg-warning', 'Aceptable'], [4, 'bg-info', 'Buena'], [5, 'bg-success', 'Excelente']];
            var level = levels[0];
            levels.forEach(function (l) { if (score >= l[0]) { level = l; } });
            bar.className = 'progress-bar ' + level[1];
            bar.style.width = v.length === 0 ? '0%' : Math.min(100, (score / 6) * 100) + '%';
            if (text) {
                text.textContent = v.length === 0
                    ? initial
                    : 'Fortaleza: ' + level[2] + (v.length < min ? ' · faltan ' + (min - v.length) + ' caracteres' : '');
            }
        });
    });

    // ------------------------------------------------------------------
    //  Confirmación de acciones (texto en data-confirm, nunca en JS en línea)
    // ------------------------------------------------------------------
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });

    // ------------------------------------------------------------------
    //  Spinner y bloqueo de doble envío
    // ------------------------------------------------------------------
    document.querySelectorAll('form.needs-loading').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (!btn) { return; }
            setTimeout(function () { btn.disabled = true; }, 0);
            var spinner = btn.querySelector('.spinner-border');
            if (spinner) { spinner.classList.remove('d-none'); }
        });
    });

    // ------------------------------------------------------------------
    //  Buscador dentro de un <select> (ubicaciones)
    // ------------------------------------------------------------------
    var normalize = function (s) {
        return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    };
    document.querySelectorAll('[data-filter-select]').forEach(function (input) {
        var select = document.querySelector(input.getAttribute('data-filter-select'));
        if (!select) { return; }
        input.addEventListener('input', function () {
            var terms = normalize(input.value).split(/\s+/).filter(Boolean);
            var firstVisible = null;
            Array.prototype.forEach.call(select.options, function (opt) {
                var text = normalize(opt.textContent);
                var match = terms.every(function (t) { return text.indexOf(t) !== -1; });
                opt.hidden = !match && !opt.selected;
                if (match && !firstVisible) { firstVisible = opt; }
            });
            if (firstVisible && select.selectedOptions.length && select.selectedOptions[0].hidden) {
                firstVisible.selected = true;
            }
        });
    });

    // ------------------------------------------------------------------
    //  Formulario de nuevo ticket
    // ------------------------------------------------------------------
    var ticketForm = document.getElementById('ticketForm');
    if (ticketForm) {
        var equipo = document.getElementById('equipo_id');
        var picker = ticketForm.querySelector('.ubicacion-picker');
        var hostname = document.getElementById('hostname_reportado');
        var aviso = document.getElementById('discrepanciaAviso');
        var gecaInfo = document.getElementById('gecaInfo');
        var monitorInfo = document.getElementById('monitorInfo');
        var equipoOnly = ticketForm.querySelectorAll('.equipo-only');

        var selected = function () { return equipo.options[equipo.selectedIndex]; };

        var refreshDiscrepancia = function () {
            var opt = selected();
            var gecaInst = opt ? opt.getAttribute('data-geca-inst') : '';
            var actual = picker && picker.ubicacionPicker ? picker.ubicacionPicker.instalacion() : '';
            var show = equipo.value !== '0' && gecaInst && actual && actual !== gecaInst;
            if (aviso) { aviso.classList.toggle('d-none', !show); }
            if (gecaInfo) {
                var geca = opt && equipo.value !== '0' ? opt.getAttribute('data-geca') : '';
                gecaInfo.textContent = geca ? 'En el resguardo GECA figura en: ' + geca : '';
            }
        };

        var refreshEquipo = function (moveUbicacion) {
            var opt = selected();
            var hasEquipo = equipo.value !== '0';
            equipoOnly.forEach(function (el) { el.classList.toggle('d-none', !hasEquipo); });

            var m1 = opt ? opt.getAttribute('data-m1') : '';
            var m2 = opt ? opt.getAttribute('data-m2') : '';
            var r1 = document.getElementById('comp_monitor_1');
            var r2 = document.getElementById('comp_monitor_2');
            if (r1 && r2) {
                r1.disabled = !m1; r2.disabled = !m2;
                document.querySelector('label[for="comp_monitor_1"]').classList.toggle('d-none', !m1);
                document.querySelector('label[for="comp_monitor_2"]').classList.toggle('d-none', !m2);
                if ((r1.checked && !m1) || (r2.checked && !m2)) {
                    document.getElementById('comp_equipo').checked = true;
                }
                if (!hasEquipo) {
                    document.getElementById('comp_general').checked = true;
                }
            }
            if (monitorInfo) {
                monitorInfo.textContent = hasEquipo && (m1 || m2)
                    ? 'Monitores casados: ' + [m1 && ('1: ' + m1), m2 && ('2: ' + m2)].filter(Boolean).join(' · ')
                    : (hasEquipo ? 'Este equipo no tiene monitores registrados en el GECA.' : '');
            }
            if (hostname) {
                hostname.placeholder = hasEquipo && opt ? 'PMxxx' + opt.getAttribute('data-serie') : 'PMxxx + serie';
            }
            if (moveUbicacion && hasEquipo && opt && opt.getAttribute('data-ub') && picker && picker.ubicacionPicker) {
                picker.ubicacionPicker.set(opt.getAttribute('data-ub'), opt.getAttribute('data-niv'), opt.getAttribute('data-ala'));
            }
            refreshDiscrepancia();
        };

        if (equipo) {
            equipo.addEventListener('change', function () { refreshEquipo(true); });
            if (picker) { picker.addEventListener('ubicacion:change', refreshDiscrepancia); }
            refreshEquipo(false);
        }
        if (hostname) {
            hostname.addEventListener('input', function () {
                var pos = hostname.selectionStart;
                hostname.value = hostname.value.toUpperCase().replace(/\s+/g, '');
                hostname.setSelectionRange(pos, pos);
            });
        }
    }

    // ------------------------------------------------------------------
    //  Carga GECA
    // ------------------------------------------------------------------
    var gecaForm = document.getElementById('gecaForm');
    if (gecaForm) {
        var input = document.getElementById('archivo');
        var button = document.getElementById('gecaSubmit');
        var wait = document.getElementById('gecaWait');

        gecaForm.addEventListener('submit', function (event) {
            var file = input && input.files && input.files[0];
            if (!file) { return; }

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
            button.disabled = true;
            button.querySelector('.spinner-border').classList.remove('d-none');
            button.querySelector('.label').textContent = 'Procesando…';
            if (wait) { wait.classList.remove('d-none'); }
        });
    }

    // --- Vista previa de imagen (avisos): input[type=file][data-preview="#marco"] ---
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        var marco = document.querySelector(input.getAttribute('data-preview'));
        if (!marco) { return; }
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            var aviso = marco.parentNode.querySelector('.aviso-imagen-aviso');
            if (aviso) { aviso.remove(); }
            if (!file) { return; }
            var max = parseInt(input.getAttribute('data-max-bytes') || '0', 10);
            var img = marco.querySelector('img') || document.createElement('img');
            img.alt = 'Vista previa';
            img.onload = function () {
                URL.revokeObjectURL(img.src);
                var msg = '';
                if (img.naturalWidth < 960 || img.naturalHeight < 540) {
                    msg = 'La imagen mide ' + img.naturalWidth + '×' + img.naturalHeight + ' px; se necesita al menos 960×540.';
                } else if (Math.abs(img.naturalWidth / img.naturalHeight - 16 / 9) > 0.08) {
                    msg = 'No es 16:9: en el carrusel se recortará al centro (así se ve en esta vista previa).';
                }
                if (max > 0 && file.size > max) {
                    msg = 'La imagen pesa más de ' + Math.floor(max / 1048576) + ' MB.';
                }
                if (msg) {
                    var p = document.createElement('div');
                    p.className = 'aviso-imagen-aviso small text-warning-emphasis mb-2';
                    p.textContent = msg;
                    marco.insertAdjacentElement('afterend', p);
                }
            };
            img.src = URL.createObjectURL(file);
            marco.insertBefore(img, marco.firstChild);
            marco.classList.remove('is-empty');
        });
    });
})();
