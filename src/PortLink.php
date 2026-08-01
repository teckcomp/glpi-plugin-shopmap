<?php

namespace GlpiPlugin\Shopmap;

/**
 * Registro opcional da ligação em NetworkPort do core (Bloco 4c).
 *
 * Quando os DOIS shapes de um cabo têm ativo vinculado que suporta
 * portas de rede, o usuário pode registrar a ligação no GLPI:
 * escolhe (ou cria) uma NetworkPort em cada lado, o plugin cria o
 * vínculo NetworkPort_NetworkPort e grava networkports_id_a/b na
 * conexão. Desfazer remove SÓ o vínculo — as portas ficam no ativo.
 *
 * Decisões:
 *  - porta já conectada a outra não pode ser escolhida (o plugin NÃO
 *    desconecta nada em silêncio);
 *  - criação de porta usa o objeto core \NetworkPort (instanciação
 *    NetworkPortEthernet, logical_number = max+1, entidade do ativo);
 *  - direitos: exige o direito 'networking' do core (rightname da
 *    NetworkPort) além do plugin_shopmap UPDATE já checado no AJAX.
 */
class PortLink
{
    /** Fallback quando $CFG_GLPI['networkport_types'] não estiver disponível. */
    public const FALLBACK_PORT_TYPES = ['NetworkEquipment', 'Computer', 'Printer'];

    /** Instanciação padrão das portas criadas pelo plugin. */
    public const INSTANTIATION = 'NetworkPortEthernet';

    /**
     * O itemtype aceita NetworkPort? (lista do core, com fallback)
     */
    public static function supportsPorts(string $itemtype): bool
    {
        if ($itemtype === '') {
            return false;
        }
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $types = $CFG_GLPI['networkport_types'] ?? null;
        if (!is_array($types) || $types === []) {
            $types = self::FALLBACK_PORT_TYPES;
        }
        return in_array($itemtype, $types, true);
    }

    /**
     * Portas de rede de um ativo, com flag de ocupação.
     *
     * @return array<int, array{id:int,name:string,number:int,busy:bool}>
     */
    public static function portsOf(string $itemtype, int $itemsId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $it = $DB->request([
            'FROM'  => 'glpi_networkports',
            'WHERE' => [
                'glpi_networkports.itemtype'   => $itemtype,
                'glpi_networkports.items_id'   => $itemsId,
                'glpi_networkports.is_deleted' => 0,
            ],
            'ORDER' => 'glpi_networkports.logical_number',
        ]);
        foreach ($it as $row) {
            $pid = (int) $row['id'];
            $out[] = [
                'id'     => $pid,
                'name'   => (string) ($row['name'] ?? ''),
                'number' => (int) ($row['logical_number'] ?? 0),
                'busy'   => self::wireOf($pid) !== null,
            ];
        }
        return $out;
    }

