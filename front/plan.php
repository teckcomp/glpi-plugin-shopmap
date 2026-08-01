<?php

/**
 * ShopMap — canvas da planta (Bloco 2a): Leaflet com CRS simples,
 * zoom e tela cheia. Shapes/conexões entram nos próximos blocos.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Shopmap\Dashboard;
use GlpiPlugin\Shopmap\Connection;
use GlpiPlugin\Shopmap\Floorplan;
use GlpiPlugin\Shopmap\Shape;
use GlpiPlugin\Shopmap\Url;

include('../../../inc/includes.php');

Session::checkRight('plugin_shopmap', READ);

$id   = (int) ($_GET['id'] ?? 0);
$plan = $id > 0 ? Floorplan::getById($id) : null;

if ($plan === null) {
    Html::displayErrorAndDie(__('Planta não encontrada', 'shopmap'));
}

Html::header(
    Dashboard::getMenuName() . ' - ' . $plan['name'],
    '',
    'assets',
    Dashboard::class
);

// Dados para o JS. JSON com flags HEX (lição: '</script>' num nome de
// planta quebraria a página sem isso).
$canUpdate = Session::haveRight('plugin_shopmap', UPDATE);

$planJson = json_encode([
    'id'        => (int) $plan['id'],
    'name'      => (string) $plan['name'],
    'fileUrl'   => Url::to('front/planfile.php') . '?id=' . (int) $plan['id'],
    'width'     => (int) $plan['svg_width'],
    'height'    => (int) $plan['svg_height'],
    'shapes'      => Shape::forPlan((int) $plan['id']),
    'connections' => Connection::forPlan((int) $plan['id']),
    'canUpdate' => $canUpdate,
    'shapeUrl'  => Url::to('ajax/shape.php'),
    'connUrl'   => Url::to('ajax/connection.php'),
    'assetUrl'  => Url::to('ajax/assetsearch.php'),
    'csrf'      => Session::getNewCSRFToken(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// Twig strict: toda variável usada no template listada aqui.
TemplateRenderer::getInstance()->display('@shopmap/plan.html.twig', [
    'sm' => [
        'plan_name'   => (string) $plan['name'],
        'plan_json'   => $planJson,
        'back_url'    => Url::to('front/index.php'),
        'leaflet_css' => Url::asset('js/leaflet/leaflet.css'),
        'leaflet_js'  => Url::asset('js/leaflet/leaflet.js'),
        'plan_js'     => Url::asset('js/shopmap-plan.js'),
    ],
]);

Html::footer();
