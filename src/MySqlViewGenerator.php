<?php
// FILE: mysql_entity_views/src/MySqlViewGenerator.php
declare(strict_types=1);

namespace Drupal\mysql_entity_views;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;

final class MySqlViewGenerator {

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $etm,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  private function cfg() {
    return $this->configFactory->get('mysql_entity_views.settings');
  }

  public function rebuildAll(): array {
    $created = [];
    if ($this->db->driver() !== 'mysql') {
      return $created;
    }
    foreach ($this->getSupportedEntityTypes() as $etid => $definition) {
      $base_table = $definition->getBaseTable();
      $id_key = $definition->getKey('id');
      if (!$base_table || !$id_key) {
        continue;
      }
      $created = array_merge($created, $this->buildForEntityType($etid, $base_table, $id_key));
    }
    return $created;
  }

  public function createViewForBundle(string $entity_type, string $bundle): ?string {
    $def = $this->etm->getDefinition($entity_type, false);
    if (!$def instanceof ContentEntityTypeInterface) {
      return null;
    }
    $base_table = $def->getBaseTable();
    $id_key = $def->getKey('id');
    if (!$base_table || !$id_key) {
      return null;
    }
    return $this->createBundleView($entity_type, $bundle, $base_table, $id_key);
  }

  public function dropViewForBundle(string $entity_type, string $bundle): void {
    $vn = $this->db->escapeTable($this->getViewName($entity_type, $bundle));
    if ($this->db->driver() === 'mysql') {
      $this->db->query("DROP VIEW IF EXISTS `$vn`");
      $meta = $this->db->escapeTable($this->getMetaViewName($entity_type, $bundle));
      $this->db->query("DROP VIEW IF EXISTS `$meta`");
    }
  }

  public function getViewName(string $entity_type, string $bundle): string {
    return "view_{$entity_type}__{$bundle}";
  }

  public function getMetaViewName(string $entity_type, string $bundle): string {
    return $this->getViewName($entity_type, $bundle) . '__meta';
  }

  public function listModuleViews(): array {
    if ($this->db->driver() !== 'mysql') {
      return [];
    }
    $database = $this->db->getConnectionOptions()['database'] ?? '';
    $views = $this->db->query(
      "SELECT TABLE_NAME
         FROM INFORMATION_SCHEMA.VIEWS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE 'view\\_%\\_\\_%'
        ORDER BY TABLE_NAME",
      [':db' => $database]
    )->fetchCol();
    return array_map(static fn($v) => (string) $v, $views);
  }

  public function dropAll(): void {
    if ($this->db->driver() !== 'mysql') {
      return;
    }
    foreach ($this->listModuleViews() as $view) {
      $vn = $this->db->escapeTable($view);
      $this->db->query("DROP VIEW IF EXISTS `$vn`");
    }
  }

  public function getSupportedEntityTypes(): array {
    $supported = [];
    foreach ($this->etm->getDefinitions() as $etid => $def) {
      if ($def instanceof ContentEntityTypeInterface && $def->getBundleEntityType()) {
        if ($def->getBaseTable() && $def->getKey('id')) {
          $supported[$etid] = $def;
        }
      }
    }
    return $supported;
  }

  public function getBundlesByEntityType(): array {
    $out = [];
    foreach ($this->getSupportedEntityTypes() as $etid => $def) {
      $bundle_storage_id = $def->getBundleEntityType();
      $bundle_storage = $this->etm->getStorage($bundle_storage_id);
      $bundles = array_keys($bundle_storage->loadMultiple());
      sort($bundles);
      $out[$etid] = $bundles;
    }
    ksort($out);
    return $out;
  }

  private function buildForEntityType(string $entity_type, string $base_table, string $id_key): array {
    $created = [];
    $bundle_storage = $this->etm->getStorage($entity_type . '_type') ?: (
    $this->etm->getStorage($this->etm->getDefinition($entity_type)->getBundleEntityType())
    );
    if (!$bundle_storage) {
      return $created;
    }
    $bundles = $bundle_storage->loadMultiple();
    foreach ($bundles as $bundle_id => $bundle) {
      $view = $this->createBundleView($entity_type, $bundle_id, $base_table, $id_key);
      if ($view) {
        $created[] = $view;
      }
    }
    return $created;
  }

