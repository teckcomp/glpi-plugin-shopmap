<?php

/**
 * ShopMap — gestão visual de infraestrutura de TI sobre planta baixa
 * (shoppings e grandes edifícios), para GLPI 11.
 *
 * Referência de produto: OZmap "indoor". Não altera tabelas nativas —
 * apenas adiciona tabelas próprias (prefixo glpi_plugin_shopmap_) e
 * reutiliza NetworkPort/Documents do core como fonte de verdade.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Shopmap\Dashboard;

define('PLUGIN_SHOPMAP_VERSION', '0.1.0');

// Versões mínima/máxima do GLPI suportadas
define('PLUGIN_SHOPMAP_MIN_GLPI', '11.0.0');
define('PLUGIN_SHOPMAP_MAX_GLPI', '11.0.99');

/**
 * Inicialização do plugin: hooks, menus, CSS/JS.
 */
function plugin_init_shopmap(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['shopmap'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('shopmap')) {
        return;
    }

    // Item de menu: Ativos > ShopMap (dashboard de plantas)
    if (Session::haveRight('plugin_shopmap', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['shopmap'] = [
            'assets' => Dashboard::class,
        ];
    }

    // Aba "ShopMap" no formulário de Perfil (Administração → Perfis):
    // matriz de direitos por módulo, Bloco 8a. A própria classe restringe
    // quem vê ($rightname = 'profile'), então o registro é incondicional —
    // amarrá-lo a `plugin_shopmap` esconderia a aba justamente de quem
    // precisa conceder o direito pela primeira vez.
    Plugin::registerClass(\GlpiPlugin\Shopmap\Profile::class, ['addtabon' => \Profile::class]);

    // CSS do plugin: NÃO usa mais o hook ADD_CSS — ele monta a URL sem
    // cache-buster (?v=versão do plugin, que nunca muda entre blocos) e
    // o celular ficou preso num shopmap.css antigo (Bloco 9 r2). Cada
    // template linka o CSS via Url::asset() (?v=filemtime), o MESMO
    // padrão do JS desde a lição 13.
}

/**
 * Metadados do plugin.
 */
function plugin_version_shopmap(): array
{
    return [
        'name'         => 'ShopMap',
        'version'      => PLUGIN_SHOPMAP_VERSION,
        'author'       => 'Teckcomp I.T. Services',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/teckcomp/glpi-plugin-shopmap',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SHOPMAP_MIN_GLPI,
                'max' => PLUGIN_SHOPMAP_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Pré-requisitos (chamado antes da instalação).
 */
function plugin_shopmap_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_SHOPMAP_MIN_GLPI, '<')) {
        echo sprintf(
            'Este plugin requer GLPI >= %s (versão atual: %s)',
            PLUGIN_SHOPMAP_MIN_GLPI,
            GLPI_VERSION
        );
        return false;
    }
    return true;
}

/**
 * Verificação de configuração (chamado na ativação).
 */
function plugin_shopmap_check_config($verbose = false): bool
{
    return true;
}
