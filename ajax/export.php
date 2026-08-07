<?php

/**
 * ShopMap — endpoint AJAX da exportação PDF (Bloco 7b).
 *
 * POST action=pdf (floorplans_id, image=dataURL PNG, filename)
 *   -> {ok:true, pdf: <base64>, filename, csrf}
 *
 * Fluxo: o navegador gera o PNG do recorte (mesmo desenho do 7a) e
 * envia aqui; o servidor embrulha em PDF A4 paisagem com cabeçalho
 * (tools/png2pdf.py, Pillow), registra no histórico (ExportLog) e
 * devolve o PDF em base64 DENTRO do JSON — resposta JSON (e não
 * binária) para rotacionar o token CSRF no mesmo padrão dos outros
 * endpoints (uso único; o JS troca a cada POST).
 *
 * Direito: READ — exportar é leitura, mesmo critério do 7a (o botão
 * de recorte funciona em modo leitura).
 *
 * O PDF é EFÊMERO: gerado em tmp, lido, apagado. Nada fica no
 * servidor; o histórico guarda só quem/quando/arquivo (decisão do
 * usuário: log simples, sem re-download).
 */

use GlpiPlugin\Shopmap\ExportLog;
use GlpiPlugin\Shopmap\Floorplan;

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

Session::checkRight('plugin_shopmap', READ);

$action = $_POST['action'] ?? '';
if ($action !== 'pdf') {
    sm_reply(['ok' => false, 'error' => 'ação inválida'], 400);
}

$planId = (int) ($_POST['floorplans_id'] ?? 0);
$plan   = $planId > 0 ? Floorplan::getById($planId) : null;
if ($plan === null) {
    sm_reply(['ok' => false, 'error' => 'planta não encontrada'], 404);
}

// ---- PNG enviado como data URL ----
$dataUrl = (string) ($_POST['image'] ?? '');
$prefix  = 'data:image/png;base64,';
if (!str_starts_with($dataUrl, $prefix)) {
    sm_reply(['ok' => false, 'error' => 'imagem inválida'], 400);
}
// Teto de 30 MB de base64 (~22 MB de PNG) — recorte de 1800px fica MUITO
// abaixo disso; o teto só barra abuso.
if (strlen($dataUrl) > 30 * 1024 * 1024) {
    sm_reply(['ok' => false, 'error' => 'imagem grande demais'], 413);
}
$png = base64_decode(substr($dataUrl, strlen($prefix)), true);
// Assinatura PNG (8 bytes) — barra payload que não é PNG de verdade
if ($png === false || strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) {
    sm_reply(['ok' => false, 'error' => 'imagem inválida'], 400);
}

// ---- nome do arquivo: mesmo slug do front, revalidado no servidor ----
$reqName  = (string) ($_POST['filename'] ?? '');
$filename = preg_match('/^[a-z0-9][a-z0-9._-]{0,140}\.pdf$/', $reqName) === 1
    ? $reqName
    : ('shopmap-export-' . date('Ymd-Hi') . '.pdf');

// ---- gera o PDF via Pillow (efêmero, em GLPI_TMP_DIR) ----
$tag    = uniqid('smexp_', true);
$tmpPng = GLPI_TMP_DIR . '/' . $tag . '.png';
$tmpPdf = GLPI_TMP_DIR . '/' . $tag . '.pdf';

if (@file_put_contents($tmpPng, $png) === false) {
    sm_reply(['ok' => false, 'error' => 'falha ao gravar arquivo temporário'], 500);
}

$script = dirname(__DIR__) . '/tools/png2pdf.py';
$title  = (string) $plan['name'];
$when   = date('d/m/Y H:i');
$cmd    = sprintf(
    'timeout 60 python3 %s %s %s %s %s 2>&1',
    escapeshellarg($script),
    escapeshellarg($tmpPng),
    escapeshellarg($tmpPdf),
    escapeshellarg($title),
    escapeshellarg($when)
);
$out = [];
$rc  = 1;
@exec($cmd, $out, $rc);
@unlink($tmpPng);

if ($rc !== 0 || !is_file($tmpPdf) || filesize($tmpPdf) === 0) {
    @unlink($tmpPdf);
    $err = trim(implode(' | ', array_slice($out, -3)));
    if (str_contains($err, 'No module named')) {
        $err = 'módulo Python Pillow ausente — instale com: pip install pillow --break-system-packages';
    }
    sm_reply(['ok' => false, 'error' => 'geração do PDF falhou: ' . ($err !== '' ? $err : 'rc=' . $rc)], 500);
}

$pdf = file_get_contents($tmpPdf);
@unlink($tmpPdf);

// Só registra DEPOIS do sucesso — falha não vira histórico
ExportLog::add($planId, (int) Session::getLoginUserID(), $filename);

sm_reply([
    'ok'       => true,
    'pdf'      => base64_encode($pdf),
    'filename' => $filename,
]);
