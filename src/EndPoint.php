<?php

namespace GlpiPlugin\Shopmap;

/**
 * Ponta avançada do cabo (Bloco 4e): quando o shape é um CONTÊINER
 * (Rack/Enclosure com ativo vinculado), o cabo chega fisicamente nele,
 * mas conecta logicamente num equipamento INTERNO (ex.: o SW no rack).
 *
 * Princípio: "o shape dá a geometria; o equipamento dá a lógica."
 *  - No mapa o cabo continua saindo do rack (correto fisicamente);
 *  - a conexão guarda o item efetivo por lado (itemtype_a/items_id_a,
 *    itemtype_b/items_id_b) — vazio = a ponta é o próprio ativo do shape;
 *  - painel de ligações e registro de portas (PortLink) usam o EFETIVO.
 *
 * O conteúdo dos contêineres é gestão NATIVA do GLPI (Item_Rack /
 * Item_Enclosure) — o ShopMap só lê, nunca grava lá (decisão Bloco 1).
 */
class EndPoint
{
    /** Contêineres suportados => [tabela de conteúdo, coluna FK]. */
    public const CONTAINERS = [
        'Rack'      => ['glpi_items_racks',      'racks_id'],
        'Enclosure' => ['glpi_items_enclosures', 'enclosures_id'],
    ];

    public static function isContainer(string $itemtype): bool
    {
        return isset(self::CONTAINERS[$itemtype]);
    }

    /**
     * Nome de um item qualquer do core (genérico — itens dentro de rack
     * podem ser de tipos fora do Shape::LINKABLE, ex. Monitor/Peripheral).
     */
    public static function itemName(string $itemtype, int $itemsId): string
    {
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return '';
        }
        /** @var \CommonDBTM $item */
        $item = new $itemtype();
        return $item->getFromDB($itemsId) ? (string) $item->getName() : '';
    }

    /** Rótulo do tipo (ex.: "Equipamento de rede"), com fallback. */
    public static function typeLabel(string $itemtype): string
    {
        if ($itemtype !== '' && class_exists($itemtype) && method_exists($itemtype, 'getTypeName')) {
            return (string) $itemtype::getTypeName(1);
        }
        return $itemtype;
    }

    /**
     * Conteúdo de um contêiner (Item_Rack / Item_Enclosure nativos).
     *
     * @return array<int, array{itemtype:string, items_id:int, name:string, type_label:string}>
     */
    public static function contents(string $containerType, int $containerId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!self::isContainer($containerType) || $containerId <= 0) {
            return [];
        }
        [$table, $fk] = self::CONTAINERS[$containerType];
        if (!$DB->tableExists($table)) {
            return [];
        }

        $out = [];
        $it = $DB->request([
            'FROM'  => $table,
            'WHERE' => [$table . '.' . $fk => $containerId],
            'ORDER' => $table . '.id',
        ]);
        foreach ($it as $row) {
            $itemtype = (string) ($row['itemtype'] ?? '');
            $itemsId  = (int) ($row['items_id'] ?? 0);
            $name     = self::itemName($itemtype, $itemsId);
            if ($name === '') {
                continue; // item apagado/classe ausente: não oferecer
            }
            $out[] = [
                'itemtype'   => $itemtype,
                'items_id'   => $itemsId,
                'name'       => $name,
                'type_label' => self::typeLabel($itemtype),
            ];
        }
        return $out;
    }

    /**
     * Item EFETIVO de um lado do cabo: o gravado na conexão (4e) ou,
     * na ausência, o ativo do próprio shape.
     *
     * @param array<string,mixed> $conn linha crua de Connection::get()
     * @param 'a'|'b' $side
     * @return array{0:string,1:int} [itemtype, items_id]
     */
    public static function effective(array $conn, string $side): array
    {
        $it  = (string) ($conn['itemtype_' . $side] ?? '');
        $iid = (int) ($conn['items_id_' . $side] ?? 0);
        if ($it !== '' && $iid > 0) {
            return [$it, $iid];
        }
        $shape = Shape::get((int) ($conn['shapes_id_' . $side] ?? 0));
        return [
            (string) ($shape['itemtype'] ?? ''),
            (int) ($shape['items_id'] ?? 0),
        ];
    }

    /**
     * Valida o item efetivo enviado pelo cliente para um lado:
     *  - vazio (itemtype '' / id 0) SEMPRE vale (limpa: volta ao shape);
     *  - senão, o shape do lado precisa ter um contêiner vinculado e o
     *    item precisa estar DENTRO dele (lista do Item_Rack/Enclosure).
     *
     * @param array<string,mixed>|null $shape linha crua do shape do lado
     */
    public static function validateEffective(?array $shape, string $itemtype, int $itemsId): bool
    {
        if ($itemtype === '' && $itemsId <= 0) {
            return true;
        }
        if ($shape === null) {
            return false;
        }
        $contType = (string) ($shape['itemtype'] ?? '');
        $contId   = (int) ($shape['items_id'] ?? 0);
        if (!self::isContainer($contType) || $contId <= 0) {
            return false;
        }
        foreach (self::contents($contType, $contId) as $item) {
            if ($item['itemtype'] === $itemtype && $item['items_id'] === $itemsId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Informações das duas pontas para o formulário do popup do cabo.
     *
     * @param array<string,mixed> $conn linha crua de Connection::get()
     * @return array{
     *   a:array{is_container:bool,container:string,items:array,current:array{itemtype:string,items_id:int}},
     *   b:array{is_container:bool,container:string,items:array,current:array{itemtype:string,items_id:int}}
     * }
     */
    public static function info(array $conn): array
    {
        $out = [];
        foreach (['a', 'b'] as $side) {
            $shape    = Shape::get((int) ($conn['shapes_id_' . $side] ?? 0));
            $contType = (string) ($shape['itemtype'] ?? '');
            $contId   = (int) ($shape['items_id'] ?? 0);
            $isCont   = self::isContainer($contType) && $contId > 0;
            $out[$side] = [
                'is_container' => $isCont,
                'container'    => $isCont ? self::itemName($contType, $contId) : '',
                'items'        => $isCont ? self::contents($contType, $contId) : [],
                'current'      => [
                    'itemtype' => (string) ($conn['itemtype_' . $side] ?? ''),
                    'items_id' => (int) ($conn['items_id_' . $side] ?? 0),
                ],
            ];
        }
        return ['a' => $out['a'], 'b' => $out['b']];
    }
}
