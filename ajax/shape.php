<?php

/**
 * ShopMap — endpoint AJAX dos shapes (Bloco 3).
 *
 * GET  action=list&floorplans_id=N          -> {shapes: [...]}
 * POST action=create (floorplans_id, shapetype, x, y)
 * POST action=move   (id, x, y)
 * POST action=update (id, [label], [itemtype+items_id], [is_route_target])
 * POST action=delete (id)
 *
 * CSRF: validado automaticamente pelo core em POST; cada resposta de
 * POST devolve token novo em 'csrf' (uso único — o JS rotaciona).
 * Entidade: toda operação valida a planta via Floorplan::getById, que
 * já aplica a restrição de entidade da sessão.
 */

use GlpiPlugin\Shopmap\Floorplan;
use GlpiPlugin\Shopmap\Connection;
use GlpiPlugin\Shopmap\Shape;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

function sm_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

/**
 * Carrega o shape e valida acesso à planta dele (entidade da sessão).
 */
function sm_shape_checked(int $id): array
{
    $shape = $id > 0 ? Shape::get($id) : null;
    if ($shape === null || Floorplan::getById((int) $shape['plugin_shopmap_floorplans_id']) === null) {
        sm_reply(['ok' => false, 'error' => 'shape não encontrado'], 404);
    }
    return $shape;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'list');

if ($action === 'list') {
    Session::checkRight('plugin_shopmap', READ);
    $planId = (int) ($_GET['floorplans_id'] ?? 0);
    if ($planId <= 0 || Floorplan::getById($planId) === null) {
        sm_reply(['ok' => false, 'error' => 'planta não encontrada'], 404);
    }
    sm_reply(['ok' => true, 'shapes' => Shape::forPlan($planId)]);
}

// Daqui para baixo, tudo é mutação
Session::checkRight('plugin_shopmap', UPDATE);

switch ($action) {
    case 'create':
        $planId = (int) ($_POST['floorplans_id'] ?? 0);
        if ($planId <= 0 || Floorplan::getById($planId) === null) {
            sm_reply(['ok' => false, 'error' => 'planta não encontrada'], 404);
        }
        $id = Shape::create(
            $planId,
            (string) ($_POST['shapetype'] ?? ''),
            (float) ($_POST['x'] ?? 0),
            (float) ($_POST['y'] ?? 0),
            (string) ($_POST['color'] ?? '')
        );
        if ($id <= 0) {
            sm_reply(['ok' => false, 'error' => 'tipo de shape inválido'], 400);
        }
        $all = Shape::forPlan($planId);
        foreach ($all as $s) {
            if ($s['id'] === $id) {
                sm_reply(['ok' => true, 'shape' => $s]);
            }
        }
        sm_reply(['ok' => false, 'error' => 'falha ao criar'], 500);
        // no break — sm_reply encerra

    case 'move':
        $shape = sm_shape_checked((int) ($_POST['id'] ?? 0));
        $ok = Shape::move((int) $shape['id'], (float) ($_POST['x'] ?? 0), (float) ($_POST['y'] ?? 0));
        sm_reply(['ok' => $ok]);
        // no break

    case 'update':
        $shape  = sm_shape_checked((int) ($_POST['id'] ?? 0));
        $fields = [];
        if (isset($_POST['label'])) {
            $fields['label'] = (string) $_POST['label'];
        }
        if (isset($_POST['itemtype'], $_POST['items_id'])) {
            $fields['itemtype'] = (string) $_POST['itemtype'];
            $fields['items_id'] = (int) $_POST['items_id'];
        }
        if (isset($_POST['is_route_target'])) {
            $fields['is_route_target'] = (int) $_POST['is_route_target'];
        }
        if (isset($_POST['color'])) {
            $fields['color'] = (string) $_POST['color'];
        }
        $ok = Shape::update((int) $shape['id'], $fields);
        // devolve o shape atualizado (com nome/URL do ativo resolvidos)
        $updated = null;
        foreach (Shape::forPlan((int) $shape['plugin_shopmap_floorplans_id']) as $s) {
            if ($s['id'] === (int) $shape['id']) {
                $updated = $s;
            }
        }
        sm_reply(['ok' => $ok, 'shape' => $updated]);
        // no break

    case 'delete':
        $shape = sm_shape_checked((int) ($_POST['id'] ?? 0));
        sm_reply(['ok' => Shape::delete((int) $shape['id'])]);
        // no break

    // Bloco 4i: legenda da planta (JSON {"#RRGGBB":"texto"})
    case 'legend':
        $planId = (int) ($_POST['floorplans_id'] ?? 0);
        if ($planId <= 0 || Floorplan::getById($planId) === null) {
            sm_reply(['ok' => false, 'error' => 'planta não encontrada'], 404);
        }
        $legend = json_decode((string) ($_POST['legend'] ?? ''), true);
        if (!is_array($legend)) {
            sm_reply(['ok' => false, 'error' => 'legenda inválida'], 400);
        }
        sm_reply(['ok' => true, 'legend' => Floorplan::setLegend($planId, $legend)]);
        // no break

    // Bloco 4f: converter em Vago (preserva cabos; desfaz registro de
    // portas e item efetivo do lado que saiu). Devolve o shape novo e
    // TODAS as conexões da planta (flags/nomes mudaram).
    case 'makevago':
        $shape  = sm_shape_checked((int) ($_POST['id'] ?? 0));
        $planId = (int) $shape['plugin_shopmap_floorplans_id'];
        $ok = Shape::makeVago((int) $shape['id']);
        $updated = null;
        foreach (Shape::forPlan($planId) as $s) {
            if ($s['id'] === (int) $shape['id']) {
                $updated = $s;
            }
        }
        sm_reply([
            'ok'          => $ok,
            'shape'       => $updated,
            'connections' => Connection::forPlan($planId),
        ]);
        // no break

    // Bloco 4f: caminho de volta (vago -> equipment/rack/passbox);
    // o vínculo do ativo novo é feito em seguida pelo popup normal.
    case 'settype':
        $shape = sm_shape_checked((int) ($_POST['id'] ?? 0));
        $ok = Shape::setType((int) $shape['id'], (string) ($_POST['shapetype'] ?? ''));
        if (!$ok) {
            sm_reply(['ok' => false, 'error' => 'conversão inválida (só a partir de Vago)'], 400);
        }
        $updated = null;
        foreach (Shape::forPlan((int) $shape['plugin_shopmap_floorplans_id']) as $s) {
            if ($s['id'] === (int) $shape['id']) {
                $updated = $s;
            }
        }
        sm_reply(['ok' => true, 'shape' => $updated]);
        // no break
}

sm_reply(['ok' => false, 'error' => 'ação desconhecida'], 400);
