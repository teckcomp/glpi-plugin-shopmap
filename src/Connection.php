<?php

namespace GlpiPlugin\Shopmap;

/**
 * Conexões — o traçado do cabo entre dois shapes (Bloco 4, Fase 2).
 *
 * Decisão de geometria: `path` guarda APENAS os pontos intermediários
 * da polilinha ({"points":[[x,y],...]}). As pontas são sempre a posição
 * ATUAL dos shapes A e B — mover o shape arrasta o cabo junto, e trocar
 * o ativo vinculado (decisão do Bloco 1) não toca no desenho.
 *
 * Atributos de cabo (benchmark OZmap), todos opcionais:
 *  - cable_type: fiber_sm | fiber_mm | utp | other | ''
 *  - cable_label, length_m, strand_count, comment
 *
 * networkports_id_a/b existem no schema desde o Bloco 1 e serão
 * preenchidos no bloco do painel de ligações (registro em NetworkPort).
 */
class Connection
{
    /** Tipos de cabo aceitos (chave => rótulo PT). */
    public const CABLE_TYPES = [
        'fiber_sm' => 'Fibra monomodo',
        'fiber_mm' => 'Fibra multimodo',
        'utp'      => 'UTP/metálico',
        'other'    => 'Outro',
    ];

    /** Máximo de vértices intermediários por traçado. */
    public const MAX_POINTS = 500;

