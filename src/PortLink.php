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

    /** Origem da porta por lado (Bloco 4g): '' = NetworkPort core. */
    public const PT_CORE = '';
    public const PT_DGO  = 'dgoplus';

    /**
     * Tipo do lado (Bloco 4g): 'core' (NetworkPort), 'dgoplus' (porta do
     * DGO+ como REFERÊNCIA — a documentação real de emenda/fusão é no
     * DGO+) ou 'none'.
     */
    public static function sideKind(string $itemtype): string
    {
        if ($itemtype === 'PassiveDCEquipment') {
            if (class_exists('Plugin') && (new \Plugin())->isActivated('dgoplus')) {
                /** @var \DBmysql $DB */
                global $DB;
                if ($DB->tableExists('glpi_plugin_dgoplus_ports')) {
                    return 'dgoplus';
                }
            }
            return 'none';
        }
        return self::supportsPorts($itemtype) ? 'core' : 'none';
    }

    /**
     * Ids de porta já referenciados por OUTROS cabos do ShopMap, por
     * origem (4g): um cabo em par core+DGO não cria wire no core, então
     * o wireOf sozinho não basta para saber que a porta está em uso.
     *
     * @return array<int,bool> id => true
     */
    public static function usedByShopmap(string $porttype, int $exceptConnId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $used = [];
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_shopmap_connections',
        ]);
        foreach ($it as $row) {
            if ((int) $row['id'] === $exceptConnId) {
                continue;
            }
            foreach (['a', 'b'] as $side) {
                $pid = (int) ($row['networkports_id_' . $side] ?? 0);
                if ($pid > 0 && (string) ($row['porttype_' . $side] ?? '') === $porttype) {
                    $used[$pid] = true;
                }
            }
        }
        return $used;
    }

    /**
     * Portas do DGO+ de uma DGO (Bloco 4g), como referência:
     * "Tubo X · Fibra Y" (+ code/name quando documentados).
     * busy = já referenciada por outro cabo do ShopMap.
     *
     * @return array<int, array{id:int,name:string,number:int,busy:bool}>
     */
    public static function dgoPortsOf(int $dgoId, int $exceptConnId = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $used = self::usedByShopmap(self::PT_DGO, $exceptConnId);
        $out  = [];
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_dgoplus_ports',
            'WHERE' => [
                'glpi_plugin_dgoplus_ports.itemtype'   => 'PassiveDCEquipment',
                'glpi_plugin_dgoplus_ports.items_id'   => $dgoId,
                'glpi_plugin_dgoplus_ports.is_deleted' => 0,
            ],
            'ORDER' => ['glpi_plugin_dgoplus_ports.tube_num', 'glpi_plugin_dgoplus_ports.fiber_num'],
        ]);
        foreach ($it as $row) {
            $pid  = (int) $row['id'];
            $name = 'Tubo ' . (int) ($row['tube_num'] ?? 0) . ' - Fibra ' . (int) ($row['fiber_num'] ?? 0);
            $extra = trim((string) ($row['code'] ?? '') . ' ' . (string) ($row['name'] ?? ''));
            if ($extra !== '') {
                $name .= ' (' . $extra . ')';
            }
            $out[] = [
                'id'     => $pid,
                'name'   => $name,
                'number' => (int) ($row['fiber_num'] ?? 0),
                'busy'   => isset($used[$pid]),
            ];
        }
        return $out;
    }

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
    public static function portsOf(string $itemtype, int $itemsId, int $exceptConnId = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $refUsed = self::usedByShopmap(self::PT_CORE, $exceptConnId); // 4g
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
                'busy'   => self::wireOf($pid) !== null || isset($refUsed[$pid]),
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
        foreach (['a', 'b'] as $side) {
            $shapeId = (int) $conn['shapes_id_' . $side];
            $shape   = Shape::get($shapeId);
            // Bloco 4e: a ponta lógica é o item EFETIVO (equipamento
            // dentro do rack quando escolhido; senão o ativo do shape)
            [$itemtype, $itemsId] = EndPoint::effective($conn, $side);
            $assetName = EndPoint::itemName($itemtype, $itemsId);

            $kind = self::sideKind($itemtype); // 4g: core | dgoplus | none
            $ok = $shape !== null && $assetName !== '' && $kind !== 'none';
            if (!$ok && $reason === '') {
                $label = $shape !== null ? (string) $shape['label'] : ('#' . $shapeId);
                if ($assetName === '') {
                    $reason = 'O shape "' . ($label !== '' ? $label : '#' . $shapeId) . '" não tem ativo vinculado.';
                } elseif (EndPoint::isContainer($itemtype)) {
                    $reason = '"' . $assetName . '" é um ' . strtolower(EndPoint::typeLabel($itemtype))
                        . ' — escolha o equipamento interno (campo "Equipamento no rack" acima) antes de registrar portas.';
                } elseif ($itemtype === 'PassiveDCEquipment') {
                    $reason = 'A DGO "' . $assetName . '" precisa do plugin DGO+ ativo para referenciar portas.';
                } else {
                    $reason = 'O ativo "' . $assetName . '" (' . $itemtype . ') não aceita portas de rede.';
                }
            }
            $can = $can && $ok;

            $ports = [];
            if ($ok) {
                $ports = ($kind === 'dgoplus')
                    ? self::dgoPortsOf($itemsId, (int) ($conn['id'] ?? 0))
                    : self::portsOf($itemtype, $itemsId);
            }
            if ($ok && $kind === 'dgoplus' && $ports === [] && $reason === '') {
                $can = false;
                $reason = 'A DGO "' . $assetName . '" ainda não tem portas documentadas — documente no DGO+ ("Mapa de portas") e volte aqui.';
            }

            $sides[$side] = [
                'kind'  => $kind,
                'shape' => $shape !== null ? (string) $shape['label'] : '',
                'asset' => $assetName,
                'ports' => $ports,
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
     * Bloco 4e: recebe o item EFETIVO da ponta (não mais o shape).
     *
     * @return array{0:int,1:string}
     */
    /**
     * Resolve a REFERÊNCIA de porta do DGO+ (Bloco 4g): a porta tem que
     * existir naquela DGO e não estar usada por outro cabo. Não há
     * criação — portas do DGO+ nascem no DGO+.
     *
     * @return array{0:int,1:string}
     */
    public static function resolveDgoPort(int $dgoId, int $portsId, int $exceptConnId): array
    {
        if ($portsId <= 0) {
            return [0, 'escolha a porta do DGO+ (referência)'];
        }
        foreach (self::dgoPortsOf($dgoId, $exceptConnId) as $p) {
            if ($p['id'] === $portsId) {
                return $p['busy']
                    ? [0, 'a porta "' . $p['name'] . '" já está referenciada por outro cabo']
                    : [$portsId, ''];
            }
        }
        return [0, 'porta não pertence a esta DGO'];
    }

    public static function resolvePort(string $itemtype, int $itemsId, int $portsId, string $newName): array
    {
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

        if (Shape::get((int) $conn['shapes_id_a']) === null || Shape::get((int) $conn['shapes_id_b']) === null) {
            return ['ok' => false, 'error' => 'shapes do cabo não encontrados'];
        }

        // Bloco 4e: as portas são do item EFETIVO de cada ponta
        [$itA, $iidA] = EndPoint::effective($conn, 'a');
        [$itB, $iidB] = EndPoint::effective($conn, 'b');
        $connId = (int) $conn['id'];

        // Bloco 4g: cada lado resolve pelo seu tipo (core cria/valida
        // NetworkPort; DGO só REFERENCIA porta existente do DGO+)
        $kindA = self::sideKind($itA);
        $kindB = self::sideKind($itB);

        [$idA, $errA] = ($kindA === 'dgoplus')
            ? self::resolveDgoPort($iidA, $portA, $connId)
            : self::resolvePort($itA, $iidA, $portA, $newA);
        if ($idA <= 0) {
            return ['ok' => false, 'error' => 'lado A: ' . $errA];
        }
        [$idB, $errB] = ($kindB === 'dgoplus')
            ? self::resolveDgoPort($iidB, $portB, $connId)
            : self::resolvePort($itB, $iidB, $portB, $newB);
        if ($idB <= 0) {
            return ['ok' => false, 'error' => 'lado B: ' . $errB];
        }
        if ($kindA === $kindB && $idA === $idB) {
            return ['ok' => false, 'error' => 'as duas pontas não podem ser a mesma porta'];
        }

        // Wire NetworkPort_NetworkPort só existe no mundo core — par com
        // DGO fica documentado na conexão (referência), sem wire.
        if ($kindA === 'core' && $kindB === 'core') {
            $wire   = new \NetworkPort_NetworkPort();
            $wireId = $wire->add([
                'networkports_id_1' => $idA,
                'networkports_id_2' => $idB,
            ]);
            if (!is_int($wireId) || $wireId <= 0) {
                return ['ok' => false, 'error' => 'falha ao conectar as portas no GLPI'];
            }
        }

        $ptA = ($kindA === 'dgoplus') ? self::PT_DGO : self::PT_CORE;
        $ptB = ($kindB === 'dgoplus') ? self::PT_DGO : self::PT_CORE;
        if (!Connection::setPorts($connId, $idA, $idB, $ptA, $ptB)) {
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
        // 4g: wire só existe quando o par é core+core (porttypes vazios)
        if ($portA > 0
            && (string) ($conn['porttype_a'] ?? '') === self::PT_CORE
            && (string) ($conn['porttype_b'] ?? '') === self::PT_CORE
        ) {
            $wire = self::wireOf($portA);
            if ($wire !== null) {
                $DB->delete('glpi_networkports_networkports', ['id' => (int) $wire['id']]);
            }
        }
        return Connection::setPorts((int) $conn['id'], 0, 0, self::PT_CORE, self::PT_CORE);
    }
}
