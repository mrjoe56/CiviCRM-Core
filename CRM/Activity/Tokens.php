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
 * This TokenSubscriber was originally produced by refactoring the code from the
 * scheduled-reminder system with the goal of making that system
 * more flexible. The current implementation is still coupled to
 * scheduled-reminders. It would be good to figure out a more generic
 * implementation which is not tied to scheduled reminders, although
 * that is outside the current scope.
 *
 * This has been enhanced to work with PDF/letter merge
 */
class CRM_Activity_Tokens extends \Civi\Token\AbstractTokenSubscriber {

  /**
   * CRM_Activity_Tokens constructor.
   */
  public function __construct() {
    parent::__construct('activity', array_merge(
      self::getBasicTokens(),
      self::getCustomFieldTokens(),
      self::getSpecialTokens()
    ));
  }

  /**
   * Get the basic tokens provided
   * @return array token name => token label
   */
  public function getBasicTokens() {
    if (!isset($this->basicTokens)) {
      $this->basicTokens = [
        'activity_id' => ts('Activity ID'),
        'activity_type' => ts('Activity Type'),
        'status' => ts('Activity Status'),
        'status_id' => ts('Activity Status ID'),
        'subject' => ts('Activity Subject'),
        'details' => ts('Activity Details'),
        'activity_date_time' => ts('Activity Date-Time'),
        'created_date' => ts('Activity Creation Date'),
        'duration' => ts('Activity Duration'),
        'campaign' => ts('Activity Campaign'),
        'campaign_id' => ts('Activity Campaign ID'),
        'targets_count' => ts('Count of Activity Targets'),
        'assignees_count' => ts('Count of Activity Assignees'),
      ];
      if (array_key_exists('CiviCase', CRM_Core_Component::getEnabledComponents())) {
        $this->basicTokens['case_id'] = ts('Activity Case ID');
      }
    }
    return $this->basicTokens;
  }

  /**
   * Get the tokens for custom fields
   * @return array token name => token label
   */
  public function getCustomFieldTokens() {
    if (!isset($this->customFieldTokens)) {
      $this->customFieldTokens = \CRM_Utils_Token::getCustomFieldTokens('Activity');
    }
    return $this->customFieldTokens;
  }

  /**
   * Get the special tokens - ie tokens that need special handling
   * @return array token name => token label
   */
  public function getSpecialTokens() {
    if (!isset($this->specialTokens)) {
      $this->specialTokens = [];
      foreach (\CRM_Core_SelectValues::contactTokens() as $label => $name) {
        $match = [];
        if (preg_match('/{contact\.(.*)}/', $label, $match)) {
          $this->specialTokens['source_' . $match[1]] = "(Source) " . $name;
          $this->specialTokens['target_N_' . $match[1]] = "(Target N) " . $name;
          $this->specialTokens['assignee_N_' . $match[1]] = "(Assignee N) " . $name;
        }
      }
    }
    return $this->specialTokens;
  }

  // Mapping from tokenName to api return field
  // Use lists since we might need multiple fields
  public static $fieldMapping = [
    'activity_id' => ['id'],
    'activity_type' => ['activity_type_id'],
    'status' => ['status_id'],
    'campaign' => ['campaign_id'],
  ];

