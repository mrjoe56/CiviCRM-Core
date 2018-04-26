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
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2018
 */

/**
 * This is the base class for common PDF/Doc Merge functionality.
 * Most CRM_*_Form_Task_PDFLetterCommon classes extend the Contact version
 * but the assumptions there are not always appropriate for other classes
 * resulting in code duplication and unexpected dependencies.
 * The intention is that common functionality can be moved here and the other
 * classes cleaned up.
 */
class CRM_Core_Form_Task_PDFLetterCommon {

  /**
   * Render html from rows
   * @param  array $rows   Array of \Civi\Token\TokenRow
   * @param  string $msgPart The name registered with the TokenProcessor
   * @return string $html  if formValues['is_unit_test'] is true,
   *                       otherwise outputs document to browser
   *
   */
  public function renderFromRows($rows, $msgPart, $formValues) {
    $html = array();
    foreach ($rows as $row) {
      $html[] = $row->render($msgPart);
    }

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
  }

}
