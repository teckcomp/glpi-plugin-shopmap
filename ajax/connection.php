<?php

/**
 * ShopMap — endpoint AJAX das conexões/cabos (Bloco 4).
 *
 * POST action=create (floorplans_id, shapes_id_a, shapes_id_b, points JSON)
 * POST action=update (id, [cable_type], [cable_label], [length_m],
 *                     [strand_count], [comment], [points])
 * POST action=delete (id)
 *
 * Bloco 4e — item efetivo da ponta (equipamento dentro do rack):
 * POST action=endinfo (id) → lados A/B: contêiner? conteúdo? escolhido?
 * action=update aceita itemtype_a/items_id_a e itemtype_b/items_id_b
 * (validados contra o conteúdo do rack; recusado se o cabo já estiver
 * registrado em portas — desfaça o registro antes de trocar a ponta).
 *
 * Bloco 4c — registro opcional em NetworkPort do core:
 * POST action=portinfo   (id) → lados A/B com portas livres/ocupadas
 * POST action=portlink   (id, ports_id_a|new_name_a, ports_id_b|new_name_b)
 * POST action=portunlink (id)
 * As três exigem também o direito 'networking' do core (UPDATE).
 *
 * CSRF: validado pelo core em POST; respostas devolvem token novo em
 * 'csrf'. Entidade: validada via Floorplan::getById em toda operação.
 */

use GlpiPlugin\Shopmap\Connection;
use GlpiPlugin\Shopmap\EndPoint;
use GlpiPlugin\Shopmap\Floorplan;
use GlpiPlugin\Shopmap\PortLink;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

function smc_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

function smc_conn_checked(int $id): array
{
    $conn = $id > 0 ? Connection::get($id) : null;
    if ($conn === null || Floorplan::getById((int) $conn['plugin_shopmap_floorplans_id']) === null) {
        smc_reply(['ok' => false, 'error' => 'conexão não encontrada'], 404);
    }
    return $conn;
}

