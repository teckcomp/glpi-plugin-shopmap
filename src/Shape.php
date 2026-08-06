<?php

namespace GlpiPlugin\Shopmap;

/**
 * Shapes — elementos posicionados sobre a planta (Bloco 3).
 *
 * Decisões já registradas que este modelo materializa:
 *  - shape DESACOPLADO do ativo: itemtype/items_id são vínculo opcional;
 *    trocar o equipamento é trocar o vínculo, o desenho fica;
 *  - destino da rota é marcação manual por planta (is_route_target),
 *    ÚNICO por planta (marcar um desmarca o anterior);
 *  - geometry JSON {"kind":"point","x":..,"y":..} em px do SVG
 *    (polígono de área fica para a fase do Leaflet.draw).
 */
class Shape
{
    /** Itemtypes vinculáveis a um shape (mesma lista do assetsearch).
     *  PassiveDCEquipment = DGO (é o itemtype que o plugin DGO+ usa). */
    public const LINKABLE = [
        'NetworkEquipment',
        'Computer',
        'Printer',
        'Rack',
        'PassiveDCEquipment',
        'Enclosure',
        'Pdu',
    ];

    /**
     * Shapes de uma planta, com nome e URL do ativo vinculado.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forPlan(int $planId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_shapes',
            'WHERE' => ['glpi_plugin_shopmap_shapes.plugin_shopmap_floorplans_id' => $planId],
            'ORDER' => 'glpi_plugin_shopmap_shapes.id',
        ]);
        foreach ($it as $row) {
            $geom = json_decode((string) ($row['geometry'] ?? ''), true) ?: [];
            $itemtype = (string) ($row['itemtype'] ?? '');
            $itemsId  = (int) ($row['items_id'] ?? 0);
            [$assetName, $assetUrl, $locationsId] = self::assetInfo($itemtype, $itemsId);
            $rows[] = [
                'id'              => (int) $row['id'],
                'shapetype'       => (string) $row['shapetype'],
                'label'           => (string) $row['label'],
                'x'               => (float) ($geom['x'] ?? 0),
                'y'               => (float) ($geom['y'] ?? 0),
                'is_route_target' => (int) ($row['is_route_target'] ?? 0),
                'color'           => in_array((string) ($row['color'] ?? ''), Install::PALETTE, true)
                    ? (string) $row['color'] : Install::DEFAULT_SHAPE_COLOR,
                'itemtype'        => (string) ($row['itemtype'] ?? ''),
                'items_id'        => (int) ($row['items_id'] ?? 0),
                'asset_name'      => $assetName,
                'asset_url'       => $assetUrl,
                'dgo_url'         => self::dgoUrl($itemtype, $itemsId, $locationsId),
                'dgo_ports'       => self::dgoPorts($itemtype, $itemsId),
                // Bloco 6 r4: conteúdo do contêiner (Item_Rack/Enclosure
                // nativos, só leitura — EndPoint::contents já existia p/ 4e).
                // Vai pro popup do rack e entra na busca do plano.
                'rack_items'      => EndPoint::isContainer($itemtype)
                    ? EndPoint::contents($itemtype, $itemsId) : [],
            ];
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_shapes',
            'WHERE' => ['glpi_plugin_shopmap_shapes.id' => $id],
            'LIMIT' => 1,
        ]);
        foreach ($it as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Cria um shape pontual. Devolve o id (0 em falha).
     */
    public static function create(int $planId, string $shapetype, float $x, float $y, string $color = ''): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!in_array($shapetype, Install::SHAPE_TYPES, true) || $shapetype === 'area') {
            // 'area' (polígono) entra com o Leaflet.draw em fase futura
            return 0;
        }
        if (!in_array($color, Install::PALETTE, true)) {
            $color = Install::DEFAULT_SHAPE_COLOR; // 4i: paleta fixa
        }

        $now = date('Y-m-d H:i:s');
        $ok  = $DB->insert('glpi_plugin_shopmap_shapes', [
            'plugin_shopmap_floorplans_id' => $planId,
            'shapetype'                    => $shapetype,
            'label'                        => '',
            'color'                        => $color,
            'geometry'                     => json_encode(['kind' => 'point', 'x' => $x, 'y' => $y]),
            'is_route_target'              => 0,
            'date_creation'                => $now,
            'date_mod'                     => $now,
        ]);
        return $ok ? (int) $DB->insertId() : 0;
    }

    public static function move(int $id, float $x, float $y): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->update('glpi_plugin_shopmap_shapes', [
            'geometry' => json_encode(['kind' => 'point', 'x' => $x, 'y' => $y]),
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Atualiza label / vínculo de ativo / destino de rota.
     * Campos ausentes em $fields não são tocados.
     *
     * @param array{label?:string, itemtype?:string, items_id?:int, is_route_target?:int} $fields
     */
    public static function update(int $id, array $fields): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $shape = self::get($id);
        if ($shape === null) {
            return false;
        }

        $upd = ['date_mod' => date('Y-m-d H:i:s')];

        if (array_key_exists('label', $fields)) {
            $upd['label'] = mb_substr(trim((string) $fields['label']), 0, 255);
        }

        // Bloco 4i: cor do chip (paleta fixa; vago ignora — cor fixa no front)
        if (array_key_exists('color', $fields)) {
            $color = (string) $fields['color'];
            if (in_array($color, Install::PALETTE, true)) {
                $upd['color'] = $color;
            }
        }

        if (array_key_exists('itemtype', $fields) && array_key_exists('items_id', $fields)) {
            $itemtype = (string) $fields['itemtype'];
            $itemsId  = (int) $fields['items_id'];
            if ($itemtype === '' || $itemsId <= 0) {
                // desvincular
                $upd['itemtype'] = '';
                $upd['items_id'] = 0;
            } elseif ((string) $shape['shapetype'] === 'vago') {
                // Bloco 4f: vago não vincula ativo — converta antes
                return false;
            } elseif (in_array($itemtype, self::LINKABLE, true)) {
                $upd['itemtype'] = $itemtype;
                $upd['items_id'] = $itemsId;
            } else {
                return false;
            }
        }

        if (array_key_exists('is_route_target', $fields)) {
            $target = ((int) $fields['is_route_target']) === 1 ? 1 : 0;
            if ($target === 1) {
                // destino único por planta: desmarca os demais antes
                $DB->update('glpi_plugin_shopmap_shapes', ['is_route_target' => 0], [
                    'plugin_shopmap_floorplans_id' => (int) $shape['plugin_shopmap_floorplans_id'],
                ]);
            }
            $upd['is_route_target'] = $target;
        }

        return (bool) $DB->update('glpi_plugin_shopmap_shapes', $upd, ['id' => $id]);
    }

    /**
     * Converte o shape em "Vago" (Bloco 4f — decisão do usuário):
     * o equipamento saiu, a fibra FICA lançada aguardando o próximo.
     *  - preserva posição, rótulo e TODOS os traçados de cabo;
     *  - cabos registrados em portas (4c) têm o registro desfeito
     *    (a porta era do equipamento que saiu; portas ficam no ativo);
     *  - limpa o item efetivo (4e) do lado deste shape em cada cabo;
     *  - desvincula o ativo e desmarca destino de rota (vago não é DC).
     */
    public static function makeVago(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $shape = self::get($id);
        if ($shape === null) {
            return false;
        }
        if ((string) $shape['shapetype'] === 'vago') {
            return true; // idempotente
        }

        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_connections',
            'WHERE' => ['OR' => [
                'glpi_plugin_shopmap_connections.shapes_id_a' => $id,
                'glpi_plugin_shopmap_connections.shapes_id_b' => $id,
            ]],
        ]);
        foreach ($it as $row) {
            $conn = Connection::get((int) $row['id']);
            if ($conn === null) {
                continue;
            }
            // registro em portas: desfaz (o vínculo liga as DUAS portas;
            // com um lado sem equipamento, ele não representa mais nada)
            if ((int) ($conn['networkports_id_a'] ?? 0) > 0
                && (int) ($conn['networkports_id_b'] ?? 0) > 0
            ) {
                PortLink::unlink($conn);
            }
            // item efetivo (4e) do lado deste shape: limpa
            $side = ((int) $conn['shapes_id_a'] === $id) ? 'a' : 'b';
            if ((string) ($conn['itemtype_' . $side] ?? '') !== '') {
                Connection::update((int) $conn['id'], [
                    'itemtype_' . $side => '',
                    'items_id_' . $side => 0,
                ]);
            }
        }

        return (bool) $DB->update('glpi_plugin_shopmap_shapes', [
            'shapetype'       => 'vago',
            'itemtype'        => '',
            'items_id'        => 0,
            'is_route_target' => 0,
            'date_mod'        => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Caminho de volta do vago (Bloco 4f): quando o equipamento novo
     * chega, o ponto vira equipment/rack/passbox/access_point/onu_router
     * de novo (Bloco 6: os 2 últimos) e o ativo é vinculado normalmente.
     * Só converte A PARTIR de vago.
     */
    public static function setType(int $id, string $type): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $shape = self::get($id);
        if ($shape === null
            || (string) $shape['shapetype'] !== 'vago'
            || !in_array($type, ['equipment', 'rack', 'passbox', 'access_point', 'onu_router'], true)
        ) {
            return false;
        }
        return (bool) $DB->update('glpi_plugin_shopmap_shapes', [
            'shapetype' => $type,
            'date_mod'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Remove o shape e as conexões que chegam/saem dele. Passa por
     * Connection::delete para desfazer eventuais registros em
     * NetworkPort do core (Bloco 4c) — nunca delete cru na tabela.
     */
    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_connections',
            'WHERE' => ['OR' => [
                'glpi_plugin_shopmap_connections.shapes_id_a' => $id,
                'glpi_plugin_shopmap_connections.shapes_id_b' => $id,
            ]],
        ]);
        foreach ($it as $row) {
            Connection::delete((int) $row['id']);
        }
        return (bool) $DB->delete('glpi_plugin_shopmap_shapes', ['id' => $id]);
    }

    /**
     * Nome + URL do formulário + localização do ativo vinculado
     * (vazios quando sem vínculo ou ativo apagado). Requisito do
     * produto: clique no ativo -> link direto para o cadastro no GLPI.
     *
     * @return array{0:string,1:string,2:int}
     */
    public static function assetInfo(string $itemtype, int $itemsId): array
    {
        if ($itemtype === '' || $itemsId <= 0
            || !in_array($itemtype, self::LINKABLE, true)
            || !class_exists($itemtype)
        ) {
            return ['', '', 0];
        }

        /** @var \CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            return ['', '', 0];
        }
        return [
            (string) $item->getName(),
            (string) $itemtype::getFormURLWithID($itemsId, true),
            (int) ($item->fields['locations_id'] ?? 0),
        ];
    }

    /**
     * Ocupação de portas de uma DGO, lida das tabelas do DGO+
     * (colunas confirmadas no código: panels.tubes/fibers_per_tube,
     * ports com is_deleted). Devolve null quando não é DGO, o DGO+
     * está inativo ou as tabelas não existem — o front simplesmente
     * não mostra a linha.
     *
     * @return array{documented:int,total:int}|null
     */
    public static function dgoPorts(string $itemtype, int $itemsId): ?array
    {
        if ($itemtype !== 'PassiveDCEquipment' || $itemsId <= 0 || !class_exists('Plugin')) {
            return null;
        }
        $plugin = new \Plugin();
        if (!$plugin->isActivated('dgoplus')) {
            return null;
        }

        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_dgoplus_ports')) {
            return null;
        }

        // Layout da grade (fallback = padrão do DGO+: 4 tubos x 16 fibras)
        $tubes  = 4;
        $fibers = 16;
        if ($DB->tableExists('glpi_plugin_dgoplus_panels')) {
            $it = $DB->request([
                'FROM'  => 'glpi_plugin_dgoplus_panels',
                'WHERE' => [
                    'glpi_plugin_dgoplus_panels.itemtype' => 'PassiveDCEquipment',
                    'glpi_plugin_dgoplus_panels.items_id' => $itemsId,
                ],
                'LIMIT' => 1,
            ]);
            foreach ($it as $row) {
                $tubes  = max(1, (int) ($row['tubes'] ?? 4));
                $fibers = max(1, (int) ($row['fibers_per_tube'] ?? 16));
            }
        }

        $documented = 0;
        $it = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_dgoplus_ports',
            'WHERE' => [
                'glpi_plugin_dgoplus_ports.itemtype'   => 'PassiveDCEquipment',
                'glpi_plugin_dgoplus_ports.items_id'   => $itemsId,
                'glpi_plugin_dgoplus_ports.is_deleted' => 0,
            ],
        ]);
        foreach ($it as $row) {
            $documented = (int) ($row['cpt'] ?? 0);
        }

        return ['documented' => $documented, 'total' => $tubes * $fibers];
    }

    /**
     * Deep-link para o mapa de portas do DGO+ quando o shape está
     * vinculado a uma DGO (PassiveDCEquipment) e o DGO+ está ativo.
     * Formato confirmado no código do DGO+ (MapController):
     * /plugins/dgoplus/front/map.php?location=<locations_id>&dgo=<id>
     */
    public static function dgoUrl(string $itemtype, int $itemsId, int $locationsId): string
    {
        if ($itemtype !== 'PassiveDCEquipment' || $itemsId <= 0 || !class_exists('Plugin')) {
            return '';
        }
        $plugin = new \Plugin();
        if (!$plugin->isActivated('dgoplus')) {
            return '';
        }
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        return ($CFG_GLPI['root_doc'] ?? '')
            . '/plugins/dgoplus/front/map.php?location=' . $locationsId . '&dgo=' . $itemsId;
    }
}
