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

use Civi\Token\TokenProcessor;

/**
 * This class provides the common functionality for creating PDF letter for
 * activities.
 *
 */
class CRM_Activity_Form_Task_PDFLetterCommon extends CRM_Contact_Form_Task_PDFLetterCommon {

  public static function postProcess(&$form) {
    self::postProcessActivities($form, $form->_activityHolderIds);
  }

  /**
   * Process the form after the input has been submitted and validated.
   * This uses the new token processor
   *
   * @param CRM_Core_Form $form
   * @param $activityIds
   *
   * @return void
   */
  public static function postProcessActivities(&$form, $activityIds) {
    $formValues = $form->controller->exportValues($form->getName());
    list($formValues, $categories, $html_message, $messageToken, $returnProperties)
      = self::processMessageTemplate($formValues);

    $tp = new TokenProcessor(\Civi::dispatcher(), array(
      'controller' => __CLASS__,
      'smarty' => FALSE,
    ));
    $tp->addMessage('body_html', $html_message, 'text/html');

    $activities = civicrm_api3('Activity', 'get', array(
      'id' => array('IN' => $activityIds),
    ));

    foreach ($activityIds as $activityId) {
      $activity = CRM_Utils_Array::value($activityId, $activities['values']);
      if ($activity) {
        // NB - must have 'contactId' context otherwise evaluate() blows up
        $tp->addRow()
          ->context('activityId', $activityId)
          ->context('actionSearchResult', (object) $activity)
          ->context('contactId', $activity['source_contact_id']);
      }
    }
    $tp->evaluate();
    foreach ($tp->getRows() as $row) {
      $html[] = $row->render('body_html');
    }

    // If we were to record a PDF activity, who would we record it against??
    // probably Target
    // self::createActivities($form, $html_message, $contactIDs, $formValues['subject'], CRM_Utils_Array::value('campaign_id', $formValues));

    if (!empty($formValues['is_unit_test'])) {
      return $html;
    }

    if (!empty($html)) {
      $type = $formValues['document_type'];

      if ($type == 'pdf') {
        CRM_Utils_PDF_Utils::html2pdf($html, "CiviLetter.pdf", FALSE, $formValues);
      }
      else {
        CRM_Utils_PDF_Document::html2doc($html, "CiviLetter.$type", $formValues);
      }
    }

    $form->postProcessHook();

    CRM_Utils_System::civiExit(1);
  }

  public function listTokens() {
    $tp = new TokenProcessor(\Civi::dispatcher(), array(
      'controller' => __CLASS__,
      'smarty' => FALSE,
    ));
    $list = array();
    foreach ($tp->getTokens() as $token => $values) {
      $list['{' . $token . '}'] = $values['label'];
    }
    return $list;
  }

}
