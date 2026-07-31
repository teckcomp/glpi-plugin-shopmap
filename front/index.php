<?php

/**
 * ShopMap — dashboard (tela única de gestão).
 * Bloco 1: esqueleto navegável; upload de planta entra no Bloco 2.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Shopmap\Dashboard;

include('../../../inc/includes.php');

Session::checkRight('plugin_shopmap', READ);

Html::header(
    Dashboard::getMenuName(),
    '', // Html::header ignora o 2o argumento no GLPI 11
    'assets',
    Dashboard::class
);

$plans = Dashboard::getFloorplans();

// Twig do GLPI é strict: TODA variável usada no template está listada
// aqui, agrupada em uma associativa única (padrão do ambiente).
TemplateRenderer::getInstance()->display('@shopmap/dashboard.html.twig', [
    'sm' => [
        'version'    => PLUGIN_SHOPMAP_VERSION,
        'plans'      => $plans,
        'plan_count' => count($plans),
    ],
]);

Html::footer();