    /**
     * Conexões de uma planta.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forPlan(int $planId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_connections',
            'WHERE' => ['glpi_plugin_shopmap_connections.plugin_shopmap_floorplans_id' => $planId],
            'ORDER' => 'glpi_plugin_shopmap_connections.id',
        ]);
        foreach ($it as $row) {
            $path = json_decode((string) ($row['path'] ?? ''), true) ?: [];
            $rows[] = [
                'id'           => (int) $row['id'],
                'shapes_id_a'  => (int) ($row['shapes_id_a'] ?? 0),
                'shapes_id_b'  => (int) ($row['shapes_id_b'] ?? 0),
                'points'       => is_array($path['points'] ?? null) ? $path['points'] : [],
                'cable_type'   => (string) ($row['cable_type'] ?? ''),
                'cable_label'  => (string) ($row['cable_label'] ?? ''),
                'length_m'     => (float) ($row['length_m'] ?? 0),
                'strand_count' => (int) ($row['strand_count'] ?? 0),
                'color'        => (string) ($row['color'] ?? ''),
                'comment'      => (string) ($row['comment'] ?? ''),
                // Bloco 4c: o front usa só a flag "registrado ou não"
                'networkports_id_a' => (int) ($row['networkports_id_a'] ?? 0),
                'networkports_id_b' => (int) ($row['networkports_id_b'] ?? 0),
                // Bloco 4e: item efetivo da ponta (equipamento dentro do rack)
                'itemtype_a'  => (string) ($row['itemtype_a'] ?? ''),
                'items_id_a'  => (int) ($row['items_id_a'] ?? 0),
                'itemtype_b'  => (string) ($row['itemtype_b'] ?? ''),
                'items_id_b'  => (int) ($row['items_id_b'] ?? 0),
                'eff_name_a'  => EndPoint::itemName((string) ($row['itemtype_a'] ?? ''), (int) ($row['items_id_a'] ?? 0)),
                'eff_name_b'  => EndPoint::itemName((string) ($row['itemtype_b'] ?? ''), (int) ($row['items_id_b'] ?? 0)),
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
            'FROM'  => 'glpi_plugin_shopmap_connections',
            'WHERE' => ['glpi_plugin_shopmap_connections.id' => $id],
            'LIMIT' => 1,
        ]);
        foreach ($it as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Normaliza/valida os pontos intermediários vindos do cliente.
     * Devolve null quando o formato é inválido.
     *
     * @return array<int, array{0:float,1:float}>|null
     */
    public static function sanitizePoints(mixed $raw): ?array
    {
        if ($raw === '' || $raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || count($raw) > self::MAX_POINTS) {
            return null;
        }
        $out = [];
        foreach ($raw as $p) {
            if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                return null;
            }
            $out[] = [(float) $p[0], (float) $p[1]];
        }
        return $out;
    }

    /**
     * Cria a conexão entre dois shapes DA MESMA planta.
     *
     * @param array<int, array{0:float,1:float}> $points intermediários
     */
    public static function create(int $planId, int $shapeA, int $shapeB, array $points): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($shapeA <= 0 || $shapeB <= 0 || $shapeA === $shapeB) {
            return 0;
        }
        $a = Shape::get($shapeA);
        $b = Shape::get($shapeB);
        if ($a === null || $b === null
            || (int) $a['plugin_shopmap_floorplans_id'] !== $planId
            || (int) $b['plugin_shopmap_floorplans_id'] !== $planId
        ) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $ok  = $DB->insert('glpi_plugin_shopmap_connections', [
            'plugin_shopmap_floorplans_id' => $planId,
            'shapes_id_a'                  => $shapeA,
            'shapes_id_b'                  => $shapeB,
            'path'                         => json_encode(['points' => $points]),
            'cable_type'                   => '',
            'cable_label'                  => '',
            'length_m'                     => 0,
            'strand_count'                 => 0,
            'date_creation'                => $now,
            'date_mod'                     => $now,
        ]);
        return $ok ? (int) $DB->insertId() : 0;
    }

    /**
     * Atualiza atributos do cabo (e opcionalmente o traçado).
     * Campos ausentes não são tocados.
     *
     * @param array{cable_type?:string, cable_label?:string, length_m?:float,
     *              strand_count?:int, comment?:string,
     *              points?:array<int, array{0:float,1:float}>} $fields
     */
    public static function update(int $id, array $fields): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::get($id) === null) {
            return false;
        }

        $upd = ['date_mod' => date('Y-m-d H:i:s')];

        if (array_key_exists('cable_type', $fields)) {
            $type = (string) $fields['cable_type'];
            if ($type !== '' && !isset(self::CABLE_TYPES[$type])) {
                return false;
            }
            $upd['cable_type'] = $type;
        }
        if (array_key_exists('cable_label', $fields)) {
            $upd['cable_label'] = mb_substr(trim((string) $fields['cable_label']), 0, 255);
        }
        if (array_key_exists('length_m', $fields)) {
            $upd['length_m'] = max(0, (float) $fields['length_m']);
        }
        if (array_key_exists('strand_count', $fields)) {
            $upd['strand_count'] = max(0, (int) $fields['strand_count']);
        }
        if (array_key_exists('comment', $fields)) {
            $upd['comment'] = mb_substr(trim((string) $fields['comment']), 0, 2000);
        }
        if (array_key_exists('points', $fields)) {
            $upd['path'] = json_encode(['points' => $fields['points']]);
        }

        // Bloco 4i: cor do cabo — '' (cru/verde) ou uma da paleta
        if (array_key_exists('color', $fields)) {
            $color = (string) $fields['color'];
            if ($color === '' || in_array($color, Install::PALETTE, true)) {
                $upd['color'] = $color;
            }
        }

        // Bloco 4e: item efetivo por lado (validação em EndPoint, no AJAX)
        foreach (['a', 'b'] as $side) {
            if (array_key_exists('itemtype_' . $side, $fields)
                && array_key_exists('items_id_' . $side, $fields)
            ) {
                $it  = (string) $fields['itemtype_' . $side];
                $iid = (int) $fields['items_id_' . $side];
                $upd['itemtype_' . $side] = ($it !== '' && $iid > 0) ? $it : '';
                $upd['items_id_' . $side] = ($it !== '' && $iid > 0) ? $iid : 0;
            }
        }

        return (bool) $DB->update('glpi_plugin_shopmap_connections', $upd, ['id' => $id]);
    }

    /**
     * Grava/zera o par de portas do core na conexão (Bloco 4c).
     * Uso interno do PortLink — a validação das portas é lá.
     */
    public static function setPorts(int $id, int $portA, int $portB): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->update('glpi_plugin_shopmap_connections', [
            'networkports_id_a' => max(0, $portA),
            'networkports_id_b' => max(0, $portB),
            'date_mod'          => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Excluir o cabo desfaz também o registro em NetworkPort (o vínculo
     * NetworkPort_NetworkPort — as portas ficam no ativo).
     */
    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $conn = self::get($id);
        if ($conn !== null && (int) ($conn['networkports_id_a'] ?? 0) > 0) {
            PortLink::unlink($conn);
        }
        return (bool) $DB->delete('glpi_plugin_shopmap_connections', ['id' => $id]);
    }
}
