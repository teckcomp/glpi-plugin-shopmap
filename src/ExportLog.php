<?php

namespace GlpiPlugin\Shopmap;

/**
 * Histórico de exportações (Bloco 7b) — log simples, sem re-download.
 *
 * Decisões do usuário (06/08/2026):
 *  - só a exportação PDF registra (o PNG é gerado e baixado 100% no
 *    navegador, o servidor nem fica sabendo);
 *  - exibição no dashboard, todas as plantas juntas;
 *  - o arquivo NÃO fica guardado no servidor — a linha do log é o
 *    registro (quem, quando, qual arquivo foi entregue).
 */
class ExportLog
{
    private const TABLE = 'glpi_plugin_shopmap_exports';

    /**
     * Registra uma exportação. Chamado pelo ajax/export.php após o PDF
     * ser gerado com sucesso (nunca antes — falha não vira histórico).
     */
    public static function add(int $floorplansId, int $usersId, string $filename): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->insert(self::TABLE, [
            'plugin_shopmap_floorplans_id' => $floorplansId,
            'users_id'                     => $usersId,
            'filename'                     => $filename,
            'date_creation'                => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Últimas exportações visíveis na entidade ativa da sessão (via
     * restrição sobre a planta — mesma regra do Dashboard), mais
     * recentes primeiro. Colunas SEMPRE qualificadas (lição: JOIN com
     * 'id' ambíguo). O nome do usuário sai pronto (friendlyname do
     * core) para o template não fazer lookup.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 30): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT' => [
                'glpi_plugin_shopmap_exports.id',
                'glpi_plugin_shopmap_exports.filename',
                'glpi_plugin_shopmap_exports.date_creation',
                'glpi_plugin_shopmap_floorplans.name AS plan_name',
                'glpi_users.name AS user_name',
                'glpi_users.realname AS user_realname',
                'glpi_users.firstname AS user_firstname',
            ],
            'FROM'      => self::TABLE,
            'LEFT JOIN' => [
                'glpi_plugin_shopmap_floorplans' => [
                    'ON' => [
                        self::TABLE                      => 'plugin_shopmap_floorplans_id',
                        'glpi_plugin_shopmap_floorplans' => 'id',
                    ],
                ],
                'glpi_users' => [
                    'ON' => [
                        self::TABLE  => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'WHERE' => getEntitiesRestrictCriteria('glpi_plugin_shopmap_floorplans'),
            'ORDER' => 'glpi_plugin_shopmap_exports.date_creation DESC',
            'LIMIT' => max(1, $limit),
        ];

        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            // nome exibível: "Sobrenome Nome" quando cadastrado, senão o login
            $display = trim(($row['user_realname'] ?? '') . ' ' . ($row['user_firstname'] ?? ''));
            $row['user_display'] = $display !== '' ? $display : (string) ($row['user_name'] ?? '');
            if ($row['user_display'] === '') {
                $row['user_display'] = '—';
            }
            $row['plan_name'] = (string) ($row['plan_name'] ?? '') !== '' ? $row['plan_name'] : '(planta excluída)';
            $rows[] = $row;
        }
        return $rows;
    }
}