  /**
   * @inheritDoc
   */
  public function checkActive(\Civi\Token\TokenProcessor $processor) {
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
        $activeTokens[] = $msgToken;
      }
      else {
        $altToken = preg_replace('/_\d+_/', '_N_', $msgToken);
        if (array_key_exists($altToken, $this->tokenNames)) {
          $activeTokens[] = $msgToken;
        }
      }
    }
    return array_unique($activeTokens);
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

    // $e->query->select('e.*'); // FIXME: seems too broad.
    // $e->query->select('ov.label as activity_type, e.id as activity_id');
    //
    // $e->query->join("og", "!casMailingJoinType civicrm_option_group og ON og.name = 'activity_type'");
    // $e->query->join("ov", "!casMailingJoinType civicrm_option_value ov ON e.activity_type_id = ov.value AND ov.option_group_id = og.id");

    // if CiviCase component is enabled, join for caseId.
    // $compInfo = CRM_Core_Component::getEnabledComponents();
    // if (array_key_exists('CiviCase', $compInfo)) {
    //   $e->query->select("civicrm_case_activity.case_id as case_id");
    //   $e->query->join('civicrm_case_activity', "LEFT JOIN `civicrm_case_activity` ON `e`.`id` = `civicrm_case_activity`.`activity_id`");
    // }
  }

  /**
   * Find the fields that we need to get to construct the tokens requested.
   * @param  array $tokens list of tokens
   * @return array         list of fields needed to generate those tokens
   */
  public function getReturnFields($tokens) {
    $fields = ['id']; // Make sure we always return something

    foreach (array_intersect($tokens,
      array_merge(array_keys(self::getBasicTokens()), array_keys(self::getCustomFieldTokens()))
      ) as $token) {
      if (isset(self::$fieldMapping[$token])) {
        $fields = array_merge($fields, self::$fieldMapping[$token]);
      }
      else {
        $fields[] = $token;
      }
    }
    return array_unique($fields);
  }

  /**
   * @inheritDoc
   */
  public function prefetch(\Civi\Token\Event\TokenValueEvent $e, $activeTokens) {
    // Find all the activity IDs
    $activityIds
      = $e->getTokenProcessor()->getContextValues('actionSearchResult', 'entityID')
      + $e->getTokenProcessor()->getContextValues('activityId');

    if (!$activityIds) {
      return;
    }

    // Get data on all activities for basic and customfield tokens
    $activities = civicrm_api3('Activity', 'get', [
      'id' => ['IN' => $activityIds],
      'options' => ['limit' => 0],
      'return' => self::getReturnFields($activeTokens),
    ]);
    $prefetch['activity'] = $activities['values'];

    // Get data for special tokens
    list($prefetch['activityContact'], $prefetch['contact'])
      = self::prefetchSpecialTokens($activeTokens, $activityIds);

    // Store the activity types if needed
    if (in_array('activity_type', $activeTokens)) {
      $this->activityTypes = \CRM_Core_OptionGroup::values('activity_type');
    }

    // Store the activity statuses if needed
    if (in_array('activity_status', $activeTokens)) {
      $this->activityStatuses = \CRM_Core_OptionGroup::values('activity_status');
    }

    // Store the campaigns if needed
    if (in_array('campaign', $activeTokens)) {
      $this->campaigns = \CRM_Campaign_BAO_Campaign::getCampaigns();
    }

    return $prefetch;
  }

  /**
   * Do the prefetch for the special tokens
   * @param  array $activeTokens The list of active tokens
   * @param  array $activityIds  list of activity ids
   * @return array               the prefetched data for these tokens
   */
  public function prefetchSpecialTokens($activeTokens, $activityIds) {
    $activityContacts = $contacts = [];
    // See if we need activity contacts
    $needContacts = FALSE;
    foreach ($activeTokens as $token) {
      if (preg_match('/^source|target|assignee/', $token)) {
        $needContacts = TRUE;
        break;
      }
    }

    // If we need ActivityContacts, load them
    if ($needContacts) {
      $result = civicrm_api3('ActivityContact', 'get', [
        'sequential' => 1,
        'activity_id' => ['IN' => $activityIds],
        'options' => ['limit' => 0],
      ]);
      $contactIds = [];
      $types = ['1' => 'assignee', '2' => 'source', '3' => 'target'];
      foreach ($result['values'] as $ac) {
        if ($ac['record_type_id'] == 2) {
          $activityContacts[$ac['activity_id']][$types[$ac['record_type_id']]] = $ac['contact_id'];
        }
        else {
          $activityContacts[$ac['activity_id']][$types[$ac['record_type_id']]][] = $ac['contact_id'];
        }
        $contactIds[$ac['contact_id']] = 1;
      }
      // @TODO only return the wanted fields
      // maybe use CRM_Contact_Tokens::prefetch() ?
      $result = civicrm_api3('Contact', 'get', [
        'id' => ['IN' => array_keys($contactIds)],
        'options' => ['limit' => 0],
      ]);
      $contacts = $result['values'];
    }
    return [$activityContacts, $contacts];
  }

  /**
   * @inheritDoc
   */
  public function evaluateToken(\Civi\Token\TokenRow $row, $entity, $field, $prefetch = NULL) {
    // maps token name to api field
    $mapping = [
      'activity_id' => 'id',
    ];

    // Get ActivityID either from actionSearchResult (for scheduled reminders) if exists
    $activityId = isset($row->context['actionSearchResult']->entityID)
      ? $row->context['actionSearchResult']->entityID
      : $row->context['activityId'];

    $activity = (object) $prefetch['activity'][$activityId];

    if (in_array($field, ['activity_date_time', 'created_date'])) {
      $row->tokens($entity, $field, \CRM_Utils_Date::customFormat($activity->$field));
    }
    elseif (isset($mapping[$field]) AND (isset($activity->{$mapping[$field]}))) {
      $row->tokens($entity, $field, $activity->{$mapping[$field]});
    }
    elseif (in_array($field, ['activity_type'])) {
      $row->tokens($entity, $field, $this->activityTypes[$activity->activity_type_id]);
    }
    elseif (in_array($field, ['activity_status'])) {
      $row->tokens($entity, $field, $this->activityStatuses[$activity->activity_status_id]);
    }
    elseif (in_array($field, ['campaign'])) {
      $row->tokens($entity, $field, $this->campaigns[$activity->campaign_id]);
    }
    elseif (array_key_exists($field, $this->customFieldTokens)) {
      $row->tokens($entity, $field, \CRM_Core_BAO_CustomField::displayValue($activity->$field, $field));
    }
    elseif (isset($activity->$field)) {
      $row->tokens($entity, $field, $activity->$field);
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
      ;
      $row->tokens($entity, $field, count(
        \CRM_Utils_Array::value($type, $prefetch['activityContact'][$activity->id], [])
      ));
    }
  }

}
