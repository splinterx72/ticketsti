/* Selector de ubicación física en cascada (mejora progresiva). */
(function () {
    'use strict';

    var ALAS = { na: 'N/A', norte: 'Norte', sur: 'Sur', oriente: 'Oriente', poniente: 'Poniente' };

    function option(value, text, selected) {
        var o = document.createElement('option');
        o.value = String(value);
        o.textContent = text;
        if (selected) { o.selected = true; }
        return o;
    }

    function init(root) {
        var data;
        try { data = JSON.parse(root.getAttribute('data-catalogo') || '[]'); } catch (e) { return; }
        var inst = root.querySelector('[data-role="instalacion"]');
        var ub = root.querySelector('[data-role="ubicacion"]');
        var niv = root.querySelector('[data-role="nivel"]');
        var ala = root.querySelector('[data-role="ala"]');
        var ind = root.querySelector('[data-role="indicaciones"]');
        if (!inst || !ub || !niv || !ala) { return; }

        function findInst(id) {
            for (var i = 0; i < data.length; i++) { if (String(data[i].id) === String(id)) { return data[i]; } }
            return null;
        }
        function findUb(id) {
            for (var i = 0; i < data.length; i++) {
                for (var j = 0; j < data[i].ubicaciones.length; j++) {
                    if (String(data[i].ubicaciones[j].id) === String(id)) { return { inst: data[i], ub: data[i].ubicaciones[j] }; }
                }
            }
            return null;
        }

        function fillUbicaciones() {
            var current = ub.value;
            var i = findInst(inst.value);
            ub.innerHTML = '';
            ub.appendChild(option('', i ? 'Selecciona…' : 'Primero elige la instalación', false));
            if (i) {
                i.ubicaciones.forEach(function (u) { ub.appendChild(option(u.id, u.nombre, String(u.id) === current)); });
            }
            ub.disabled = !i;
            fillDetalle();
        }

        function fillDetalle() {
            var f = findUb(ub.value);
            var curN = niv.value, curA = ala.value;
            niv.innerHTML = '';
            ala.innerHTML = '';
            if (!f) {
                niv.appendChild(option('', '—', true));
                ala.appendChild(option('', '—', true));
                niv.disabled = ala.disabled = true;
                if (ind) { ind.classList.add('d-none'); }
                root.dispatchEvent(new CustomEvent('ubicacion:change', { bubbles: true, detail: { instalacion: inst.value } }));
                return;
            }
            f.ub.niveles.forEach(function (n) { niv.appendChild(option(n.id, n.nombre, String(n.id) === curN)); });
            f.ub.alas.forEach(function (a) { ala.appendChild(option(a, ALAS[a] || a, a === curA)); });
            // Un solo valor posible ("Único" / "N/A"): se muestra fijo.
            niv.disabled = false; ala.disabled = false;
            niv.classList.toggle('pe-none', f.ub.niveles.length === 1);
            ala.classList.toggle('pe-none', f.ub.alas.length === 1);
            if (ind) {
                ind.classList.toggle('d-none', !f.ub.indicaciones);
                ind.querySelector('span').textContent = f.ub.indicaciones || '';
            }
            root.dispatchEvent(new CustomEvent('ubicacion:change', { bubbles: true, detail: { instalacion: inst.value } }));
        }

        // API para que el formulario de ticket preseleccione la ubicación del equipo.
        root.ubicacionPicker = {
            set: function (ubicacionId, nivelId, alaVal) {
                var f = findUb(ubicacionId);
                if (!f) { return; }
                inst.value = String(f.inst.id);
                fillUbicaciones();
                ub.value = String(ubicacionId);
                fillDetalle();
                if (nivelId) { niv.value = String(nivelId); }
                if (alaVal) { ala.value = alaVal; }
            },
            instalacion: function () { return inst.value; }
        };

        inst.addEventListener('change', fillUbicaciones);
        ub.addEventListener('change', fillDetalle);
        fillUbicaciones();
    }

    document.querySelectorAll('.ubicacion-picker').forEach(init);
})();
