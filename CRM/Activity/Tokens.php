<?php

/*
 +--------------------------------------------------------------------+
 | CiviCRM version 5                                                  |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2018                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2018
 */

/**
 * Class CRM_Member_Tokens
 *
 * Generate "activity.*" tokens.
 *
 * This TokenSubscriber was produced by refactoring the code from the
 * scheduled-reminder system with the goal of making that system
 * more flexible. The current implementation is still coupled to
 * scheduled-reminders. It would be good to figure out a more generic
 * implementation which is not tied to scheduled reminders, although
 * that is outside the current scope.
 */
class CRM_Activity_Tokens extends \Civi\Token\AbstractTokenSubscriber {

  /**
   * CRM_Activity_Tokens constructor.
   */
  public function __construct() {
    $tokens = [];
    foreach (CRM_Core_SelectValues::contactTokens() as $label => $name) {
      $match = [];
      if (preg_match('/{contact\.(.*)}/', $label, $match)) {
        $tokens['source_' . $match[1]] = "(Source) " . $name;
        $tokens['target_N_' . $match[1]] = "(Target N) " . $name;
        $tokens['assignee_N_' . $match[1]] = "(Assignee N) " . $name;
      }
    }
    parent::__construct('activity', array_merge(
      array(
        'activity_id' => ts('Activity ID'),
        'activity_type' => ts('Activity Type'),
        'status' => ts('Activity Status'),
        'subject' => ts('Activity Subject'),
        'details' => ts('Activity Details'),
        'activity_date_time' => ts('Activity Date-Time'),
        'targets_count' => ts('Count of Activity Targets'),
        'assignees_count' => ts('Count of Activity Assignees'),
      ),
      CRM_Utils_Token::getCustomFieldTokens('Activity'),
      $tokens
    ));
  }

  /**
   * @inheritDoc
   */
  public function checkActive(\Civi\Token\TokenProcessor $processor) {
    // Extracted from scheduled-reminders code. See the class description.
    return
      in_array('activityId', $processor->context['schema']) ||
      (!empty($processor->context['actionMapping'])
      && $processor->context['actionMapping']->getEntity() === 'civicrm_activity');
  }

  /**
   * @inheritDoc
   */
  public function getActiveTokens(\Civi\Token\Event\TokenValueEvent $e) {
    $messageTokens = $e->getTokenProcessor()->getMessageTokens();
    if (!isset($messageTokens[$this->entity])) {
      return NULL;
    }

    $activeTokens = [];
    // if message token contains '_\d+_', then treat as '_N_'
    foreach ($messageTokens[$this->entity] as $msgToken) {
      if (array_key_exists($msgToken, $this->tokenNames)) {
        $activeTokens[$msgToken] = 1;
      }
      else {
        $altToken = preg_replace('/_\d+_/', '_N_', $msgToken);
        if (array_key_exists($altToken, $this->tokenNames)) {
          $activeTokens[$msgToken] = 1;
        }
      }
    }
    return array_keys($activeTokens);
  }


  /**
   * @inheritDoc
   */
  public function alterActionScheduleQuery(\Civi\ActionSchedule\Event\MailingQueryEvent $e) {
    if ($e->mapping->getEntity() !== 'civicrm_activity') {
      return;
    }

    // The joint expression for activities needs some extra nuance to handle.
    // Multiple revisions of the activity.
    // Q: Could we simplify & move the extra AND clauses into `where(...)`?
    $e->query->param('casEntityJoinExpr', 'e.id = reminder.entity_id AND e.is_current_revision = 1 AND e.is_deleted = 0');

    $e->query->select('e.*'); // FIXME: seems too broad.
    $e->query->select('ov.label as activity_type, e.id as activity_id');

    $e->query->join("og", "!casMailingJoinType civicrm_option_group og ON og.name = 'activity_type'");
    $e->query->join("ov", "!casMailingJoinType civicrm_option_value ov ON e.activity_type_id = ov.value AND ov.option_group_id = og.id");

    // if CiviCase component is enabled, join for caseId.
    $compInfo = CRM_Core_Component::getEnabledComponents();
    if (array_key_exists('CiviCase', $compInfo)) {
      $e->query->select("civicrm_case_activity.case_id as case_id");
      $e->query->join('civicrm_case_activity', "LEFT JOIN `civicrm_case_activity` ON `e`.`id` = `civicrm_case_activity`.`activity_id`");
    }
  }

