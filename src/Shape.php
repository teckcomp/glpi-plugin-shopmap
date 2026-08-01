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
                'itemtype'        => (string) ($row['itemtype'] ?? ''),
                'items_id'        => (int) ($row['items_id'] ?? 0),
                'asset_name'      => $assetName,
                'asset_url'       => $assetUrl,
                'dgo_url'         => self::dgoUrl($itemtype, $itemsId, $locationsId),
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
    public static function create(int $planId, string $shapetype, float $x, float $y): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!in_array($shapetype, Install::SHAPE_TYPES, true) || $shapetype === 'area') {
            // 'area' (polígono) entra com o Leaflet.draw em fase futura
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $ok  = $DB->insert('glpi_plugin_shopmap_shapes', [
            'plugin_shopmap_floorplans_id' => $planId,
            'shapetype'                    => $shapetype,
            'label'                        => '',
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

        if (array_key_exists('itemtype', $fields) && array_key_exists('items_id', $fields)) {
            $itemtype = (string) $fields['itemtype'];
            $itemsId  = (int) $fields['items_id'];
            if ($itemtype === '' || $itemsId <= 0) {
                // desvincular
                $upd['itemtype'] = '';
                $upd['items_id'] = 0;
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
     * Remove o shape e as conexões que chegam/saem dele.
     */
    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete('glpi_plugin_shopmap_connections', ['shapes_id_a' => $id]);
        $DB->delete('glpi_plugin_shopmap_connections', ['shapes_id_b' => $id]);
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
