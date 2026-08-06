/**
 * ShopMap — canvas da planta (Bloco 3: shapes).
 *
 * window.ShopMapPlan.mount(rootId, dataId): estado isolado por
 * instância. Além do overlay da planta (Bloco 2), agora renderiza os
 * shapes (equipamento/rack/caixa), permite criar clicando, arrastar
 * para mover, e editar num popup (rótulo, ativo do GLPI, destino da
 * rota, excluir). Persistência via ajax/shape.php com CSRF rotativo.
 *
 * Bloco 4d — visibilidade de cabos sob demanda: cabos OCULTOS por
 * padrão; clique num shape acende só os cabos dele (os demais somem);
 * clique no vazio limpa o foco; toggle geral no canto (abaixo da tela
 * cheia); modo de desenho força tudo visível e restaura ao sair. O
 * mesmo mecanismo de foco será reutilizado pela rota BFS (Fase 3).
 *
 * Coordenadas: shape.x/y em px do SVG; Leaflet CRS.Simple usa
 * latlng = [y, x].
 */
(function () {
    'use strict';

    // r3: o GLPI 11 carrega o PRÓPRIO Leaflet (leaflet.min.js?v=...) de
    // forma assíncrona e sobrescreve window.L DEPOIS do nosso. Misturar
    // os dois builds quebra tudo que é criado após o load (preview do
    // traçado, cabo novo): "Cannot read properties of undefined ('x')".
    // Fixamos a NOSSA instância e devolvemos window.L ao core.
    var L = (window.L && window.L.noConflict) ? window.L.noConflict() : window.L;

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

    function popupHtml(shape, canUpdate, links) {
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
        if (shape.dgo_ports) {
            h += '<div class="sm-pop-asset">Portas: <strong>' + shape.dgo_ports.documented +
                 '/' + shape.dgo_ports.total + '</strong> documentadas</div>';
        }

        // Painel de ligações (Bloco 4b): todos os cabos deste shape
        links = links || [];
        h += '<div class="sm-pop-links"><div class="sm-pop-lbl">Liga\u00e7\u00f5es (' + links.length + ')</div>';
        if (links.length === 0) {
            h += '<div class="sm-pop-muted-txt">nenhum cabo conectado</div>';
        } else {
            h += '<ul>';
            links.forEach(function (lk) {
                h += '<li><span class="sm-link-dot" style="background:' + lk.color + '"></span>' +
                     '\u2194 ' + esc(lk.other) +
                     ' <span class="sm-pop-muted-txt">' + lk.typeLabel +
                     (lk.label ? ' \u00b7 ' + esc(lk.label) : '') +
                     (lk.length > 0 ? ' \u00b7 ' + lk.length + ' m' : '') +
                     '</span></li>';
            });
            h += '</ul>';
        }
        h += '</div>';

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

    var CABLE_META = {
        fiber_sm: { label: 'Fibra monomodo',  color: '#e0a800' },
        fiber_mm: { label: 'Fibra multimodo', color: '#fd7e14' },
        utp:      { label: 'UTP/met\u00e1lico',    color: '#1a6dd8' },
        other:    { label: 'Outro',            color: '#6c757d' },
        '':       { label: 'Cabo',             color: '#20a06a' }
    };

    function cableMeta(type) { return CABLE_META[type] || CABLE_META['']; }

    /**
     * Visibilidade de um cabo (Bloco 4d). state = { mode, focus, base }:
     * modo de desenho ativo força tudo visível; foco num shape mostra só
     * os cabos dele; sem foco vale o toggle geral (base 'all'|'hidden').
     */
    function cableVisible(state, conn) {
        if (state.mode) { return true; }
        if (state.focus) {
            return conn.shapes_id_a === state.focus || conn.shapes_id_b === state.focus;
        }
        return state.base === 'all';
    }

    /** Cabo registrado em NetworkPort dos dois lados? (Bloco 4c) */
    function connLinked(conn) {
        return (conn.networkports_id_a || 0) > 0 && (conn.networkports_id_b || 0) > 0;
    }

    /** Um lado do formulário de registro em portas (Bloco 4c). */
    function portSideHtml(side, key, defName) {
        var free = (side.ports || []).filter(function (p) { return !p.busy; });
        var h = '<div class="sm-cpop-pside">' +
            '<label class="sm-pop-lbl">Lado ' + key.toUpperCase() + ': ' +
            esc(side.asset || side.shape || '?') + '</label>' +
            '<select class="sm-cpop-psel" data-side="' + key + '">';
        free.forEach(function (p) {
            h += '<option value="' + p.id + '">' +
                 esc(p.name || ('porta ' + p.number)) + ' (n\u00ba ' + p.number + ')</option>';
        });
        h += '<option value="0"' + (free.length === 0 ? ' selected' : '') + '>+ criar nova porta\u2026</option>' +
             '</select>' +
             '<input type="text" class="sm-cpop-pnew' + (free.length === 0 ? '' : ' d-none') + '"' +
             ' data-side="' + key + '" maxlength="255" placeholder="nome da nova porta"' +
             ' value="' + esc(defName) + '">' +
             '</div>';
        return h;
    }

    /** Formulário completo de registro (portinfo -> HTML). Puro p/ teste. */
    function portFormHtml(info, defName) {
        if (!info || !info.can) {
            return '<div class="sm-cpop-pmsg">' +
                esc((info && info.reason) || 'Registro indispon\u00edvel para este cabo.') +
                '</div>';
        }
        return portSideHtml(info.a, 'a', defName) +
               portSideHtml(info.b, 'b', defName) +
               '<button type="button" class="sm-pop-btn sm-cpop-pgo">Confirmar registro</button>';
    }

    /** Um lado do formulário de equipamento no rack (Bloco 4e). */
    function endSideHtml(side, key) {
        var cur = side.current || {};
        var h = '<div class="sm-cpop-eside">' +
            '<label class="sm-pop-lbl">Equipamento no rack \u2014 lado ' + key.toUpperCase() +
            ' (' + esc(side.container || '?') + ')</label>' +
            '<select class="sm-cpop-esel" data-side="' + key + '">' +
            '<option value="">(o pr\u00f3prio ' + esc(side.container || 'rack') + ')</option>';
        (side.items || []).forEach(function (it) {
            var v = it.itemtype + ':' + it.items_id;
            var sel = (cur.itemtype === it.itemtype && cur.items_id === it.items_id) ? ' selected' : '';
            h += '<option value="' + esc(v) + '"' + sel + '>' +
                 esc(it.name) + ' (' + esc(it.type_label) + ')</option>';
        });
        return h + '</select></div>';
    }

    /** Formulário das pontas (endinfo -> HTML). Puro p/ teste. */
    function endsFormHtml(ends) {
        var h = '';
        ['a', 'b'].forEach(function (key) {
            var side = ends && ends[key];
            if (side && side.is_container) { h += endSideHtml(side, key); }
        });
        return h;
    }

    function connPopupHtml(conn, names, canUpdate, ends) {
        var meta = cableMeta(conn.cable_type);
        var h = '<div class="sm-cpop" data-conn-id="' + conn.id + '">';
        h += '<div class="sm-pop-type"><i class="ti ti-route"></i> ' +
             esc(names.a) + ' \u2194 ' + esc(names.b) + '</div>';

        if (!canUpdate) {
            h += '<div>' + meta.label +
                 (conn.cable_label ? ' \u00b7 ' + esc(conn.cable_label) : '') +
                 (conn.length_m > 0 ? ' \u00b7 ' + conn.length_m + ' m' : '') +
                 (conn.strand_count > 0 ? ' \u00b7 ' + conn.strand_count + ' fibras/pares' : '') +
                 (connLinked(conn) ? ' \u00b7 registrado em portas' : '') +
                 '</div>';
            return h + '</div>';
        }

        h += '<label class="sm-pop-lbl">Tipo do cabo</label>' +
             '<select class="sm-cpop-type">';
        ['', 'fiber_sm', 'fiber_mm', 'utp', 'other'].forEach(function (k) {
            h += '<option value="' + k + '"' + (conn.cable_type === k ? ' selected' : '') + '>' +
                 (k === '' ? '(n\u00e3o informado)' : CABLE_META[k].label) + '</option>';
        });
        h += '</select>';

        h += '<label class="sm-pop-lbl">Etiqueta/identifica\u00e7\u00e3o</label>' +
             '<input type="text" class="sm-cpop-label" maxlength="255" value="' + esc(conn.cable_label) + '">';

        h += '<div class="sm-cpop-row">' +
             '<span><label class="sm-pop-lbl">Comprimento (m)</label>' +
             '<input type="text" class="sm-cpop-length" value="' + (conn.length_m > 0 ? conn.length_m : '') + '"></span>' +
             '<span><label class="sm-pop-lbl">Fibras/pares</label>' +
             '<input type="text" class="sm-cpop-strands" value="' + (conn.strand_count > 0 ? conn.strand_count : '') + '"></span>' +
             '</div>';

        // Bloco 4e: equipamento dentro do rack (carregado sob demanda)
        if (ends && (ends.a || ends.b)) {
            h += '<div class="sm-cpop-ends"><span class="sm-cpop-eload">' +
                 'carregando equipamentos do rack\u2026</span></div>';
        }

        // Bloco 4c: registro opcional em NetworkPort do core
        h += '<div class="sm-cpop-ports">';
        if (connLinked(conn)) {
            h += '<div class="sm-cpop-plinked"><i class="ti ti-plug-connected"></i> ' +
                 'Registrado em portas de rede (GLPI)</div>' +
                 '<button type="button" class="sm-pop-btn sm-cpop-punlink">Desfazer registro</button>';
        } else {
            h += '<button type="button" class="sm-pop-btn sm-cpop-plink">' +
                 '<i class="ti ti-plug"></i> Registrar em portas de rede</button>' +
                 '<div class="sm-cpop-pform d-none"></div>';
        }
        h += '</div>';

        h += '<div class="sm-pop-actions">' +
             '<button type="button" class="sm-pop-btn sm-cpop-save">Salvar</button>' +
             '<button type="button" class="sm-pop-btn sm-pop-del sm-cpop-del">Excluir cabo</button>' +
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
        this.conns = {};          // conn id -> dados
        this.lines = {};          // conn id -> L.polyline
        this.pendingType = null;  // tipo aguardando clique no mapa
        this.mode = null;         // null | 'draw' | 'quick'
        this.drawStart = 0;       // shape id inicial do desenho
        this.drawPoints = [];     // vertices intermediarios
        this.tempLine = null;     // preview do traçado
        this.cableBase = 'hidden'; // toggle geral (4d): oculto por padrão
        this.cableFocus = 0;      // shape em foco (0 = nenhum)
        this.cablesBtn = null;    // botão do toggle (controle Leaflet)

        (cfg.shapes || []).forEach(function (s) { self.addMarker(s); });
        (cfg.connections || []).forEach(function (c) { self.addLine(c); });
        this.addCablesControl();

        // clique no mapa: sempre ligado (limpar foco vale também no modo
        // leitura); ações de edição são barradas dentro de onMapClick
        this.map.on('click', function (ev) { self.onMapClick(ev); });

        if (cfg.canUpdate) {
            this.bindToolbar();
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') { self.cancelDraw(); }
            });
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

        marker.on('click', function (ev) {
            if (ev && ev.originalEvent) { ev.originalEvent.stopPropagation(); }
            self.onShapeClick(shape.id);
        });

        if (this.cfg.canUpdate) {
            marker.on('dragend', function () {
                var ll = marker.getLatLng();
                self.post({ action: 'move', id: shape.id, x: ll.lng, y: ll.lat }, function () {});
                var s = self.shapes[shape.id];
                s.x = ll.lng; s.y = ll.lat;
                self.redrawLinesOf(shape.id);
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

    App.prototype.shapePos = function (id) {
        var s = this.shapes[id];
        return s ? [s.y, s.x] : [0, 0];
    };

    App.prototype.connLatLngs = function (conn) {
        var pts = [this.shapePos(conn.shapes_id_a)];
        (conn.points || []).forEach(function (p) { pts.push([p[1], p[0]]); });
        pts.push(this.shapePos(conn.shapes_id_b));
        return pts;
    };

    App.prototype.addLine = function (conn) {
        var self = this;
        this.conns[conn.id] = conn;
        var line = L.polyline(this.connLatLngs(conn), {
            color: cableMeta(conn.cable_type).color,
            weight: 3,
            opacity: 0.9
        }).addTo(this.map);
        // 4d r2: a linha fica SEMPRE no mapa; esconder é opacity 0 +
        // pointer-events none. Remover/re-adicionar paths quebra o
        // renderer SVG do Leaflet (só volta com F5).
        this.styleLineVis(line, this.lineVisible(conn));
        line.on('click', function (ev) {
            if (ev && ev.originalEvent) { ev.originalEvent.stopPropagation(); }
            if (self.mode) { return; } // desenhando: ignora cliques no cabo
            var c = self.conns[conn.id];
            L.popup({ minWidth: 240 })
                .setLatLng(ev.latlng)
                .setContent(connPopupHtml(c, self.connNames(c), self.cfg.canUpdate, self.connEnds(c)))
                .openOn(self.map);
        });
        this.lines[conn.id] = line;
    };

    App.prototype.refreshLine = function (conn) {
        this.conns[conn.id] = conn;
        var line = this.lines[conn.id];
        if (line) {
            line.setLatLngs(this.connLatLngs(conn));
            line.setStyle({ color: cableMeta(conn.cable_type).color });
        }
    };

    App.prototype.redrawLinesOf = function (shapeId) {
        var self = this;
        Object.keys(this.conns).forEach(function (cid) {
            var c = self.conns[cid];
            if (c.shapes_id_a === shapeId || c.shapes_id_b === shapeId) {
                self.lines[cid].setLatLngs(self.connLatLngs(c));
            }
        });
    };

    App.prototype.removeLinesOf = function (shapeId) {
        var self = this;
        Object.keys(this.conns).forEach(function (cid) {
            var c = self.conns[cid];
            if (c.shapes_id_a === shapeId || c.shapes_id_b === shapeId) {
                self.map.removeLayer(self.lines[cid]);
                delete self.lines[cid];
                delete self.conns[cid];
            }
        });
    };

    // ---------- visibilidade de cabos (Bloco 4d) ----------

    App.prototype.visState = function () {
        return { mode: this.mode, focus: this.cableFocus, base: this.cableBase };
    };

    App.prototype.lineVisible = function (conn) {
        return cableVisible(this.visState(), conn);
    };

    /** Aplica o estado atual a todas as linhas (sem tirar do mapa). */
    App.prototype.applyCableVis = function () {
        var self = this;
        Object.keys(this.conns).forEach(function (cid) {
            var line = self.lines[cid];
            if (!line) { return; }
            self.styleLineVis(line, self.lineVisible(self.conns[cid]));
        });
        if (this.cablesBtn) {
            this.cablesBtn.classList.toggle('active', this.cableBase === 'all');
        }
    };

    /** Mostra/esconde uma linha por estilo (renderer permanece vivo). */
    App.prototype.styleLineVis = function (line, show) {
        line.setStyle({ opacity: show ? 0.9 : 0 });
        var el = line.getElement && line.getElement();
        if (el) { el.style.pointerEvents = show ? '' : 'none'; }
    };

    /** Toggle geral "Mostrar cabos", abaixo do botão de tela cheia. */
    App.prototype.addCablesControl = function () {
        var self = this;
        var Ctl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('a', 'leaflet-bar shopmap-cables-btn');
                btn.href = '#';
                btn.title = 'Mostrar/ocultar todos os cabos';
                btn.innerHTML = '<i class="ti ti-route"></i>';
                L.DomEvent.on(btn, 'click', function (ev) {
                    L.DomEvent.stop(ev);
                    self.cableBase = (self.cableBase === 'all') ? 'hidden' : 'all';
                    self.cableFocus = 0;
                    self.applyCableVis();
                });
                self.cablesBtn = btn;
                return btn;
            }
        });
        this.map.addControl(new Ctl());
    };

    /**
     * Shape mais próximo do ponto clicado, em px de TELA (independe do
     * zoom). Devolve o id, ou 0 se nenhum estiver a <= tol px.
     * Correção do Bloco 4-2: em zoom afastado o chip do shape é pequeno
     * e o clique "quase em cima" caía no mapa sem nenhum efeito.
     */
    App.prototype.nearestShape = function (latlng, tol) {
        var p = this.map.latLngToContainerPoint(latlng);
        var bestId = 0;
        var bestD = (tol || 20);
        var self = this;
        Object.keys(this.shapes).forEach(function (sid) {
            var s = self.shapes[sid];
            var q = self.map.latLngToContainerPoint([s.y, s.x]);
            var d = Math.hypot(p.x - q.x, p.y - q.y);
            if (d <= bestD) { bestD = d; bestId = parseInt(sid, 10); }
        });
        return bestId;
    };

    /** Ligações de um shape, prontas para o popup (Bloco 4b). */
    App.prototype.linksOf = function (shapeId) {
        var self = this;
        var out = [];
        Object.keys(this.conns).forEach(function (cid) {
            var c = self.conns[cid];
            if (c.shapes_id_a !== shapeId && c.shapes_id_b !== shapeId) { return; }
            var otherId = (c.shapes_id_a === shapeId) ? c.shapes_id_b : c.shapes_id_a;
            var otherEff = (c.shapes_id_a === shapeId) ? c.eff_name_b : c.eff_name_a;
            out.push({
                other: self.shapeName(otherId) + (otherEff ? ' \u203a ' + otherEff : ''),
                typeLabel: cableMeta(c.cable_type).label,
                color: cableMeta(c.cable_type).color,
                label: c.cable_label,
                length: c.length_m
            });
        });
        return out;
    };

    App.prototype.shapeName = function (id) {
        var s = this.shapes[id];
        return s ? (s.label || s.asset_name || ('#' + id)) : ('#' + id);
    };

    /** Nomes das pontas com o item efetivo do 4e ("Rack \u203a SW"). */
    App.prototype.connNames = function (c) {
        var a = this.shapeName(c.shapes_id_a);
        var b = this.shapeName(c.shapes_id_b);
        if (c.eff_name_a) { a += ' \u203a ' + c.eff_name_a; }
        if (c.eff_name_b) { b += ' \u203a ' + c.eff_name_b; }
        return { a: a, b: b };
    };

    /** Lados cujo shape é contêiner (Rack/Enclosure com ativo). */
    App.prototype.connEnds = function (c) {
        var self = this;
        var isCont = function (sid) {
            var s = self.shapes[sid];
            return !!(s && (s.itemtype === 'Rack' || s.itemtype === 'Enclosure') && s.items_id > 0);
        };
        return { a: isCont(c.shapes_id_a), b: isCont(c.shapes_id_b) };
    };

    App.prototype.setHint = function (text) {
        var el = document.getElementById('shopmap-hint');
        if (el) { el.textContent = text; }
    };

    App.prototype.setMode = function (mode) {
        this.cancelDraw();
        this.mode = mode;
        this.applyCableVis(); // 4d: modo de desenho força cabos visíveis; sair restaura
        var draw = document.getElementById('shopmap-draw-cable');
        var quick = document.getElementById('shopmap-quick-connect');
        if (draw) { draw.classList.toggle('active', mode === 'draw'); }
        if (quick) { quick.classList.toggle('active', mode === 'quick'); }
        this.map.getContainer().style.cursor = mode ? 'crosshair' : '';
        if (mode === 'draw') {
            this.setHint('Desenhar cabo: clique no shape de ORIGEM');
        } else if (mode === 'quick') {
            this.setHint('Conectar: clique no shape de ORIGEM');
        } else {
            this.setHint('Arraste um shape para reposicionar \u00b7 clique nele para editar');
        }
    };

    App.prototype.cancelDraw = function () {
        this.drawStart = 0;
        this.drawPoints = [];
        if (this.tempLine) {
            this.map.removeLayer(this.tempLine);
            this.tempLine = null;
        }
    };

    App.prototype.onShapeClick = function (shapeId) {
        var self = this;

        // modo normal: foca os cabos do shape (4d) e abre o popup
        if (!this.mode) {
            this.cableFocus = shapeId;
            this.applyCableVis();
            var s = this.shapes[shapeId];
            var links = this.linksOf(shapeId);
            if (links.length > 0) {
                this.setHint('Mostrando ' + links.length + ' cabo(s) de ' +
                    this.shapeName(shapeId) + ' \u00b7 clique no vazio da planta para ocultar');
            }
            L.popup({ minWidth: 230 })
                .setLatLng([s.y, s.x])
                .setContent(popupHtml(s, this.cfg.canUpdate, links))
                .openOn(this.map);
            return;
        }

        // desenhando: primeiro clique define a origem
        if (!this.drawStart) {
            this.drawStart = shapeId;
            if (this.mode === 'draw') {
                this.tempLine = L.polyline([this.shapePos(shapeId)], {
                    color: '#20a06a', weight: 3, dashArray: '6 6'
                }).addTo(this.map);
                this.setHint('Origem: ' + this.shapeName(shapeId) +
                    ' \u00b7 clique na planta para tra\u00e7ar o caminho \u00b7 clique no shape de DESTINO para finalizar \u00b7 Esc cancela');
            } else {
                this.setHint('Origem: ' + this.shapeName(shapeId) + ' \u00b7 clique no shape de DESTINO');
            }
            return;
        }

        if (shapeId === this.drawStart) { return; } // mesmo shape: ignora

        // segundo shape: fecha a conexão
        var params = {
            action: 'create',
            floorplans_id: this.cfg.id,
            shapes_id_a: this.drawStart,
            shapes_id_b: shapeId,
            points: JSON.stringify(this.drawPoints)
        };
        this.postConn(params, function (data) {
            self.setMode(null);
            if (data.ok && data.connection) {
                // 4d: ao sair do modo os cabos voltariam a sumir; foca o
                // shape de origem para o cabo novo ficar aceso com o popup
                self.cableFocus = data.connection.shapes_id_a;
                self.applyCableVis();
                self.addLine(data.connection);
                // abre direto a edição dos atributos do cabo recém-criado
                var c = data.connection;
                var mid = self.connLatLngs(c)[Math.floor(self.connLatLngs(c).length / 2)];
                L.popup({ minWidth: 240 })
                    .setLatLng(mid)
                    .setContent(connPopupHtml(c, self.connNames(c), true, self.connEnds(c)))
                    .openOn(self.map);
            }
        });
    };

    App.prototype.postConn = function (params, done) {
        var self = this;
        params._glpi_csrf_token = this.csrf;
        var body = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        fetch(this.cfg.connUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.csrf) { self.csrf = data.csrf; }
            done(data || { ok: false });
        }).catch(function () { done({ ok: false, error: 'falha de rede' }); });
    };

    App.prototype.bindToolbar = function () {
        var self = this;
        var bar = document.getElementById('shopmap-toolbar');
        if (!bar) { return; }
        bar.classList.remove('d-none');
        bar.querySelectorAll('[data-shapetype]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                self.setMode(null);
                var type = btn.getAttribute('data-shapetype');
                self.pendingType = (self.pendingType === type) ? null : type;
                bar.querySelectorAll('[data-shapetype]').forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-shapetype') === self.pendingType);
                });
                self.map.getContainer().style.cursor = self.pendingType ? 'crosshair' : '';
            });
        });

        var bindMode = function (btnId, mode) {
            var btn = document.getElementById(btnId);
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                self.pendingType = null;
                bar.querySelectorAll('[data-shapetype]').forEach(function (b) { b.classList.remove('active'); });
                self.setMode(self.mode === mode ? null : mode);
            });
        };
        bindMode('shopmap-draw-cable', 'draw');
        bindMode('shopmap-quick-connect', 'quick');
    };

    App.prototype.onMapClick = function (ev) {
        var self = this;

        // 4d: clique no vazio (fora de modo) limpa o foco de cabos
        if (!this.mode && this.cableFocus) {
            this.cableFocus = 0;
            this.applyCableVis();
            this.setHint('Arraste um shape para reposicionar \u00b7 clique nele para editar');
        }

        // daqui para baixo é tudo edição
        if (!this.cfg.canUpdate) { return; }

        // modo de conexão SEM origem: clique perto de um shape conta
        // como clique nele; longe de todos, avisa em vez de silenciar
        if (this.mode && !this.drawStart) {
            var near = this.nearestShape(ev.latlng, 20);
            if (near) { this.onShapeClick(near); return; }
            this.setHint('Clique em cima de um shape para definir a ORIGEM (aproxime o zoom se necess\u00e1rio) \u00b7 Esc cancela');
            return;
        }

        // desenhando o traçado: clique perto de outro shape FINALIZA;
        // clique livre na planta vira vértice do caminho
        if (this.mode === 'draw' && this.drawStart) {
            var end = this.nearestShape(ev.latlng, 16);
            if (end && end !== this.drawStart) { this.onShapeClick(end); return; }
            this.drawPoints.push([ev.latlng.lng, ev.latlng.lat]);
            if (this.tempLine) {
                this.tempLine.addLatLng([ev.latlng.lat, ev.latlng.lng]);
            }
            return;
        }

        // conexão rápida com origem definida: só falta o destino
        if (this.mode === 'quick' && this.drawStart) {
            var dest = this.nearestShape(ev.latlng, 20);
            if (dest && dest !== this.drawStart) { this.onShapeClick(dest); return; }
            this.setHint('Clique em cima do shape de DESTINO \u00b7 Esc cancela');
            return;
        }
        if (this.mode) { return; }

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

        var cbox = el ? el.querySelector('.sm-cpop') : null;
        if (cbox) { this.bindConnPopup(cbox); return; }

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
                        self.removeLinesOf(id);
                    }
                    self.map.closePopup();
                });
            });
        }
    };

    App.prototype.bindConnPopup = function (box) {
        var self = this;
        var id = parseInt(box.getAttribute('data-conn-id'), 10);
        var q = function (sel) { return box.querySelector(sel); };

        // ---- Bloco 4e: equipamento dentro do rack ----
        var endsBox = q('.sm-cpop-ends');
        if (endsBox) {
            self.postConn({ action: 'endinfo', id: id }, function (data) {
                if (!data.ok || !data.ends) {
                    endsBox.innerHTML = '<div class="sm-cpop-pmsg">' +
                        esc(data.error || 'falha ao listar o conte\u00fado do rack') + '</div>';
                    return;
                }
                var h = endsFormHtml(data.ends);
                endsBox.innerHTML = h !== '' ? h :
                    '<div class="sm-cpop-pmsg">rack sem equipamentos documentados (Item_Rack)</div>';
            });
        }

        var save = q('.sm-cpop-save');
        if (save) {
            save.addEventListener('click', function () {
                var params = {
                    action: 'update',
                    id: id,
                    cable_type: q('.sm-cpop-type').value,
                    cable_label: q('.sm-cpop-label').value,
                    length_m: q('.sm-cpop-length').value || 0,
                    strand_count: q('.sm-cpop-strands').value || 0
                };
                // Bloco 4e: pontas escolhidas (apenas selects renderizados)
                box.querySelectorAll('.sm-cpop-esel').forEach(function (sel) {
                    var side = sel.getAttribute('data-side');
                    var parts = (sel.value || '').split(':');
                    params['itemtype_' + side] = parts.length === 2 ? parts[0] : '';
                    params['items_id_' + side] = parts.length === 2 ? (parseInt(parts[1], 10) || 0) : 0;
                });
                self.postConn(params, function (data) {
                    if (data.ok && data.connection) { self.refreshLine(data.connection); }
                    self.map.closePopup();
                });
            });
        }

        // ---- Bloco 4c: registro em NetworkPort ----
        var plink = q('.sm-cpop-plink');
        if (plink) {
            plink.addEventListener('click', function () {
                plink.disabled = true;
                self.postConn({ action: 'portinfo', id: id }, function (data) {
                    var form = q('.sm-cpop-pform');
                    if (!form) { return; }
                    form.classList.remove('d-none');
                    if (!data.ok) {
                        form.innerHTML = '<div class="sm-cpop-pmsg">' +
                            esc(data.error || 'falha ao consultar as portas') + '</div>';
                        return;
                    }
                    var conn = self.conns[id] || {};
                    var defName = conn.cable_label || ('ShopMap cabo #' + id);
                    form.innerHTML = portFormHtml(data.info, defName);

                    // "+ criar nova porta…" mostra o campo de nome do lado
                    form.querySelectorAll('.sm-cpop-psel').forEach(function (sel) {
                        sel.addEventListener('change', function () {
                            var inp = form.querySelector(
                                '.sm-cpop-pnew[data-side="' + sel.getAttribute('data-side') + '"]');
                            if (inp) { inp.classList.toggle('d-none', sel.value !== '0'); }
                        });
                    });

                    var go = form.querySelector('.sm-cpop-pgo');
                    if (go) {
                        go.addEventListener('click', function () {
                            var v = function (side) {
                                var sel = form.querySelector('.sm-cpop-psel[data-side="' + side + '"]');
                                var inp = form.querySelector('.sm-cpop-pnew[data-side="' + side + '"]');
                                return {
                                    id: sel ? (parseInt(sel.value, 10) || 0) : 0,
                                    name: inp ? inp.value : ''
                                };
                            };
                            var a = v('a');
                            var b = v('b');
                            go.disabled = true;
                            self.postConn({
                                action: 'portlink',
                                id: id,
                                ports_id_a: a.id,
                                new_name_a: a.name,
                                ports_id_b: b.id,
                                new_name_b: b.name
                            }, function (res) {
                                if (res.ok && res.connection) {
                                    self.refreshLine(res.connection);
                                    self.map.closePopup();
                                    self.setHint('Cabo registrado em portas de rede do GLPI.');
                                } else {
                                    go.disabled = false;
                                    window.alert(res.error || 'falha ao registrar as portas');
                                }
                            });
                        });
                    }
                });
            });
        }

        var punlink = q('.sm-cpop-punlink');
        if (punlink) {
            punlink.addEventListener('click', function () {
                if (!window.confirm('Desfazer o registro em portas? As portas continuam no ativo; s\u00f3 a conex\u00e3o entre elas \u00e9 removida.')) {
                    return;
                }
                self.postConn({ action: 'portunlink', id: id }, function (data) {
                    if (data.ok && data.connection) { self.refreshLine(data.connection); }
                    self.map.closePopup();
                });
            });
        }

        var del = q('.sm-cpop-del');
        if (del) {
            del.addEventListener('click', function () {
                if (!window.confirm('Excluir este cabo?')) { return; }
                self.postConn({ action: 'delete', id: id }, function (data) {
                    if (data.ok && self.lines[id]) {
                        self.map.removeLayer(self.lines[id]);
                        delete self.lines[id];
                        delete self.conns[id];
                    }
                    self.map.closePopup();
                });
            });
        }
    };

    window.ShopMapPlan = {
        _esc: esc,
        _cableMeta: cableMeta,
        _cableVisible: cableVisible,
        _connLinked: connLinked,
        _portFormHtml: portFormHtml,
        _endsFormHtml: endsFormHtml,
        _connPopupHtml: connPopupHtml,
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
