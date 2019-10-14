<?php

namespace Drupal\ibt_api\Helper;

use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;

class ApiDataHelper {

  public static function deleteItems(&$context) {
    /** @var \Drupal\ibt_api\UtilityService $utility */
    $utility = \Drupal::service('ibt_api.utility');
    /** @var \Drupal\taxonomy\Entity\Term $dyssTerm */
    $dyssTerm = $utility->getEntityBy('taxonomy_term', 'name', 'Dyssembler');
    if ($dyssTerm instanceof Term) {
      try {
        $dyssTerm->delete();
        \Drupal::messenger()->addStatus('Successfully deleted dyss');
      }
      catch (EntityStorageException $exception) {

      }

    }
  }

  public static function processData($process, &$context) {
    /** @var \Drupal\ibt_api\UtilityService $utility */
    $utility = \Drupal::service('ibt_api.utility');
    $connection = Database::getConnection();
    $messenger = \Drupal::messenger();
    $limit = 25;
    switch ($process) {
      case 0: # Extract sub-headline.
        $selection = $connection->select('node_field_data', 'n')
          ->condition('type', 'audio');
        if (!isset($context['sandbox']['progress'])) {
          $count = $selection->countQuery()->execute()->fetchField();
          $context['sandbox'] = [
            'progress' => 0,
            'limit'    => (int) $limit,
            'max'      => (int) $count,
          ];
          $context['results']['items'] = 0;
          $context['results']['nodes']  = 0;
          // Count new versus existing
          $context['results']['nodes_inserted'] = 0;
          $context['results']['nodes_updated']  = 0;
        }
        $sandbox = &$context['sandbox'];
        $query = $selection->fields('n', ['title', 'nid',])
          ->range($sandbox['progress'], $sandbox['limit'])
        ;
        $results = $query->execute()->fetchAllAssoc('nid');
        if (sizeof($results) < $limit)
          $sandbox['limit'] = sizeof($results);
        foreach ($results as $nid => $row) {
          $sub = '';
          $part = NULL;
          $sequence = NULL;
          $string = $row->title ?? 'NULL';
          if (stripos($string, 'dyssembler') === 0) {
            $string = str_replace('Dyssember #', '', $string);
            $string = str_replace('Dyssembler #', '', $string);
            $string = str_replace('DYSSEMBLER #', '', $string);
            $string = str_replace('Dyssembler Radio #', '', $string);
            $array = explode(' ', $string);
            $number = isset($array[0]) ? array_shift($array) : NULL;
            $number = (int) $number ?? null;
            if (
              isset($array[0])
              && ($array[0] === 'part' || $array[0] === 'Part')
              && isset($array[1])
            ) {
              $sequence = $array[1];
              $part = 'part ' . $sequence;
              unset($array[0]);
              unset($array[1]);
              $array = array_values($array);
            }
            elseif (
              isset($array[0])
              && (
                $array[0] === 'p1'
                || $array[0] === 'p.1'
                || $array[0] === 'p2'
                || $array[0] === 'p.2'
                || $array[0] === 'p3'
                || $array[0] === 'p.3'
              )
            ) {
              $part = array_shift($array);
            }
            if (
              isset($array[0])
              && (
                $array[0] == '-'
                || $array[0] == '/'
                || $array[0] == '~'
                || $array[0] == '.'
                || $array[0] == ':'
              )
            ) {
              unset($array[0]);
              $array = array_values($array);
            }
            $sub = implode(' ', $array);
          }
          else {
            $messenger->addWarning(t('Title excluded: @title', ['@title'=> $string]));
          }
          if (isset($number) && is_integer($number)) {
            $title = 'Dyssembler Radio #' . $number;
            $title = isset($part) ? $title . ' ' . $part : $title;
            /** @var Node $node */
            $node = $utility->entityTypeManager->getStorage('node')
              ->load($row->nid);
            $node->set('field_number', $number);
            $node->set('field_sequence', $sequence);
            $node->set('field_sub_headline', $sub);
            $node->set('title', $title);
            if ($nodeSaved = $node->save()) {
            }
            else
              $messenger->addError(t('Error saving node @nid', ['@nid' => $node->id()]));
            unset($title);
            unset($sub);
            unset($number);
          }

          $sandbox['progress']++;
          $context['results']['items']++;
          if (isset($nodeSaved)) {
            $context['results']['nodes']++;
          }
          // Build a message so this isn't entirely boring for admins
          $msg = '<h2>' . t('Processing Nodes...') . '</h2>';
          $msg .= t('Processed @p of @t items, @n new & @u updated', [
            '@p' => $sandbox['progress'],
            '@t' => $sandbox['max'],
            '@n' => $context['results']['nodes_inserted'],
            '@u' => $context['results']['nodes_updated'],
          ]);
          $msg .= '<br />';
          if (isset($nodeSaved) && isset($node)) {
            $msg .= t('Processed item: %t %n', [
              '%t' => $node->getTitle(),
              '%n' => '(nid:' . $node->id() . ')',
            ]);

          }
          $context['message'] = $msg;
        }

        if ($sandbox['max']) {
          $context['finished'] = $sandbox['progress'] / $sandbox['max'];
        }
        if ($context['finished'] === 1) {
          $query = $connection->delete($utility::DB_STAGING);
          $query->execute();
          $utility->deleteStore('authors');
        }
        break;
    }
  }
  /**
   * Reports the results of the Tea import operations.
   *
   * @param bool  $success
   * @param array $results
   * @param array $operations
   */
  public static function finishedBatch($success, $results, $operations) {
    $downloaded = t('Finished with an error.');
    $processed  = FALSE;
    $saved      = FALSE;
    $inserted   = FALSE;
    $updated    = FALSE;
    drupal_set_message($downloaded);
    if ($processed) {
      drupal_set_message($processed);
    };
    if ($saved) {
      drupal_set_message($saved);
    };
    if ($inserted) {
      drupal_set_message($inserted);
    };
    if ($updated) {
      drupal_set_message($updated);
    };
  }

}
