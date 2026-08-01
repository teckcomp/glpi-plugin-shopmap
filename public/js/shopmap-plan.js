/**
 * ShopMap — canvas da planta (Bloco 3: shapes).
 *
 * window.ShopMapPlan.mount(rootId, dataId): estado isolado por
 * instância. Além do overlay da planta (Bloco 2), agora renderiza os
 * shapes (equipamento/rack/caixa), permite criar clicando, arrastar
 * para mover, e editar num popup (rótulo, ativo do GLPI, destino da
 * rota, excluir). Persistência via ajax/shape.php com CSRF rotativo.
 *
 * Coordenadas: shape.x/y em px do SVG; Leaflet CRS.Simple usa
 * latlng = [y, x].
 */
(function () {
    'use strict';

    // ---------- helpers puros (testáveis) ----------

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var TYPE_META = {
        equipment: { label: 'Equipamento', cls: 'sm-shape-equipment', icon: 'ti-cpu' },
        rack:      { label: 'Rack',        cls: 'sm-shape-rack',      icon: 'ti-server-2' },
        passbox:   { label: 'Caixa',       cls: 'sm-shape-passbox',   icon: 'ti-square-rounded' }
    };

    // Ícone automático pelo TIPO DO ATIVO vinculado (Tabler, já carregado
    // pelo GLPI). Sem vínculo, vale o ícone do tipo de shape.
    var ITEMTYPE_ICON = {
        NetworkEquipment:   'ti-network',
        Computer:           'ti-device-desktop',
        Printer:            'ti-printer',
        Rack:               'ti-server-2',
        PassiveDCEquipment: 'ti-grid-dots',   // DGO (mesmo ícone do DGO+)
        Enclosure:          'ti-box',
        Pdu:                'ti-plug'
    };

    function iconClass(shape) {
        return ITEMTYPE_ICON[shape.itemtype] ||
            (TYPE_META[shape.shapetype] || TYPE_META.equipment).icon;
    }

    function iconHtml(shape) {
        var meta = TYPE_META[shape.shapetype] || TYPE_META.equipment;
        var star = shape.is_route_target ? '<span class="sm-shape-star">\u2605</span>' : '';
        var text = shape.label || shape.asset_name || '';
        return '<div class="sm-shape ' + meta.cls + '">' +
            '<i class="ti ' + iconClass(shape) + ' sm-shape-glyph"></i>' + star +
            (text ? '<span class="sm-shape-label">' + esc(text) + '</span>' : '') +
            '</div>';
    }

    function popupHtml(shape, canUpdate) {
        var meta = TYPE_META[shape.shapetype] || TYPE_META.equipment;
        var h = '<div class="sm-pop" data-shape-id="' + shape.id + '">';
        h += '<div class="sm-pop-type">' + meta.label + (shape.is_route_target ? ' \u2605 destino da rota' : '') + '</div>';

        if (shape.asset_name) {
            h += '<div class="sm-pop-asset">Ativo: <strong>' + esc(shape.asset_name) + '</strong> ' +
                 '<a href="' + esc(shape.asset_url) + '" target="_blank" title="Abrir cadastro no GLPI">\u2197</a></div>';
        }
        if (shape.dgo_url) {
            h += '<div class="sm-pop-asset"><a href="' + esc(shape.dgo_url) + '" target="_blank">' +
                 '<i class="ti ti-grid-dots"></i> Mapa de portas (DGO+) \u2197</a></div>';
        }

        if (!canUpdate) {
            h += (shape.label ? '<div>' + esc(shape.label) + '</div>' : '');
            return h + '</div>';
        }

        h += '<label class="sm-pop-lbl">R\u00f3tulo</label>' +
             '<input type="text" class="sm-pop-label" maxlength="255" value="' + esc(shape.label) + '">';

        h += '<label class="sm-pop-lbl">Vincular ativo (nome)</label>' +
             '<div class="sm-pop-searchrow">' +
             '<input type="text" class="sm-pop-search" placeholder="m\u00edn. 2 letras">' +
             '<button type="button" class="sm-pop-btn sm-pop-dosearch">Buscar</button>' +
             '</div>' +
             '<ul class="sm-pop-results"></ul>';

        if (shape.asset_name) {
            h += '<button type="button" class="sm-pop-btn sm-pop-unlink">Desvincular ativo</button>';
        }

        h += '<label class="sm-pop-check"><input type="checkbox" class="sm-pop-target"' +
             (shape.is_route_target ? ' checked' : '') + '> Destino da rota (rack/DC)</label>';

        h += '<div class="sm-pop-actions">' +
             '<button type="button" class="sm-pop-btn sm-pop-save">Salvar</button>' +
             '<button type="button" class="sm-pop-btn sm-pop-del">Excluir</button>' +
             '</div>';

        return h + '</div>';
    }

    // ---------- montagem ----------

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

    function buildMap(root, cfg, w, h) {
        var map = L.map(root, {
            crs: L.CRS.Simple,
            minZoom: -10,
            maxZoom: 24,
            zoomSnap: 0.25,
            attributionControl: false
        });

        var bounds = [[0, 0], [h, w]];
        L.imageOverlay(cfg.fileUrl, bounds).addTo(map);
        map.fitBounds(bounds);

        // Faixa de zoom RELATIVA ao enquadramento (escalas lógicas muito
        // diferentes entre PDF ~centenas de px e DXF em mm ~milhões).
        var fitZoom = map.getZoom();
        map.setMinZoom(fitZoom - 2);
        map.setMaxZoom(fitZoom + 7);

        var Fullscreen = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('a', 'leaflet-bar shopmap-fs-btn');
                btn.href = '#';
                btn.title = 'Tela cheia';
                btn.innerHTML = '\u26F6';
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

    function App(root, cfg, w, h) {
        var self = this;
        this.cfg = cfg;
        this.csrf = cfg.csrf;
        this.map = buildMap(root, cfg, w, h);
        this.markers = {};        // shape id -> L.marker
        this.shapes = {};         // shape id -> dados
        this.pendingType = null;  // tipo aguardando clique no mapa

        (cfg.shapes || []).forEach(function (s) { self.addMarker(s); });

        if (cfg.canUpdate) {
            this.bindToolbar();
            this.map.on('click', function (ev) { self.onMapClick(ev); });
        }
        this.map.on('popupopen', function (ev) { self.bindPopup(ev); });
    }

    App.prototype.post = function (params, done) {
        var self = this;
        params._glpi_csrf_token = this.csrf;
        var body = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        fetch(this.cfg.shapeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.csrf) { self.csrf = data.csrf; }
            done(data || { ok: false });
        }).catch(function () { done({ ok: false, error: 'falha de rede' }); });
    };

    App.prototype.addMarker = function (shape) {
        var self = this;
        this.shapes[shape.id] = shape;
        var marker = L.marker([shape.y, shape.x], {
            icon: L.divIcon({ className: 'sm-shape-wrap', html: iconHtml(shape), iconSize: null }),
            draggable: !!this.cfg.canUpdate
        }).addTo(this.map);

        marker.bindPopup(function () {
            return popupHtml(self.shapes[shape.id], self.cfg.canUpdate);
        }, { minWidth: 230 });

        if (this.cfg.canUpdate) {
            marker.on('dragend', function () {
                var ll = marker.getLatLng();
                self.post({ action: 'move', id: shape.id, x: ll.lng, y: ll.lat }, function () {});
            });
        }
        this.markers[shape.id] = marker;
    };

    App.prototype.refreshMarker = function (shape) {
        this.shapes[shape.id] = shape;
        var marker = this.markers[shape.id];
        if (marker) {
            marker.setIcon(L.divIcon({ className: 'sm-shape-wrap', html: iconHtml(shape), iconSize: null }));
        }
    };

    App.prototype.bindToolbar = function () {
        var self = this;
        var bar = document.getElementById('shopmap-toolbar');
        if (!bar) { return; }
        bar.classList.remove('d-none');
        bar.querySelectorAll('[data-shapetype]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var type = btn.getAttribute('data-shapetype');
                self.pendingType = (self.pendingType === type) ? null : type;
                bar.querySelectorAll('[data-shapetype]').forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-shapetype') === self.pendingType);
                });
                self.map.getContainer().style.cursor = self.pendingType ? 'crosshair' : '';
            });
        });
    };

    App.prototype.onMapClick = function (ev) {
        var self = this;
        if (!this.pendingType) { return; }
        var type = this.pendingType;
        this.post({
            action: 'create',
            floorplans_id: this.cfg.id,
            shapetype: type,
            x: ev.latlng.lng,
            y: ev.latlng.lat
        }, function (data) {
            if (data.ok && data.shape) { self.addMarker(data.shape); }
        });
    };

    App.prototype.bindPopup = function (ev) {
        var self = this;
        var el = ev.popup.getElement();
        var box = el ? el.querySelector('.sm-pop') : null;
        if (!box) { return; }
        var id = parseInt(box.getAttribute('data-shape-id'), 10);
        var picked = null; // ativo escolhido na busca

        var q = function (sel) { return box.querySelector(sel); };

        var dosearch = q('.sm-pop-dosearch');
        if (dosearch) {
            dosearch.addEventListener('click', function () {
                var term = (q('.sm-pop-search').value || '').trim();
                var list = q('.sm-pop-results');
                list.innerHTML = '<li class="sm-pop-muted">buscando...</li>';
                fetch(self.cfg.assetUrl + '?q=' + encodeURIComponent(term))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        list.innerHTML = '';
                        var results = (data && data.results) || [];
                        if (!results.length) {
                            list.innerHTML = '<li class="sm-pop-muted">nenhum ativo encontrado</li>';
                            return;
                        }
                        results.forEach(function (r) {
                            var li = document.createElement('li');
                            li.textContent = r.name + ' (' + r.itemtype_label + ')';
                            li.addEventListener('click', function () {
                                picked = r;
                                list.querySelectorAll('li').forEach(function (x) { x.classList.remove('active'); });
                                li.classList.add('active');
                            });
                            list.appendChild(li);
                        });
                    })
                    .catch(function () { list.innerHTML = '<li class="sm-pop-muted">falha na busca</li>'; });
            });
        }

        var save = q('.sm-pop-save');
        if (save) {
            save.addEventListener('click', function () {
                var params = {
                    action: 'update',
                    id: id,
                    label: q('.sm-pop-label').value,
                    is_route_target: q('.sm-pop-target').checked ? 1 : 0
                };
                if (picked) {
                    params.itemtype = picked.itemtype;
                    params.items_id = picked.id;
                }
                self.post(params, function (data) {
                    if (data.ok && data.shape) {
                        self.refreshMarker(data.shape);
                        // destino é único por planta: re-renderiza os demais
                        if (data.shape.is_route_target) {
                            Object.keys(self.shapes).forEach(function (sid) {
                                sid = parseInt(sid, 10);
                                if (sid !== id && self.shapes[sid].is_route_target) {
                                    self.shapes[sid].is_route_target = 0;
                                    self.refreshMarker(self.shapes[sid]);
                                }
                            });
                        }
                    }
                    self.map.closePopup();
                });
            });
        }

        var unlink = q('.sm-pop-unlink');
        if (unlink) {
            unlink.addEventListener('click', function () {
                self.post({ action: 'update', id: id, itemtype: '', items_id: 0 }, function (data) {
                    if (data.ok && data.shape) { self.refreshMarker(data.shape); }
                    self.map.closePopup();
                });
            });
        }

        var del = q('.sm-pop-del');
        if (del) {
            del.addEventListener('click', function () {
                if (!window.confirm('Excluir este shape? Conex\u00f5es dele tamb\u00e9m ser\u00e3o removidas.')) {
                    return;
                }
                self.post({ action: 'delete', id: id }, function (data) {
                    if (data.ok) {
                        self.map.removeLayer(self.markers[id]);
                        delete self.markers[id];
                        delete self.shapes[id];
                    }
                    self.map.closePopup();
                });
            });
        }
    };

    window.ShopMapPlan = {
        _esc: esc,
        _iconClass: iconClass,
        _iconHtml: iconHtml,
        _popupHtml: popupHtml,

        mount: function (rootId, dataId) {
            var root = document.getElementById(rootId);
            var cfg = readData(dataId);
            if (!root || !cfg || !cfg.fileUrl) { return null; }

            var w = Number(cfg.width) || 0;
            var h = Number(cfg.height) || 0;

            if (w > 0 && h > 0) {
                return new App(root, cfg, w, h);
            }

            var probe = new Image();
            probe.onload = function () {
                new App(root, cfg, probe.naturalWidth || 2000, probe.naturalHeight || 1500);
            };
            probe.onerror = function () {
                root.innerHTML = '<div class="p-4 text-danger">Falha ao carregar o arquivo da planta.</div>';
            };
            probe.src = cfg.fileUrl;
            return null;
        }
    };
})();
