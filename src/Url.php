<?php

namespace GlpiPlugin\Shopmap;

/**
 * Helper ÚNICO de URL do plugin.
 *
 * `Plugin::getWebDir()` está DEPRECATED no GLPI 11 (cada chamada grava
 * aviso no log). No GLPI 11 quem resolve a rota é o PluginsRouterListener,
 * que localiza o plugin pela CHAVE — `/plugins/<key>` funciona inclusive
 * para instalação via marketplace. NÃO usar `$_SERVER['PHP_SELF']`: no
 * front controller do GLPI 11 ele vale sempre `/index.php`.
 *
 * (Padrão herdado do ProjectPlus, Etapa 6, Bloco 4a.)
 */
final class Url
{
    /**
     * Chave/diretório do plugin. É a mesma string usada pelo roteador.
     */
    public const KEY = 'shopmap';

    /**
     * Raiz web do plugin, sem barra no fim. Ex.: `/glpi/plugins/shopmap`
     */
    public static function base(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/' . self::KEY;
    }

    /**
     * URL de um recurso do plugin. Aceita o caminho com ou sem barra
     * inicial. Ex.: `Url::to('front/index.php')`
     */
    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * URL de recurso ESTÁTICO (js/css) com cache-buster `?v=<mtime>`.
     *
     * O front controller do GLPI 11 serve `public/` com cache agressivo
     * no navegador; sem versionar a URL, o cliente segue executando o
     * JS antigo mesmo depois de atualizar o bloco — inclusive com
     * Ctrl+F5 em alguns cenários (lição do Bloco 4d r4). A versão é o
     * mtime do arquivo: muda sozinha a cada bloco aplicado.
     */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname(__DIR__) . '/public/' . $path;
        $v    = is_file($file) ? (string) filemtime($file) : '0';

        return self::to($path) . '?v=' . $v;
    }
}
