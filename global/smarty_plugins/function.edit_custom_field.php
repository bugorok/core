<?php

use FormTools\General;
use FormTools\OptionLists;
use FormTools\Settings;
use FormTools\Submissions;
use FormTools\Templates;


/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     function.edit_custom_field
 * Type:     function
 * Name:     edit_custom_field
 * Purpose:  This is used on the Edit Submission pages. It does all the clever stuff needed to generate the
 *           actual markup for a single field, with whatever user-defined settings have been employed.
 *
 *           It's strongly coupled to the ViewFields::getGroupedViewFields function (when called with the form ID &
 *           submission ID params) to ensure that all data is efficiently returned for use by this function.
 * -------------------------------------------------------------
 */
function smarty_function_edit_custom_field($params, &$smarty)
{
    global $g_root_url, $g_root_dir, $g_multi_val_delimiter;

    if (!Templates::hasRequiredParams($smarty, $params, array("form_id", "field_info", "field_types", "settings"))) {
        return;
    }

    $form_id     = $params["form_id"];
    $field_info  = $params["field_info"];
    $field_types = $params["field_types"];
    $settings    = $params["settings"];

    $submission_id = isset($params["submission_id"]) ? $params["submission_id"] : "";


  // loop through the field types and store the one we're interested in in $field_type_info
  $field_type_info = array();
  foreach ($field_types as $curr_field_type)
  {
    if ($field_info["field_type_id"] == $curr_field_type["field_type_id"])
    {
      $field_type_info = $curr_field_type;
      break;
    }
  }

  if ($field_info["is_editable"] == "no")
  {
    $markup_with_placeholders = trim($field_type_info["view_field_smarty_markup"]);

    if (empty($markup_with_placeholders))
    {
      echo $field_info["submission_info"]["value"];
      return;
    }
  }
  else
  {
    $markup_with_placeholders = $field_type_info["edit_field_smarty_markup"];
  }

  // now construct all available placeholders
  $placeholders = array(
    "FORM_ID"       => $form_id,
    "VIEW_ID"       => $field_info["view_id"],
    "SUBMISSION_ID" => $submission_id,
    "FIELD_ID"      => $field_info["field_id"],
    "NAME"          => $field_info["field_name"],
    "COLNAME"       => $field_info["col_name"],
    "VALUE"         => isset($field_info["submission_value"]) ? $field_info["submission_value"] : "",
    "SETTINGS"      => $settings,
    "CONTEXTPAGE"   => "edit_submission",
    "ACCOUNT_INFO"  => isset($_SESSION["ft"]["account"]) ? $_SESSION["ft"]["account"] : array(),
    "g_root_url"    => $g_root_url,
    "g_root_dir"    => $g_root_dir,
    "g_multi_val_delimiter" => $g_multi_val_delimiter
  );

  // add in all field type settings and their replacements
  foreach ($field_type_info["settings"] as $setting_info)
  {
    $curr_setting_id         = $setting_info["setting_id"];
    $curr_setting_field_type = $setting_info["field_type"];
    $default_value_type      = $setting_info["default_value_type"];
    $value                   = $setting_info["default_value"];
    $identifier              = $setting_info["field_setting_identifier"];

    foreach ($field_info["field_settings"] as $setting)
    {
      $found = false;
      while (list($setting_id, $setting_value) = each($setting))
      {
        if ($setting_id == $curr_setting_id)
        {
          $value = $setting_value;
          break;
        }
      }
      reset($setting);
      if ($found)
        break;
    }

    // next, if the setting is dynamic, convert the stored value
    if ($default_value_type == "dynamic")
    {
      // dynamic setting values should ALWAYS be of the form "setting_name,module_folder/'core'". If they're not, just ignore it
      $parts = explode(",", $value);
      if (count($parts) == 2)
      {
        $value = Settings::get($parts[0], $parts[1]);
      }
    }

    // if this setting type is a dropdown list and $value is non-empty, get the list of options
    if ($curr_setting_field_type == "option_list_or_form_field" && !empty($value))
    {
      if (preg_match("/^form_field/", $value))
      {
        $value = Submissions::getMappedFormFieldData($value);
      }
      else
      {
        $value = OptionLists::getOptionList($value);
      }
    }

    $placeholders[$identifier] = $value;
  }

  echo General::evalSmartyString($markup_with_placeholders, $placeholders);
}
