<?php
// FILE: bm_mysql_entity_views/src/Controller/RebuildController.php
declare(strict_types=1);

namespace Drupal\bm_mysql_entity_views\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\bm_mysql_entity_views\MySqlViewGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class RebuildController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(private readonly MySqlViewGenerator $generator) {}

  public static function create(ContainerInterface $container): static {
    $svc = $container->get('bm_mysql_entity_views.generator');
    if (!$svc instanceof MySqlViewGenerator) {
      throw new \RuntimeException('Service "bm_mysql_entity_views.generator" is not an instance of MySqlViewGenerator.');
    }
    return new self($svc);
  }

  public function rebuild(): array {
    $created = $this->generator->rebuildAll();
    return [
      '#type' => 'container',
      'messages' => ['#type' => 'status_messages'],
      'list' => [
        '#theme' => 'item_list',
        '#items' => array_map(static fn($v) => "Created/updated: $v (+ meta)", $created),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }
}