  /**
   * @inheritDoc
   */
  public function prefetch(\Civi\Token\Event\TokenValueEvent $e) {
    if (!empty($e->getTokenProcessor()->context['actionMapping'])) {
      // Scheduled reminders provide results objects so don't prefetch
      return NULL;
    }

    // Get the activities
    foreach ($e->getRows() as $row) {
      $activityIds[] = $row->context['activityId'];
    }

    $activities = civicrm_api3('Activity', 'get', array(
      'id' => array('IN' => $activityIds),
    ));
    $prefetch['activity'] = $activities['values'];

    // If we need ActivityContacts, load them
    $messageTokens = $e->getTokenProcessor()->getMessageTokens();
    $needContacts = FALSE;
    foreach ($messageTokens[$this->entity] as $token) {
      if (preg_match('/^source|target|assignee/', $token)) {
        $needContacts = TRUE;
        break;
      }
    }
    if ($needContacts) {
      $result = civicrm_api3('ActivityContact', 'get', [
        'sequential' => 1,
        'activity_id' => array('IN' => $activityIds),
      ]);
      $contactIds = [];
      $types = [ '1' => 'assignee', '2' => 'source', '3' => 'target'];
      foreach ($result['values'] as $ac) {
        if ($ac['record_type_id'] == 2) {
          $prefetch['activityContact'][$ac['activity_id']][$types[$ac['record_type_id']]] = $ac['contact_id'];
        }
        else {
          $prefetch['activityContact'][$ac['activity_id']][$types[$ac['record_type_id']]][] = $ac['contact_id'];
        }
        $contactIds[$ac['contact_id']] = 1;
      }
      $result = civicrm_api3('Contact', 'get', [
        'id' => array('IN' => array_keys($contactIds)),
      ]);
      $prefetch['contact'] = $result['values'];
    }
    return $prefetch;
  }

  /**
   * @inheritDoc
   */
  public function evaluateToken(\Civi\Token\TokenRow $row, $entity, $field, $prefetch = NULL) {
    // maps token name to api field
    $mapping = array(
      'activity_id' => 'id',
    );

    if (!empty($row->context['actionSearchResult'])) {
      // For scheduled reminders
      $activity = $row->context['actionSearchResult'];
    }
    else {
      $activity = (object) $prefetch['activity'][$row->context['activityId']];
    }

    if (in_array($field, array('activity_date_time'))) {
      $row->tokens($entity, $field, \CRM_Utils_Date::customFormat($activity->$field));
    }
    elseif (isset($mapping[$field]) AND (isset($activity->{$mapping[$field]}))) {
      $row->tokens($entity, $field, $activity->{$mapping[$field]});
    }
    elseif (in_array($field, array('activity_type'))) {
      $activityTypes = \CRM_Core_OptionGroup::values('activity_type');
      $row->tokens($entity, $field, $activityTypes[$activity->activity_type_id]);
    }
    elseif ((strpos($field, 'custom_') === 0) AND ($cfID = \CRM_Core_BAO_CustomField::getKeyID($field))) {
      $row->customToken($entity, $cfID, $activity->id);
    }
    elseif (isset($activity->$field)) {
      $row->tokens($entity, $field, $activity->$field);
    }
    elseif ($cfID = \CRM_Core_BAO_CustomField::getKeyID($field)) {
      $row->customToken($entity, $cfID, $activity->id);
    }
    elseif (preg_match('/^(target|assignee|source)_/', $field, $match)) {
      if ($match[1] == 'source') {
        $fieldParts = explode('_', $field, 2);
        $contactId = \CRM_Utils_Array::value($fieldParts[0], $prefetch['activityContact'][$activity->id]);
        $wantedField = $fieldParts[1];
      }
      else {
        $fieldParts = explode('_', $field, 3);
        $contactIds = \CRM_Utils_Array::value($fieldParts[0], $prefetch['activityContact'][$activity->id]);
        $selectedId = (int) $fieldParts[1] > 0 ? $fieldParts[1] - 1 : 0;
        $contactId = \CRM_Utils_Array::value($selectedId, $contactIds);
        $wantedField = $fieldParts[2];
      }
      $contact = \CRM_Utils_Array::value($contactId, $prefetch['contact']);
      if (!$contact) {
        $row->tokens($entity, $field, '');
      }
      else {
        $contact = (object) $contact;
        // This is OK for simple tokens, but would be better for this to be handled by
        // CRM_Contact_Tokens ... but that doesn't exist yet.
        $row->tokens($entity, $field, $contact->$wantedField);
      }
    }
    elseif (preg_match('/^(targets|assignees)_count/', $field, $match)) {
      $type = rtrim($match[1], 's');
      $contacts = \CRM_Utils_Array::value($type, $prefetch['activityContact'][$activity->id], []);
      $row->tokens($entity, $field, count($contacts));
    }
    else {
      $row->tokens($entity, $field, '');
    }
  }

}
