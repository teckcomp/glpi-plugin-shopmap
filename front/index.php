<?php

/**
 * ShopMap — dashboard (tela única de gestão).
 * Bloco 2a: formulário de nova planta + cards com abrir/excluir.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Shopmap\Dashboard;
use GlpiPlugin\Shopmap\ExportLog;
use GlpiPlugin\Shopmap\Url;

include('../../../inc/includes.php');

Session::checkRight('plugin_shopmap', READ);

Html::header(
    Dashboard::getMenuName(),
    '', // Html::header ignora o 2o argumento no GLPI 11
    'assets',
    Dashboard::class
);

$plans = Dashboard::getFloorplans();

// Bloco 7b: histórico de exportações PDF (log simples, decisão do
// usuário: no dashboard, todas as plantas juntas)
$exports = ExportLog::recent(30);

// Twig do GLPI é strict: TODA variável usada no template está listada
// aqui, agrupada em uma associativa única (padrão do ambiente).
TemplateRenderer::getInstance()->display('@shopmap/dashboard.html.twig', [
    'sm' => [
        'version'      => PLUGIN_SHOPMAP_VERSION,
        'plans'        => $plans,
        'plan_count'   => count($plans),
        'can_create'   => Session::haveRight('plugin_shopmap', CREATE),
        'can_delete'   => Session::haveRight('plugin_shopmap', DELETE),
        'csrf_token'   => Session::getNewCSRFToken(),
        'form_url'     => Url::to('front/floorplan.form.php'),
        'plan_url'     => Url::to('front/plan.php'),
        'exports'      => $exports,
        'export_count' => count($exports),
    ],
]);

Html::footer();
