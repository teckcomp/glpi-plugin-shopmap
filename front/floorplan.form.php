<?php

/**
 * ShopMap — grava/exclui plantas (Bloco 2a).
 *
 * CSRF: validado automaticamente pelo core em POST (token no formulário).
 */

use GlpiPlugin\Shopmap\Floorplan;
use GlpiPlugin\Shopmap\Url;

include('../../../inc/includes.php');

Session::checkLoginUser();

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    Session::checkRight('plugin_shopmap', DELETE);

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && Floorplan::purge($id)) {
        Session::addMessageAfterRedirect(__('Planta excluída', 'shopmap'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Planta não encontrada', 'shopmap'), true, ERROR);
    }
    Html::redirect(Url::to('front/index.php'));
}

// ---- criação (upload) ----
Session::checkRight('plugin_shopmap', CREATE);

$name = trim((string) ($_POST['name'] ?? ''));
$file = $_FILES['planfile'] ?? null;

if ($name === '') {
    Session::addMessageAfterRedirect(__('Informe o nome da planta', 'shopmap'), true, ERROR);
    Html::redirect(Url::to('front/index.php'));
}

if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Session::addMessageAfterRedirect(__('Selecione o arquivo da planta', 'shopmap'), true, ERROR);
    Html::redirect(Url::to('front/index.php'));
}

$ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

if (in_array($ext, Floorplan::FUTURE_EXT, true)) {
    Session::addMessageAfterRedirect(
        __('DWG/DXF/PDF serão aceitos no próximo bloco (conversão automática para SVG). Por enquanto, envie SVG, PNG ou JPG.', 'shopmap'),
        true,
        WARNING
    );
    Html::redirect(Url::to('front/index.php'));
}

if (!in_array($ext, Floorplan::ALLOWED_EXT, true)) {
    Session::addMessageAfterRedirect(
        sprintf(__('Formato não suportado (.%s). Aceitos: SVG, PNG, JPG.', 'shopmap'), $ext),
        true,
        ERROR
    );
    Html::redirect(Url::to('front/index.php'));
}

$id = Floorplan::createFromUpload($name, (string) $file['tmp_name'], $ext);

if ($id > 0) {
    Session::addMessageAfterRedirect(__('Planta cadastrada', 'shopmap'), true, INFO);
    Html::redirect(Url::to('front/plan.php') . '?id=' . $id);
}

Session::addMessageAfterRedirect(__('Falha ao gravar a planta (verifique permissões de files/_plugins)', 'shopmap'), true, ERROR);
Html::redirect(Url::to('front/index.php'));
