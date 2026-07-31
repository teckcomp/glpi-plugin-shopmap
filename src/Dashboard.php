<?php

namespace GlpiPlugin\Shopmap;

use CommonGLPI;

/**
 * Dashboard do ShopMap — tela única de gestão (requisito 1 do produto):
 * busca de plantas e projetos, cards por shopping/piso.
 *
 * No Bloco 1 a tela é o esqueleto: título, contagem de plantas e aviso
 * de "nenhuma planta". Upload + conversão SVG entram no Bloco 2.
 */
class Dashboard extends CommonGLPI
{
    public static $rightname = 'plugin_shopmap';

    public static function getTypeName($nb = 0)
    {
        return __('ShopMap', 'shopmap');
    }

    public static function getMenuName()
    {
        return __('ShopMap', 'shopmap');
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Url::to('front/index.php'),
            'icon'  => 'ti ti-map-2',
        ];
    }

    /**
     * Plantas visíveis na entidade ativa da sessão, mais recentes
     * primeiro. Colunas qualificadas (lição: 'id' ambíguo em JOIN
     * futuro) e restrição de entidade do core.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getFloorplans(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT' => [
                'glpi_plugin_shopmap_floorplans.id',
                'glpi_plugin_shopmap_floorplans.name',
                'glpi_plugin_shopmap_floorplans.entities_id',
                'glpi_plugin_shopmap_floorplans.conversion_status',
                'glpi_plugin_shopmap_floorplans.date_mod',
            ],
            'FROM'   => 'glpi_plugin_shopmap_floorplans',
            'WHERE'  => getEntitiesRestrictCriteria('glpi_plugin_shopmap_floorplans'),
            'ORDER'  => 'glpi_plugin_shopmap_floorplans.date_mod DESC',
        ];

        $plans = [];
        foreach ($DB->request($criteria) as $row) {
            $plans[] = $row;
        }
        return $plans;
    }
}
