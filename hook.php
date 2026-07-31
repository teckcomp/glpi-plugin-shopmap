<?php

/**
 * ShopMap — hooks de instalação/desinstalação.
 * O GLPI exige estas funções globais; a lógica real fica em src/Install.php.
 */

use GlpiPlugin\Shopmap\Install;

function plugin_shopmap_install(): bool
{
    return Install::install();
}

function plugin_shopmap_uninstall(): bool
{
    return Install::uninstall();
}
