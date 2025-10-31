<?php
// FILE: mysql_entity_views/src/Form/MaintenanceForm.php
declare(strict_types=1);

namespace Drupal\mysql_entity_views\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mysql_entity_views\MySqlViewGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class MaintenanceForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(private readonly MySqlViewGenerator $generator) {}

  public static function create(ContainerInterface $container): static {
    $svc = $container->get('mysql_entity_views.generator');
    if (!$svc instanceof MySqlViewGenerator) {
      throw new \RuntimeException('Service "mysql_entity_views.generator" is not an instance of MySqlViewGenerator.');
    }
    return new self($svc);
  }

  public function getFormId(): string {
    return 'mysql_entity_views_maintenance_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Attach small admin CSS.
    $form['#attached']['library'][] = 'mysql_entity_views/admin';

    $form['intro'] = [
      '#markup' => $this->t('Create or delete MySQL VIEWs that flatten entity data per bundle. Use with care on production.')
    ];

    $existing = array_flip($this->generator->listModuleViews());
    $bundles_by_type = $this->generator->getBundlesByEntityType();

    $form['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Entity type'),
        $this->t('Bundle'),
        $this->t('View name'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No bundles found.'),
      '#attributes' => ['id' => 'mysql-entity-views'],
    ];

    foreach ($bundles_by_type as $entity_type => $bundles) {
      foreach ($bundles as $bundle) {
        $view = $this->generator->getViewName($entity_type, $bundle);
        $exists = isset($existing[$view]);
        $row_key = $entity_type . '::' . $bundle;

        $form['table'][$row_key]['entity_type'] = ['#plain_text' => $entity_type];
        $form['table'][$row_key]['bundle'] = ['#plain_text' => $bundle];
        $form['table'][$row_key]['view'] = ['#plain_text' => $view];
        $form['table'][$row_key]['status'] = ['#plain_text' => $exists ? $this->t('Exists') : $this->t('Missing')];
        $form['table'][$row_key]['ops'] = [
          '#type' => 'container',
          'add' => [
            '#type' => 'submit',
            '#value' => $this->t('Add/Update'),
            '#name' => "add::$row_key",
            '#submit' => [[self::class, 'submitAdd']],
          ],
          'delete' => [
            '#type' => 'submit',
            '#value' => $this->t('Delete'),
            '#name' => "delete::$row_key",
            '#submit' => [[self::class, 'submitDelete']],
            '#attributes' => ['onclick' => 'return confirm("Delete this view?")'],
          ],
        ];
      }
    }

    $form['bulk'] = [
      '#type' => 'details',
      '#title' => $this->t('Bulk actions'),
      '#open' => FALSE,
    ];
    $form['bulk']['rebuild_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild all views'),
      '#submit' => [[self::class, 'submitRebuildAll']],
    ];
    $form['bulk']['drop_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete all module views'),
      '#submit' => [[self::class, 'submitDropAll']],
      '#attributes' => ['onclick' => 'return confirm("Delete ALL views created by this module?")'],
    ];

    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No default submission.
  }

  public static function submitAdd(array &$form, FormStateInterface $form_state): void {
    $instance = $form_state->getFormObject();
    [$entity_type, $bundle] = self::parseRowKey($form_state);
    if ($entity_type && $bundle) {
      $name = $instance->generator->createViewForBundle($entity_type, $bundle);
      $instance->messenger()->addStatus($instance->t('Created/updated view @v.', ['@v' => $name ?? '']));
    }
    $form_state->setRebuild();
  }

  public static function submitDelete(array &$form, FormStateInterface $form_state): void {
    $instance = $form_state->getFormObject();
    [$entity_type, $bundle] = self::parseRowKey($form_state);
    if ($entity_type && $bundle) {
      $instance->generator->dropViewForBundle($entity_type, $bundle);
      $instance->messenger()->addStatus(
        $instance->t('Deleted view @v.', ['@v' => $instance->generator->getViewName($entity_type, $bundle)])
      );
    }
    $form_state->setRebuild();
  }

  public static function submitRebuildAll(array &$form, FormStateInterface $form_state): void {
    $instance = $form_state->getFormObject();
    $list = $instance->generator->rebuildAll();
    $instance->messenger()->addStatus($instance->t('Rebuilt @n views.', ['@n' => count($list)]));
    $form_state->setRebuild();
  }

  public static function submitDropAll(array &$form, FormStateInterface $form_state): void {
    $instance = $form_state->getFormObject();
    $instance->generator->dropAll();
    $instance->messenger()->addStatus($instance->t('All module-managed views were deleted.'));
    $form_state->setRebuild();
  }

  private static function parseRowKey(FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $name = (string) ($trigger['#name'] ?? '');
    // Expected patterns: "add::<entity_type>::<bundle>" or "delete::<entity_type>::<bundle>"
    $parts = explode('::', $name, 3);
    if (count($parts) === 3) {
      return [$parts[1], $parts[2]];
    }
    return [null, null];
  }
}