  private function createBundleView(string $entity_type, string $bundle, string $base_table, string $id_key): ?string {
    if ($this->db->driver() !== 'mysql') {
      return null;
    }

    // Session setting for wide concatenations.
    $max_len = max(0, (int) ($this->cfg()->get('group_concat_max_len') ?? 1048576));
    $this->db->query("SET SESSION group_concat_max_len = " . $max_len);

    $sep = (string) ($this->cfg()->get('separator') ?? ' / ');
    $sep_q = $this->db->quote($sep);

    $order = (string) ($this->cfg()->get('order_multi_values') ?? 'delta');
    $order_sql = match ($order) {
      'delta' => ' ORDER BY `delta`',
      'value' => ' ORDER BY `value`',
      default => ''
    };

    $bt = $this->db->escapeTable($base_table);
    $ik = $this->db->escapeField($id_key);
    $view_name = $this->getViewName($entity_type, $bundle);
    $vn = $this->db->escapeTable($view_name);

    $base_cols = [
      "$bt.$ik AS id",
      "$bt.langcode",
      "$bt.type",
      "$bt.uid",
      "$bt.status",
      "$bt.created",
      "$bt.changed",
    ];
    $columns_bt = $this->listColumns($base_table);
    if (in_array('title', $columns_bt, true)) {
      array_splice($base_cols, 3, 0, "$bt.title");
    }

    $fields = FieldConfig::loadByProperties(['entity_type' => $entity_type, 'bundle' => $bundle]);
    $joins = [];
    $selects = [];

    foreach ($fields as $fc) {
      $field_name = $fc->getName();
      $data_table = "{$entity_type}__{$field_name}";
      if (!$this->db->schema()->tableExists($data_table)) {
        continue;
      }
      $columns = $this->listColumns($data_table);
      if (!$columns) {
        continue;
      }

      $preferred = ['value','target_id','uri','url','title','entity_id','format','width','height','fid'];
      $pick = array_values(array_intersect($preferred, $columns));
      if (!$pick) {
        $pick = array_values(array_filter(
          $columns,
          static fn($c) => !in_array($c, ['bundle','deleted','entity_id','revision_id','langcode','delta'], true)
        ));
      }

      $alias = "agg_{$field_name}";
      $safe_alias = $this->db->escapeTable($alias);
      $safe_table = $this->db->escapeTable($data_table);
      $coalesce = 'COALESCE(' . implode(', ', array_map(static fn($c) => "`$c`", $pick)) . ')';

      $agg_select = "SELECT `entity_id`, `langcode`, GROUP_CONCAT($coalesce{$order_sql} SEPARATOR {$sep_q}) AS `{$field_name}`
                     FROM `$safe_table`
                     WHERE `deleted` = 0
                     GROUP BY `entity_id`, `langcode`";

      $joins[] = "LEFT JOIN ($agg_select) AS `$safe_alias`
                    ON `$safe_alias`.`entity_id` = $bt.$ik
                   AND `$safe_alias`.`langcode` = $bt.langcode";
      $selects[] = "`$safe_alias`.`{$field_name}` AS `{$field_name}`";
    }

    $bundle_q = $this->db->quote($bundle);

    $sql = "CREATE OR REPLACE VIEW `$vn` AS
      SELECT
        " . implode(",\n        ", array_merge($base_cols, $selects)) . "
      FROM `$bt`
      WHERE `$bt`.`type` = $bundle_q";

    $txn = $this->db->startTransaction();
    try {
      $this->db->query("DROP VIEW IF EXISTS `$vn`");
      if ($joins) {
        $sql = str_replace("FROM `$bt`", "FROM `$bt`\n      " . implode("\n      ", $joins), $sql);
      }
      $this->db->query($sql);

      // Optional companion META view with comments per column.
      if ($this->cfg()->get('create_meta_views')) {
        $this->createMetaView($entity_type, $bundle, $view_name, $columns_bt, $fields);
      }
    }
    catch (\Throwable $e) {
      $txn->rollBack();
      return null;
    }
    return $view_name;
  }

  /**
   * Creates <view>__meta with (column_name, comment, source).
   * This is a workaround because MySQL does not store column comments for VIEW columns.
   */
  private function createMetaView(string $entity_type, string $bundle, string $view_name, array $base_columns, array $field_configs): void {
    $meta_name = $this->db->escapeTable($this->getMetaViewName($entity_type, $bundle));
    $rows = [];

    // Base table column comments.
    $base_map = [
      'id'      => 'Entity ID',
      'langcode'=> 'Language code',
      'type'    => 'Bundle machine name',
      'uid'     => 'Author user ID',
      'status'  => 'Published (1) / Unpublished (0)',
      'created' => 'Created timestamp (Unix)',
      'changed' => 'Updated timestamp (Unix)',
      'title'   => 'Title',
    ];
    foreach (['id','langcode','type','uid','status','created','changed','title'] as $col) {
      if ($col === 'title' && !in_array('title', $base_columns, true)) {
        continue;
      }
      $cn = $this->db->quote($col);
      $cm = $this->db->quote($base_map[$col] ?? '');
      $src = $this->db->quote('base');
      $rows[] = "SELECT $cn AS column_name, $cm AS comment, $src AS source";
    }

    // Field columns.
    /** @var \Drupal\field\Entity\FieldConfig $fc */
    foreach ($field_configs as $fc) {
      $name = $fc->getName();                 // e.g. field_tags
      $label = trim((string) $fc->label());   // human label
      $desc = trim((string) ($fc->getDescription() ?? ''));
      $comment = $label . ($desc ? (': ' . $desc) : '');
      $cn = $this->db->quote($name);
      $cm = $this->db->quote($comment ?: $label ?: $name);
      $src = $this->db->quote("field:$name");
      $rows[] = "SELECT $cn AS column_name, $cm AS comment, $src AS source";
    }

    $sql = "CREATE OR REPLACE VIEW `$meta_name` AS\n" . implode("\nUNION ALL\n", $rows);
    $this->db->query("DROP VIEW IF EXISTS `$meta_name`");
    $this->db->query($sql);
  }

  private function listColumns(string $table): array {
    $database = $this->db->getConnectionOptions()['database'] ?? '';
    $cols = $this->db->query(
      "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
        ORDER BY ORDINAL_POSITION",
      [':db' => $database, ':tbl' => $table]
    )->fetchCol();
    return array_map(static fn($c) => (string) $c, $cols);
  }
}
