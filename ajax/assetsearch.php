<?php

/**
 * ShopMap — busca de ativos para vincular a um shape (Bloco 3).
 *
 * GET q=<texto> -> {results: [{itemtype, itemtype_label, id, name}]}
 * Busca por nome em NetworkEquipment/Computer/Printer, respeitando a
 * entidade da sessão, até 10 resultados no total.
 */

use GlpiPlugin\Shopmap\Shape;

include('../../../inc/includes.php');

Session::checkRight('plugin_shopmap', READ);

header('Content-Type: application/json; charset=UTF-8');

/** @var \DBmysql $DB */
global $DB;

$q = trim((string) ($_GET['q'] ?? ''));
$results = [];

if (mb_strlen($q) >= 2) {
    foreach (Shape::LINKABLE as $itemtype) {
        if (count($results) >= 10 || !class_exists($itemtype)) {
            continue;
        }
        $table = $itemtype::getTable();
        $it = $DB->request([
            'SELECT' => ["$table.id", "$table.name"],
            'FROM'   => $table,
            'WHERE'  => [
                "$table.is_deleted" => 0,
                "$table.name"       => ['LIKE', '%' . $q . '%'],
            ] + getEntitiesRestrictCriteria($table),
            'ORDER'  => "$table.name",
            'LIMIT'  => 10 - count($results),
        ]);
        foreach ($it as $row) {
            $results[] = [
                'itemtype'       => $itemtype,
                'itemtype_label' => $itemtype::getTypeName(1),
                'id'             => (int) $row['id'],
                'name'           => (string) $row['name'],
            ];
        }
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