    /**
     * Linha de glpi_networkports_networkports em que a porta aparece
     * (qualquer lado), ou null quando a porta está livre.
     *
     * @return array<string,mixed>|null
     */
    public static function wireOf(int $portId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($portId <= 0) {
            return null;
        }
        $it = $DB->request([
            'FROM'  => 'glpi_networkports_networkports',
            'WHERE' => ['OR' => [
                'glpi_networkports_networkports.networkports_id_1' => $portId,
                'glpi_networkports_networkports.networkports_id_2' => $portId,
            ]],
            'LIMIT' => 1,
        ]);
        foreach ($it as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Informações dos dois lados do cabo para o formulário de registro.
     *
     * @param array<string,mixed> $conn linha crua de Connection::get()
     * @return array{
     *   linked:bool, can:bool, reason:string,
     *   a:array{shape:string,asset:string,ports:array<int,array{id:int,name:string,number:int,busy:bool}>},
     *   b:array{shape:string,asset:string,ports:array<int,array{id:int,name:string,number:int,busy:bool}>}
     * }
     */
    public static function info(array $conn): array
    {
        $linked = (int) ($conn['networkports_id_a'] ?? 0) > 0
               && (int) ($conn['networkports_id_b'] ?? 0) > 0;

        $sides  = [];
        $can    = true;
        $reason = '';
        foreach (['a' => (int) $conn['shapes_id_a'], 'b' => (int) $conn['shapes_id_b']] as $side => $shapeId) {
            $shape    = Shape::get($shapeId);
            $itemtype = (string) ($shape['itemtype'] ?? '');
            $itemsId  = (int) ($shape['items_id'] ?? 0);
            [$assetName] = Shape::assetInfo($itemtype, $itemsId);

            $ok = $shape !== null && $assetName !== '' && self::supportsPorts($itemtype);
            if (!$ok && $reason === '') {
                $label = $shape !== null ? (string) $shape['label'] : ('#' . $shapeId);
                $reason = ($assetName === '')
                    ? 'O shape "' . ($label !== '' ? $label : '#' . $shapeId) . '" não tem ativo vinculado.'
                    : 'O ativo "' . $assetName . '" (' . $itemtype . ') não aceita portas de rede.';
            }
            $can = $can && $ok;

            $sides[$side] = [
                'shape' => $shape !== null ? (string) $shape['label'] : '',
                'asset' => $assetName,
                'ports' => $ok ? self::portsOf($itemtype, $itemsId) : [],
            ];
        }

        return [
            'linked' => $linked,
            'can'    => $can,
            'reason' => $reason,
            'a'      => $sides['a'],
            'b'      => $sides['b'],
        ];
    }

    /**
     * Resolve a porta de um lado: valida a existente OU cria uma nova.
     * Devolve [id, ''] em sucesso ou [0, mensagem de erro].
     *
     * @param array<string,mixed> $shape linha crua do shape do lado
     * @return array{0:int,1:string}
     */
    public static function resolvePort(array $shape, int $portsId, string $newName): array
    {
        $itemtype = (string) ($shape['itemtype'] ?? '');
        $itemsId  = (int) ($shape['items_id'] ?? 0);

        if ($itemsId <= 0 || !self::supportsPorts($itemtype)) {
            return [0, 'lado sem ativo com suporte a portas'];
        }

        if ($portsId > 0) {
            // porta existente: tem que ser DESTE ativo e estar livre
            $found = null;
            foreach (self::portsOf($itemtype, $itemsId) as $p) {
                if ($p['id'] === $portsId) {
                    $found = $p;
                    break;
                }
            }
            if ($found === null) {
                return [0, 'porta não pertence ao ativo'];
            }
            if ($found['busy']) {
                return [0, 'a porta "' . $found['name'] . '" já está conectada a outra porta'];
            }
            return [$portsId, ''];
        }

        // criar porta nova
        if (!\Session::haveRight('networking', CREATE)) {
            return [0, 'seu perfil não pode criar portas de rede (direito "networking")'];
        }
        $newName = mb_substr(trim($newName), 0, 255);
        if ($newName === '') {
            return [0, 'informe o nome da nova porta'];
        }
        $id = self::createPort($itemtype, $itemsId, $newName);
        return $id > 0 ? [$id, ''] : [0, 'falha ao criar a porta no GLPI'];
    }

    /**
     * Cria uma NetworkPort no ativo via objeto core (entidade do ativo,
     * logical_number sequencial, instanciação Ethernet).
     */
    public static function createPort(string $itemtype, int $itemsId, string $name): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!class_exists($itemtype)) {
            return 0;
        }
        /** @var \CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            return 0;
        }

        // próximo logical_number do ativo
        $next = 1;
        $it = $DB->request([
            'SELECT' => ['MAX' => 'glpi_networkports.logical_number AS maxnum'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => [
                'glpi_networkports.itemtype' => $itemtype,
                'glpi_networkports.items_id' => $itemsId,
            ],
        ]);
        foreach ($it as $row) {
            $next = ((int) ($row['maxnum'] ?? 0)) + 1;
        }

        $port = new \NetworkPort();
        $id   = $port->add([
            'itemtype'           => $itemtype,
            'items_id'           => $itemsId,
            'entities_id'        => (int) ($item->fields['entities_id'] ?? 0),
            'is_recursive'       => (int) ($item->fields['is_recursive'] ?? 0),
            'name'               => $name,
            'logical_number'     => $next,
            'instantiation_type' => self::INSTANTIATION,
        ]);
        return is_int($id) && $id > 0 ? $id : 0;
    }

    /**
     * Registra a ligação: valida/cria as duas portas, cria o vínculo
     * NetworkPort_NetworkPort e grava networkports_id_a/b na conexão.
     *
     * @param array<string,mixed> $conn linha crua de Connection::get()
     * @return array{ok:bool,error:string}
     */
    public static function link(array $conn, int $portA, string $newA, int $portB, string $newB): array
    {
        if ((int) ($conn['networkports_id_a'] ?? 0) > 0 && (int) ($conn['networkports_id_b'] ?? 0) > 0) {
            return ['ok' => false, 'error' => 'este cabo já está registrado em portas'];
        }

        $shapeA = Shape::get((int) $conn['shapes_id_a']);
        $shapeB = Shape::get((int) $conn['shapes_id_b']);
        if ($shapeA === null || $shapeB === null) {
            return ['ok' => false, 'error' => 'shapes do cabo não encontrados'];
        }

        [$idA, $errA] = self::resolvePort($shapeA, $portA, $newA);
        if ($idA <= 0) {
            return ['ok' => false, 'error' => 'lado A: ' . $errA];
        }
        [$idB, $errB] = self::resolvePort($shapeB, $portB, $newB);
        if ($idB <= 0) {
            return ['ok' => false, 'error' => 'lado B: ' . $errB];
        }
        if ($idA === $idB) {
            return ['ok' => false, 'error' => 'as duas pontas não podem ser a mesma porta'];
        }

        $wire   = new \NetworkPort_NetworkPort();
        $wireId = $wire->add([
            'networkports_id_1' => $idA,
            'networkports_id_2' => $idB,
        ]);
        if (!is_int($wireId) || $wireId <= 0) {
            return ['ok' => false, 'error' => 'falha ao conectar as portas no GLPI'];
        }

        if (!Connection::setPorts((int) $conn['id'], $idA, $idB)) {
            return ['ok' => false, 'error' => 'falha ao gravar o vínculo na conexão'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Desfaz o registro: remove o vínculo NetworkPort_NetworkPort (as
     * portas ficam) e zera networkports_id_a/b na conexão.
     *
     * @param array<string,mixed> $conn linha crua de Connection::get()
     */
    public static function unlink(array $conn): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $portA = (int) ($conn['networkports_id_a'] ?? 0);
        if ($portA > 0) {
            $wire = self::wireOf($portA);
            if ($wire !== null) {
                $DB->delete('glpi_networkports_networkports', ['id' => (int) $wire['id']]);
            }
        }
        return Connection::setPorts((int) $conn['id'], 0, 0);
    }
}
