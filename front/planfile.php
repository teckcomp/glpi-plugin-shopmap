<?php

/**
 * ShopMap — entrega o arquivo de exibição da planta (Bloco 2a).
 *
 * files/_plugins não é servido pelo Apache; este endpoint valida o
 * direito e a entidade antes de devolver o arquivo. O CSP abaixo
 * neutraliza <script> dentro de SVG enviado por usuário (defesa em
 * profundidade — o Leaflet exibe via <img>, onde script já não roda).
 */

use GlpiPlugin\Shopmap\Floorplan;

include('../../../inc/includes.php');

Session::checkRight('plugin_shopmap', READ);

$id   = (int) ($_GET['id'] ?? 0);
$plan = $id > 0 ? Floorplan::getById($id) : null;

if ($plan === null || empty($plan['svg_filename'])) {
    http_response_code(404);
    exit;
}

$path = Floorplan::filePath((string) $plan['svg_filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . Floorplan::mimeFor((string) $plan['svg_filename']));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