function smc_find(int $planId, int $id): ?array
{
    foreach (Connection::forPlan($planId) as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

$action = $_POST['action'] ?? '';

// Bloco 8b: o bit exigido segue a natureza da ação (modelo DGO+):
// desenhar cabo = CREATE; excluir = DELETE; consultar as pontas
// (endinfo é leitura pura, alimenta o popup) = READ; editar cabo e
// registrar/desfazer portas = UPDATE (portas exigem TAMBÉM o direito
// `networking` UPDATE do core, checado dentro de cada case).
$needed = [
    'create'     => CREATE,
    'update'     => UPDATE,
    'delete'     => DELETE,
    'endinfo'    => READ,
    'portinfo'   => UPDATE,
    'portlink'   => UPDATE,
    'portunlink' => UPDATE,
];
Session::checkRight('plugin_shopmap', $needed[$action] ?? UPDATE);

switch ($action) {
    case 'create':
        $planId = (int) ($_POST['floorplans_id'] ?? 0);
        if ($planId <= 0 || Floorplan::getById($planId) === null) {
            smc_reply(['ok' => false, 'error' => 'planta não encontrada'], 404);
        }
        $points = Connection::sanitizePoints($_POST['points'] ?? '');
        if ($points === null) {
            smc_reply(['ok' => false, 'error' => 'traçado inválido'], 400);
        }
        $id = Connection::create(
            $planId,
            (int) ($_POST['shapes_id_a'] ?? 0),
            (int) ($_POST['shapes_id_b'] ?? 0),
            $points
        );
        if ($id <= 0) {
            smc_reply(['ok' => false, 'error' => 'shapes inválidos para conectar'], 400);
        }
        smc_reply(['ok' => true, 'connection' => smc_find($planId, $id)]);
        // sem break — smc_reply encerra

    case 'update':
        $conn   = smc_conn_checked((int) ($_POST['id'] ?? 0));
        $fields = [];
        foreach (['cable_type', 'cable_label', 'comment'] as $f) {
            if (isset($_POST[$f])) {
                $fields[$f] = (string) $_POST[$f];
            }
        }
        if (isset($_POST['length_m'])) {
            // aceita vírgula decimal pt-BR
            $fields['length_m'] = (float) str_replace(',', '.', (string) $_POST['length_m']);
        }
        if (isset($_POST['strand_count'])) {
            $fields['strand_count'] = (int) $_POST['strand_count'];
        }
        if (isset($_POST['color'])) {
            // 4i r2: faltava repassar (validação na Connection::update)
            $fields['color'] = (string) $_POST['color'];
        }
        if (isset($_POST['power_ref_dbm'])) {
            // 4j: dBm com vírgula pt-BR; vazio = limpar (NULL)
            $raw = trim((string) $_POST['power_ref_dbm']);
            $fields['power_ref_dbm'] = ($raw === '') ? null
                : (float) str_replace(',', '.', $raw);
        }
        if (isset($_POST['points'])) {
            $points = Connection::sanitizePoints($_POST['points']);
            if ($points === null) {
                smc_reply(['ok' => false, 'error' => 'traçado inválido'], 400);
            }
            $fields['points'] = $points;
        }
        // Bloco 4e: item efetivo por lado
        foreach (['a', 'b'] as $side) {
            if (!isset($_POST['itemtype_' . $side], $_POST['items_id_' . $side])) {
                continue;
            }
            $effIt  = (string) $_POST['itemtype_' . $side];
            $effIid = (int) $_POST['items_id_' . $side];
            $curIt  = (string) ($conn['itemtype_' . $side] ?? '');
            $curIid = (int) ($conn['items_id_' . $side] ?? 0);
            if ($effIt === $curIt && $effIid === $curIid) {
                continue; // sem mudança
            }
            if ((int) ($conn['networkports_id_a'] ?? 0) > 0
                && (int) ($conn['networkports_id_b'] ?? 0) > 0
            ) {
                smc_reply(['ok' => false, 'error' => 'este cabo está registrado em portas — desfaça o registro antes de trocar o equipamento da ponta'], 400);
            }
            $shape = \GlpiPlugin\Shopmap\Shape::get((int) $conn['shapes_id_' . $side]);
            if (!EndPoint::validateEffective($shape, $effIt, $effIid)) {
                smc_reply(['ok' => false, 'error' => 'lado ' . strtoupper($side) . ': o equipamento escolhido não está dentro deste rack'], 400);
            }
            $fields['itemtype_' . $side] = $effIt;
            $fields['items_id_' . $side] = $effIid;
        }
        $ok = Connection::update((int) $conn['id'], $fields);
        smc_reply([
            'ok'         => $ok,
            'connection' => smc_find((int) $conn['plugin_shopmap_floorplans_id'], (int) $conn['id']),
        ]);
        // sem break

    case 'delete':
        $conn = smc_conn_checked((int) ($_POST['id'] ?? 0));
        smc_reply(['ok' => Connection::delete((int) $conn['id'])]);
        // sem break

    case 'endinfo':
        $conn = smc_conn_checked((int) ($_POST['id'] ?? 0));
        smc_reply(['ok' => true, 'ends' => EndPoint::info($conn)]);
        // sem break

    case 'portinfo':
        $conn = smc_conn_checked((int) ($_POST['id'] ?? 0));
        if (!Session::haveRight('networking', UPDATE)) {
            smc_reply(['ok' => false, 'error' => 'seu perfil não tem o direito "Rede" (networking) do GLPI'], 403);
        }
        smc_reply(['ok' => true, 'info' => PortLink::info($conn)]);
        // sem break

    case 'portlink':
        $conn = smc_conn_checked((int) ($_POST['id'] ?? 0));
        if (!Session::haveRight('networking', UPDATE)) {
            smc_reply(['ok' => false, 'error' => 'seu perfil não tem o direito "Rede" (networking) do GLPI'], 403);
        }
        $res = PortLink::link(
            $conn,
            (int) ($_POST['ports_id_a'] ?? 0),
            (string) ($_POST['new_name_a'] ?? ''),
            (int) ($_POST['ports_id_b'] ?? 0),
            (string) ($_POST['new_name_b'] ?? '')
        );
        if (!$res['ok']) {
            smc_reply(['ok' => false, 'error' => $res['error']], 400);
        }
        smc_reply([
            'ok'         => true,
            'connection' => smc_find((int) $conn['plugin_shopmap_floorplans_id'], (int) $conn['id']),
        ]);
        // sem break

    case 'portunlink':
        $conn = smc_conn_checked((int) ($_POST['id'] ?? 0));
        if (!Session::haveRight('networking', UPDATE)) {
            smc_reply(['ok' => false, 'error' => 'seu perfil não tem o direito "Rede" (networking) do GLPI'], 403);
        }
        $ok = PortLink::unlink($conn);
        smc_reply([
            'ok'         => $ok,
            'connection' => smc_find((int) $conn['plugin_shopmap_floorplans_id'], (int) $conn['id']),
        ]);
        // sem break
}

smc_reply(['ok' => false, 'error' => 'ação desconhecida'], 400);
