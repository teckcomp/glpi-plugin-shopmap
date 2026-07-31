/**
 * ShopMap — canvas da planta (Bloco 2a).
 *
 * window.ShopMapPlan.mount(rootId, dataId): estado isolado por instância
 * (padrão do ambiente). Lê o JSON do <script type="application/json">,
 * cria o mapa Leaflet em CRS.Simple e coloca a planta como overlay.
 *
 * Zoom sem perda: SVG re-renderiza nítido em qualquer zoom; raster
 * (PNG/JPG) desfoca — o formato-alvo continua sendo SVG.
 */
(function () {
    'use strict';

    function readData(dataId) {
        var el = document.getElementById(dataId);
        if (!el) { return null; }
        try {
            var data = JSON.parse(el.textContent);
            return (data && typeof data === 'object') ? data : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Cria o mapa com a planta ocupando bounds [[0,0],[h,w]].
     */
    function buildMap(root, plan, w, h) {
        var map = L.map(root, {
            crs: L.CRS.Simple,
            minZoom: -4,
            maxZoom: 4,
            zoomSnap: 0.25,
            attributionControl: false
        });

        var bounds = [[0, 0], [h, w]];
        L.imageOverlay(plan.fileUrl, bounds).addTo(map);
        map.fitBounds(bounds);
        map.setMaxBounds([[-h * 0.25, -w * 0.25], [h * 1.25, w * 1.25]]);

        // Controle de tela cheia (requisito: uso em tela cheia)
        var Fullscreen = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('a', 'leaflet-bar shopmap-fs-btn');
                btn.href = '#';
                btn.title = 'Tela cheia';
                btn.innerHTML = '\u26F6'; // ⛶
                L.DomEvent.on(btn, 'click', function (ev) {
                    L.DomEvent.stop(ev);
                    var el = map.getContainer();
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    } else if (el.requestFullscreen) {
                        el.requestFullscreen();
                    }
                });
                return btn;
            }
        });
        map.addControl(new Fullscreen());

        // Reajusta o viewport ao entrar/sair da tela cheia
        document.addEventListener('fullscreenchange', function () {
            setTimeout(function () {
                map.invalidateSize();
                if (!document.fullscreenElement) {
                    map.fitBounds(bounds);
                }
            }, 150);
        });

        return map;
    }

    window.ShopMapPlan = {
        mount: function (rootId, dataId) {
            var root = document.getElementById(rootId);
            var plan = readData(dataId);
            if (!root || !plan || !plan.fileUrl) { return null; }

            var w = Number(plan.width) || 0;
            var h = Number(plan.height) || 0;

            if (w > 0 && h > 0) {
                return buildMap(root, plan, w, h);
            }

            // Dimensões desconhecidas (SVG sem width/viewBox): sonda o
            // tamanho natural pela própria imagem antes de montar.
            var probe = new Image();
            probe.onload = function () {
                buildMap(
                    root,
                    plan,
                    probe.naturalWidth || 2000,
                    probe.naturalHeight || 1500
                );
            };
            probe.onerror = function () {
                root.innerHTML = '<div class="p-4 text-danger">Falha ao carregar o arquivo da planta.</div>';
            };
            probe.src = plan.fileUrl;
            return null;
        }
    };
})();
