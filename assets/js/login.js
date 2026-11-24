/* Pantalla de entrada: carrusel de noticias y mostrar/ocultar contraseña. */
(function () {
    'use strict';

    // --- Mostrar / ocultar contraseña ---
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        var input = document.querySelector(btn.getAttribute('data-toggle-password'));
        if (!input) { return; }
        btn.addEventListener('click', function () {
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
            btn.querySelector('i').className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
            input.focus();
        });
    });

    var news = document.querySelector('[data-news]');
    var el = document.getElementById('newsCarousel');
    if (!news || !el || typeof bootstrap === 'undefined') { return; }

    var items = Array.prototype.slice.call(el.querySelectorAll('.carousel-item'));
    var dots = Array.prototype.slice.call(news.querySelectorAll('.news-dot'));
    var layers = news.querySelectorAll('.news-backdrop-layer');
    var counter = news.querySelector('[data-news-current]');
    var pauseBtn = news.querySelector('[data-news-pause]');
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var visible = 0;

    var carousel = bootstrap.Carousel.getOrCreateInstance(el, {
        interval: 7000,
        ride: reduce || items.length < 2 ? false : 'carousel',
        pause: 'hover',
        touch: true,
        keyboard: true
    });

    // Fondo difuminado con fundido entre dos capas
    function fondo(index) {
        var url = items[index] ? items[index].getAttribute('data-bg') : '';
        var siguiente = layers[1 - visible];
        if (!siguiente) { return; }
        siguiente.style.backgroundImage = url ? 'url("' + url.replace(/"/g, '%22') + '")' : 'none';
        siguiente.classList.add('is-visible');
        layers[visible].classList.remove('is-visible');
        visible = 1 - visible;
    }

    function marcar(index) {
        dots.forEach(function (d, i) {
            d.classList.remove('active');
            d.removeAttribute('aria-current');
            if (i === index) {
                void d.offsetWidth;          // reinicia la animación de la barra
                d.classList.add('active');
                d.setAttribute('aria-current', 'true');
            }
        });
        if (counter) { counter.textContent = String(index + 1).padStart(2, '0'); }
    }

    el.addEventListener('slide.bs.carousel', function (ev) {
        marcar(ev.to);
        fondo(ev.to);
    });

    // Pausa / reanudar (también se pausa con el cursor encima)
    if (pauseBtn) {
        var setPausa = function (pausado) {
            news.classList.toggle('is-paused', pausado);
            pauseBtn.setAttribute('aria-pressed', pausado ? 'true' : 'false');
            pauseBtn.setAttribute('aria-label', pausado ? 'Reanudar' : 'Pausar');
            pauseBtn.querySelector('i').className = pausado ? 'bi bi-play-fill' : 'bi bi-pause-fill';
            if (pausado) { carousel.pause(); } else { carousel.cycle(); }
        };
        pauseBtn.addEventListener('click', function () {
            setPausa(!news.classList.contains('is-paused'));
        });
        if (reduce) { setPausa(true); }
        // Al quitar el cursor, Bootstrap reanuda: respeta la pausa manual.
        el.addEventListener('mouseleave', function () {
            if (news.classList.contains('is-paused')) { carousel.pause(); }
        });
    }

    // Cuando la pestaña no se ve, no avanza
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { carousel.pause(); } else if (!news.classList.contains('is-paused') && !reduce) { carousel.cycle(); }
    });

    layers[0].style.backgroundImage = 'none';
    fondo(0);
})();
