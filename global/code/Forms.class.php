<?php

/**
 * Forms.
 */

// -------------------------------------------------------------------------------------------------

namespace FormTools;


use PDOException;


class Forms
{
    /**
     * Returns all forms. Should only be called by adminstrators.
     * @return array
     */
    public static function getForms()
    {
        return self::searchForms(array("is_admin" => true));
    }


    /**
     * This function processes the form submissions, after the form has been set up in the database.
     */
    public static function processForm($form_data)
    {
        $db = Core::$db;
        $LANG = Core::$L;
        $multi_val_delimiter = Core::getMultiFieldValDelimiter();
        $query_str_delimiter = Core::getQueryStrMultiValSeparator();

        // $g_multi_val_delimiter, $g_query_str_multi_val_separator,
        global $g_api_version, $g_api_recaptcha_private_key;

        // ensure the incoming values are escaped
        $form_id = $form_data["form_tools_form_id"];
        $form_info = Forms::getForm($form_id);

        // do we have a form for this id?
        if (!self::checkFormExists($form_id)) {
            $page_vars = array("message_type" => "error", "message" => $LANG["processing_invalid_form_id"]);
            Themes::displayPage("error.tpl", $page_vars);
            exit;
        }

        extract(Hooks::processHookCalls("start", compact("form_info", "form_id", "form_data"), array("form_data")), EXTR_OVERWRITE);

        // check to see if this form has been completely set up
        if ($form_info["is_complete"] == "no") {
            $page_vars = array("message_type" => "error", "message" => $LANG["processing_form_incomplete"]);
            Themes::displayPage("error.tpl", $page_vars);
            exit;
        }

        // check to see if this form has been disabled
        if ($form_info["is_active"] == "no") {
            if (isset($form_data["form_tools_inactive_form_redirect_url"])) {
                header("location: {$form_data["form_tools_inactive_form_redirect_url"]}");
                exit;
            }
            $page_vars = array("message_type" => "error", "message" => $LANG["processing_form_disabled"]);
            Themes::displayPage("error.tpl", $page_vars);
            exit;
        }

        // do we have a form for this id?
        if (!self::checkFormExists($form_id)) {
            $page_vars = array("message_type" => "error", "message" => $LANG["processing_invalid_form_id"]);
            Themes::displayPage("error.tpl", $page_vars);
            exit;
        }

        // was there a reCAPTCHA response? If so, a recaptcha was just submitted. This generally implies the
        // form page included the API, so check it was entered correctly. If not, return the user to the webpage
        if (isset($g_api_version) && isset($form_data["recaptcha_response_field"])) {
            $passes_captcha = false;
            $recaptcha_challenge_field = $form_data["recaptcha_challenge_field"];
            $recaptcha_response_field  = $form_data["recaptcha_response_field"];

            require_once(__DIR__ . "/global/api/recaptchalib.php");

            $resp = recaptcha_check_answer($g_api_recaptcha_private_key, $_SERVER["REMOTE_ADDR"], $recaptcha_challenge_field, $recaptcha_response_field);

            if ($resp->is_valid) {
                $passes_captcha = true;
            } else {
                // since we need to pass all the info back to the form page we do it by storing the data in sessions. Enable 'em.
                //@ft_api_start_sessions();
                $_SESSION["form_tools_form_data"] = $form_data;
                $_SESSION["form_tools_form_data"]["api_recaptcha_error"] = $resp->error;

                // if there's a form_tools_form_url specified, redirect to that
                if (isset($form_data["form_tools_form_url"])) {
                    header("location: {$form_data["form_tools_form_url"]}");
                    exit;

                    // if not, see if the server has the redirect URL specified
                } else if (isset($_SERVER["HTTP_REFERER"])) {
                    header("location: {$_SERVER["HTTP_REFERER"]}");
                    exit;

                    // no luck! Throw an error
                } else {
                    $page_vars = array("message_type" => "error", "message" => $LANG["processing_no_form_url_for_recaptcha"]);
                    Themes::displayPage("error.tpl", $page_vars);
                    exit;
                }
            }
        }

        // get a list of the custom form fields (i.e. non-system) for this form
        $form_fields = Fields::getFormFields($form_id, array("include_field_type_info" => true));

        $custom_form_fields = array();
        $file_fields = array();
        foreach ($form_fields as $field_info) {
            $field_id        = $field_info["field_id"];
            $is_system_field = $field_info["is_system_field"];
            $field_name      = $field_info["field_name"];

            // ignore system fields
            if ($is_system_field == "yes") {
                continue;
            }

            if ($field_info["is_file_field"] == "no") {
                $custom_form_fields[$field_name] = array(
                "field_id"    => $field_id,
                "col_name"    => $field_info["col_name"],
                "field_title" => $field_info["field_title"],
                "include_on_redirect" => $field_info["include_on_redirect"],
                "field_type_id" => $field_info["field_type_id"],
                "is_date_field" => $field_info["is_date_field"]
                );
            } else {
                $file_fields[] = array(
                "field_id"   => $field_id,
                "field_info" => $field_info
                );
            }
        }

        // now examine the contents of the POST/GET submission and get a list of those fields
        // which we're going to update
        $valid_form_fields = array();
        while (list($form_field, $value) = each($form_data)) {
            // if this field is included, store the value for adding to DB
            if (array_key_exists($form_field, $custom_form_fields)) {
                $curr_form_field = $custom_form_fields[$form_field];

                $cleaned_value = $value;
                if (is_array($value)) {
                    if ($form_info["submission_strip_tags"] == "yes") {
                        for ($i=0; $i<count($value); $i++)
                            $value[$i] = strip_tags($value[$i]);
                    }

                    $cleaned_value = implode("$g_multi_val_delimiter", $value);
                } else {
                    if ($form_info["submission_strip_tags"] == "yes")
                        $cleaned_value = strip_tags($value);
                }

                $valid_form_fields[$curr_form_field["col_name"]] = "'$cleaned_value'";
            }
        }

        $now = General::getCurrentDatetime();
        $ip_address = $_SERVER["REMOTE_ADDR"];

        $col_names = array_keys($valid_form_fields);
        $col_names_str = join(", ", $col_names);
        if (!empty($col_names_str)) {
            $col_names_str .= ", ";
        }

        $col_values = array_values($valid_form_fields);
        $col_values_str = join(", ", $col_values);
        if (!empty($col_values_str)) {
            $col_values_str .= ", ";
        }

        // build our query
        $query = "
            INSERT INTO {PREFIX}form_$form_id ($col_names_str submission_date, last_modified_date, ip_address, is_finalized)
            VALUES ($col_values_str '$now', '$now', '$ip_address', 'yes')
        ";

        // add the submission to the database (if form_tools_ignore_submission key isn't set by either the form or a module)
        $submission_id = "";
        if (!isset($form_data["form_tools_ignore_submission"])) {
            try {
                $db->query($query);
            } catch (PDOException $e) {

//                Errors::showErrorCode();

                // TODO change to queryError. No more "system" errors

                $page_vars = array(
                    "message_type" => "error",
                    "error_code" => 304,
                    "error_type" => "system",
                    "debugging"=> "Failed query in <b>" . __CLASS__ . ", " . __FILE__ . "</b>, line " . __LINE__ .
                    ": <i>" . nl2br($query) . "</i>", mysql_error()
                );
                Themes::displayPage("error.tpl", $page_vars);
                exit;

            }

            $submission_id = mysql_insert_id();
            extract(Hooks::processHookCalls("end", compact("form_id", "submission_id"), array()), EXTR_OVERWRITE);
        }


        $redirect_query_params = array();

        // build the redirect query parameter array
        foreach ($form_fields as $field_info) {
            if ($field_info["include_on_redirect"] == "no" || $field_info["is_file_field"] == "yes") {
                continue;
            }

            switch ($field_info["col_name"]) {
                case "submission_id":
                    $redirect_query_params[] = "submission_id=$submission_id";
                    break;
                case "submission_date":
                    $settings = Settings::get();
                    $submission_date_formatted = General::getDate($settings["default_timezone_offset"], $now, $settings["default_date_format"]);
                    $redirect_query_params[] = "submission_date=" . rawurlencode($submission_date_formatted);
                    break;
                case "last_modified_date":
                    $settings = Settings::get();
                    $submission_date_formatted = General::getDate($settings["default_timezone_offset"], $now, $settings["default_date_format"]);
                    $redirect_query_params[] = "last_modified_date=" . rawurlencode($submission_date_formatted);
                    break;
                case "ip_address":
                    $redirect_query_params[] = "ip_address=$ip_address";
                    break;

                default:
                    $field_name = $field_info["field_name"];

                    // if $value is an array, convert it to a string, separated by $g_query_str_multi_val_separator
                    if (isset($form_data[$field_name])) {
                        if (is_array($form_data[$field_name])) {
                            $value_str = join($g_query_str_multi_val_separator, $form_data[$field_name]);
                            $redirect_query_params[] = "$field_name=" . rawurlencode($value_str);
                        } else {
                            $redirect_query_params[] = "$field_name=" . rawurlencode($form_data[$field_name]);
                        }
                    }
                    break;
            }
        }

        // only upload files & send emails if we're not ignoring the submission
        if (!isset($form_data["form_tools_ignore_submission"])) {
            // now process any file fields. This is placed after the redirect query param code block above to allow whatever file upload
            // module to append the filename to the query string, if needed
            extract(Hooks::processHookCalls("manage_files", compact("form_id", "submission_id", "file_fields", "redirect_query_params"), array("success", "message", "redirect_query_params")), EXTR_OVERWRITE);

            // send any emails
            Emails::sendEmails("on_submission", $form_id, $submission_id);
        }

        // if the redirect URL has been specified either in the database or as part of the form
        // submission, redirect the user [form submission form_tools_redirect_url value overrides
        // database value]
        if (!empty($form_info["redirect_url"]) || !empty($form_data["form_tools_redirect_url"])) {
            // build redirect query string
            $redirect_url = (isset($form_data["form_tools_redirect_url"]) && !empty($form_data["form_tools_redirect_url"]))
                ? $form_data["form_tools_redirect_url"] : $form_info["redirect_url"];

            $query_str = "";
            if (!empty($redirect_query_params)) {
                $query_str = join("&", $redirect_query_params);
            }

            if (!empty($query_str)) {
                // only include the ? if it's not already there
                if (strpos($redirect_url, "?")) {
                    $redirect_url .= "&" . $query_str;
                } else {
                    $redirect_url .= "?" . $query_str;
                }
            }

            General::redirect($redirect_url);
        }

        // the user should never get here! This means that the no redirect URL has been specified
        $page_vars = array("message_type" => "error", "message" => $LANG["processing_no_redirect_url"]);
        Themes::displayPage("error.tpl", $page_vars);
        exit;
    }


    /**
     * Caches the total number of (finalized) submissions in a particular form - or all forms - in the
     * $_SESSION["ft"]["form_{$form_id}_num_submissions"] key. That value is used on the administrator's main Forms
     * page to list the form submission count.
     *
     * @param integer $form_id
     */
    public static function cacheFormStats($form_id = "")
    {
        $db = Core::$db;

        $where_clause = "";
        if (!empty($form_id)) {
            $where_clause = "AND form_id = :form_id";
        }

        $db->query("
            SELECT form_id
            FROM   {PREFIX}forms
            WHERE  is_complete = 'yes'
            $where_clause
        ");
        if (!empty($form_id)) {
            $db->bind("form_id", $form_id);
        }
        $db->execute();

        // loop through all forms, extract the submission count and first submission date
        foreach ($db->fetchAll() as $form_info) {
            $form_id = $form_info["form_id"];

            try {
                $db->query("
                    SELECT count(*) as c
                    FROM   {PREFIX}form_$form_id
                    WHERE  is_finalized = 'yes'
                ");
                $db->execute();
                $info = $db->fetch();
                Sessions::set("form_{$form_id}_num_submissions", $info["c"]);
            } catch (PDOException $e) {

                // need a softer error here. If the form table doesn't exist, we need to log the issue.
//                Errors::queryError(__CLASS__, __FILE__, __LINE__, $e->getMessage());
//                exit;
            }
        }
    }


    /**
     * Retrieves information about all forms associated with a particular account. Since 2.0.0 this function lets you
     * SEARCH the forms, but it still returns all results - not a page worth (the reason being: the vast majority of
     * people use Form Tools for a small number of forms < 100) so the form tables are displaying via JS, with all
     * results actually returned and hidden in the page ready to be displayed.
     *
     * @param array $search_criteria an optional hash with any of the following keys:
     *                 "account_id" - if blank, return all finalized forms, otherwise returns the forms associated with
     *                                this particular client.
     *                 "is_admin"   - (boolean) whether or not the user retrieving the data is an administrator or not.
     *                                If it is, ALL forms are retrieved - even those that aren't yet finalized.
     *                 "status"     - (string) online / offline
     *                 "keyword"    - (any string)
     *                 "order"      - (string) form_id-DESC, form_id-ASC, form_name-DESC, form-name-ASC,
     *                                status-DESC, status-ASC
     * @return array returns an array of form hashes
     */
    public static function searchForms($params = array())
    {
        $db = Core::$db;

        $search_criteria = array_merge(array(
            "account_id" => "",
            "is_admin"   => false,
            "status"     => "online",
            "keyword"    => "",
            "order"      => "form_id-DESC"
        ), $params);

        extract(Hooks::processHookCalls("start", compact("account_id", "is_admin", "search_criteria"), array("search_criteria")), EXTR_OVERWRITE);

        $results = self::getSearchFormSqlClauses($search_criteria);

        // get the form IDs. All info about the forms will be retrieved in a separate query
        $db->query("
            SELECT form_id
            FROM   {PREFIX}forms
            {$results["where_clause"]}
            {$results["order_clause"]}
        ");
        $db->execute();

        // now retrieve the basic info (id, first and last name) about each client assigned to this form. This
        // takes into account whether it's a public form or not and if so, what clients are in the omit list
        $omitted_forms = $results["omitted_forms"];
        $form_info = array();
        foreach ($db->fetchAll() as $row) {
            $form_id = $row["form_id"];

            // if this was a search for a single client, filter out those public forms which include their account ID
            // on the form omit list
            if (!empty($omitted_forms) && in_array($form_id, $omitted_forms)) {
                continue;
            }
            $form_info[] = Forms::getForm($form_id);
        }

        extract(Hooks::processHookCalls("end", compact("account_id", "is_admin", "search_criteria", "form_info"), array("form_info")), EXTR_OVERWRITE);

        return $form_info;
    }


    /**
     * Retrieves all information about single form; all associated client information is stored in the client_info key,
     * as an array of hashes. Note: this function returns information about any form - complete or incomplete.
     *
     * @param integer $form_id the unique form ID
     * @return array a hash of form information. If the form isn't found, it returns an empty array
     */
    public static function getForm($form_id)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
        $form_info = $db->fetch();

        if (empty($form_info)) {
            return array();
        }

        $form_info["client_info"] = Forms::getFormClients($form_id);
        $form_info["client_omit_list"] = ($form_info["access_type"] == "public") ? OmitLists::getPublicFormOmitList($form_id) : array();

        $db->query("SELECT * FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id ORDER BY page_num");
        $form_info["multi_page_form_urls"] = array();
        $db->bind("form_id", $form_id);
        $db->execute();

        foreach ($db->fetchAll() as $row) {
            $form_info["multi_page_form_urls"][] = $row;
        }

        extract(Hooks::processHookCalls("end", compact("form_id", "form_info"), array("form_info")), EXTR_OVERWRITE);

        return $form_info;
    }


    /**
     * Returns an array of account information of all clients associated with a particular form. This
     * function is smart enough to return the complete list, depending on whether the form has public access
     * or not. If it's a public access form, it takes into account those clients on the form omit list.
     *
     * @param integer $form
     * @return array
     */
    public static function getFormClients($form_id)
    {
        $db = Core::$db;

        $db->query("SELECT access_type FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        $access_type_info = $db->fetch();
        $access_type = $access_type_info["access_type"];

        $accounts = array();
        if ($access_type == "public") {
            $client_omit_list = OmitLists::getPublicFormOmitList($form_id);
            $all_clients = Clients::getList();

            foreach ($all_clients as $client_info) {
                $client_id = $client_info["account_id"];
                if (!in_array($client_id, $client_omit_list)) {
                    $accounts[] = $client_info;
                }
            }
        } else  {
            $db->query("
                SELECT *
                FROM   {PREFIX}client_forms cf, {PREFIX}accounts a
                WHERE  cf.form_id = :form_id AND
                       cf.account_id = a.account_id
            ");
            $db->bind("form_id", $form_id);
            $db->execute();

            foreach ($db->fetchAll() as $row) {
                $accounts[] = $row;
            }
        }

        extract(Hooks::processHookCalls("end", compact("form_id", "accounts"), array("accounts")), EXTR_OVERWRITE);

        return $accounts;
    }


    /**
     * Added in 2.1.0, this creates an Internal form with a handful of custom settings.
     *
     * @param $request array the POST request containing the form name, number of fields and access type.
     */
    public static function createInternalForm($request)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        $rules = array();
        $rules[] = "required,form_name,{$LANG["validation_no_form_name"]}";
        $rules[] = "required,num_fields,{$LANG["validation_no_num_form_fields"]}";
        $rules[] = "digits_only,num_fields,{$LANG["validation_invalid_num_form_fields"]}";
        $rules[] = "required,access_type,{$LANG["validation_no_access_type"]}";

        $errors = validate_fields($request, $rules);
        if (!empty($errors)) {
            return array(false, General::getErrorListHTML($errors));
        }

        $info = $request;
        $config = array(
            "form_type"    => "internal",
            "form_name"    => $info["form_name"],
            "access_type"  => $info["access_type"]
        );

        // set up the entry for the form
        list($success, $message, $new_form_id) = Forms::setupForm($config);

        $form_data = array(
            "form_tools_form_id" => $new_form_id,
            "form_tools_display_notification_page" => false
        );

        for ($i=1; $i<=$info["num_fields"]; $i++) {
            $form_data["field{$i}"] = $i;
        }
        self::initializeForm($form_data);

        $form_fields = Fields::getFormFields($new_form_id);

        $order = 1;

        // if the user just added a form with a lot of fields (over 50), the database row size will be too
        // great. Varchar fields (which with utf-8 equates to 1220 bytes) in a table can have a combined row
        // size of 65,535 bytes, so 53 is the max. The client-side validation limits the number of fields to
        // 1000. Any more will throw an error.
        $field_size_clause = ($info["num_fields"] > 50) ? ", field_size = 'small'" : "";

        $field_name_prefix = $LANG["word_field"];
        foreach ($form_fields as $field_info) {
            if (preg_match("/field(\d+)/", $field_info["field_name"], $matches)) {
                $field_id  = $field_info["field_id"];
                $db->query("
                    UPDATE {PREFIX}form_fields
                    SET    field_title = :field_title,
                           col_name = :col_name
                           $field_size_clause
                    WHERE  field_id = $field_id
                ");
                $db->bindAll(array(
                    "field_title" => "$field_name_prefix $order",
                    "col_name" => "col_$order"
                ));
                $db->execute();
                $order++;
            }
        }

        Forms::finalizeForm($new_form_id);

        // if the form has an access type of "private" add whatever client accounts the user selected
        if ($info["access_type"] == "private") {
            $selected_client_ids = $info["selected_client_ids"];
            $queries = array();
            foreach ($selected_client_ids as $client_id) {
                $queries[] = "($client_id, $new_form_id)";
            }

            if (!empty($queries)) {
                $insert_values = implode(",", $queries);
                $db->query("
                    INSERT INTO {PREFIX}client_forms (account_id, form_id)
                    VALUES $insert_values
                ");
            }
        }

        return array(true, $LANG["notify_internal_form_created"], $new_form_id);
    }


    public static function setSubmissionType($form_id, $submission_type) {
        if (empty($form_id) || empty($submission_type)) {
            return;
        }
        $db = Core::$db;
        $db->query("
            UPDATE {PREFIX}forms 
            SET submission_type = :submission_type 
            WHERE form_id = :form_id
        ");
        $db->bindAll(array(
            "form_id" => $form_id,
            "submission_type" => $submission_type
        ));
        $db->execute();
    }


    /**
     * Used on the Add External form process. Returns appropriate values to show in step 2 based on whether the user
     * just arrived, just updated the values or is returning to finish configuring a new form from earlier.
     */
    public static function addFormGetExternalFormValues($source, $form_id = "", $post = array()) {
        $page_values = array();
        $page_values["client_info"] = array();

        switch ($source) {
            case "new_form":
                $page_values["form_name"] = "";
                $page_values["form_url"] = "";
                $page_values["is_multi_page_form"] = "no";
                $page_values["multi_page_form_urls"] = array();
                $page_values["redirect_url"] = "";
                $page_values["access_type"]  = "admin";
                $page_values["hidden_fields"] = "<input type=\"hidden\" name=\"add_form\" value=\"1\" />";
                break;

            case "post":
                $page_values["form_name"]    = $post["form_name"];
                $page_values["form_url"]     = $post["form_url"];
                $page_values["is_multi_page_form"] = isset($post["is_multi_page_form"]) ? "yes" : "no";
                $page_values["redirect_url"] = $post["redirect_url"];
                $page_values["access_type"]  = $post["access_type"];

                if (!empty($form_id)) {
                    $page_values["hidden_fields"] = "
          <input type=\"hidden\" name=\"update_form\" value=\"1\" />
          <input type=\"hidden\" name=\"form_id\" value=\"$form_id\" />";
                } else {
                    $page_values["hidden_fields"] = "<input type=\"hidden\" name=\"add_form\" value=\"1\" />";
                }
                break;

            case "database":
                if (empty($form_id)) {
                    return array();
                }

                $form_info = Forms::getForm($form_id);
                $page_values["form_name"]    = $form_info["form_name"];
                $page_values["form_url"]     = $form_info["form_url"];
                $page_values["is_multi_page_form"] = $form_info["is_multi_page_form"];
                $page_values["multi_page_form_urls"]  = $form_info["multi_page_form_urls"];
                $page_values["redirect_url"] = $form_info["redirect_url"];
                $page_values["access_type"]  = $form_info["access_type"];
                $page_values["client_info"]  = $form_info["client_info"];

                $page_values["hidden_fields"] = "
        <input type=\"hidden\" name=\"update_form\" value=\"1\" />
        <input type=\"hidden\" name=\"form_id\" value=\"$form_id\" />";
                break;
        }

        return $page_values;
    }


    /**
     * This function sets up the main form values in preparation for a test submission by the actual form. It is
     * called from step 2 of the form creation page for totally new forms.
     *
     * @param array $info this parameter should be a hash (e.g. $_POST or $_GET) containing the various fields from
     *                the step 1 add form page.
     * @return array Returns array with indexes:<br/>
     *               [0]: true/false (success / failure)<br/>
     *               [1]: message string<br/>
     *               [2]: new form ID (success only)
     */
    public static function setupForm($info)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        $success = true;
        $message = "";

        // check required $info fields. This changes depending on the form type (external / internal). Validation
        // for the internal forms is handled separately [inelegant!]
        $rules = array();
        if ($info["form_type"] == "external") {
            $rules[] = "required,form_name,{$LANG["validation_no_form_name"]}";
            $rules[] = "required,access_type,{$LANG["validation_no_access_type"]}";
        }
        $errors = validate_fields($info, $rules);

        // if there are errors, piece together an error message string and return it
        if (!empty($errors)) {
            return array(false, General::getErrorListHTML($errors));
        }

        $access_type     = $info["access_type"];
        $submission_type = (isset($info["submission_type"])) ? "'{$info["submission_type"]}'" : "NULL";
        $user_ids        = isset($info["selected_client_ids"]) ? $info["selected_client_ids"] : array();
        $form_name       = trim($info["form_name"]);
        $is_multi_page_form = isset($info["is_multi_page_form"]) ? $info["is_multi_page_form"] : "no";
        $redirect_url       = isset($info["redirect_url"]) ? trim($info["redirect_url"]) : "";
        $phrase_edit_submission = $LANG["phrase_edit_submission"];

        if ($is_multi_page_form == "yes") {
            $form_url = $info["multi_page_urls"][0];
        } else {
            // this won't be defined for Internal forms
            $form_url = isset($info["form_url"]) ? $info["form_url"] : "";
        }

        $now = General::getCurrentDatetime();

        $db->query("
            INSERT INTO {PREFIX}forms (form_type, access_type, submission_type, date_created, is_active, is_complete,
              is_multi_page_form, form_name, form_url, redirect_url, edit_submission_page_label)
            VALUES (:form_type, :access_type, :submission_type, :now, :is_active, :is_complete, :is_multi_page_form,
              :form_name, :form_url, :redirect_url, :phrase_edit_submission)
        ");
        $db->bindAll(array(
            "form_type" => $info["form_type"],
            "access_type" => $access_type,
            "submission_type" => $submission_type,
            "now" => $now,
            "is_active" => "no",
            "is_complete" => "no",
            "is_multi_page_form" => $is_multi_page_form,
            "form_name" => $form_name,
            "form_url" => $form_url,
            "redirect_url" => $redirect_url,
            "phrase_edit_submission" => $phrase_edit_submission
        ));
        $db->execute();

        $new_form_id = $db->getInsertId();

        // store which clients are assigned to this form
        self::setFormClients($new_form_id, $user_ids);

        // if this is a multi-page form, add the list of pages in the form
        self::setMultiPageUrls($new_form_id, $is_multi_page_form === "yes" ? $info["multi_page_urls"] : array());

        return array($success, $message, $new_form_id);
    }


    public static function setFormClients($form_id, $client_ids)
    {
        $db = Core::$db;

        // remove any old mappings
        Forms::deleteClientForms($form_id);

        // add the new clients (assuming there are any)
        foreach ($client_ids as $client_id) {
            $db->query("
                INSERT INTO {PREFIX}client_forms (account_id, form_id)
                VALUES (:client_id, :form_id)
            ");
            $db->bindAll(array(
                "account_id" => $client_id,
                "form_id" => $form_id
            ));
            $db->execute();
        }
    }

    public static function setMultiPageUrls($form_id, $urls)
    {
        $db = Core::$db;
        $db->query("DELETE FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        $db->beginTransaction();
        $page_num = 1;
        foreach ($urls as $url) {
            if (empty($url)) {
                continue;
            }
            $db->query("
                INSERT INTO {PREFIX}multi_page_form_urls (form_id, form_url, page_num)
                VALUES (:form_id, :url, :page_num)
            ");
            $db->bindAll(array(
                "form_id" => $form_id,
                "url" => $url,
                "page_num" => $page_num
            ));
            $page_num++;
        }
        $db->processTransaction();
    }


    /**
     * "Uninitializes" a form, letting the user to resend the test submission.
     * @param integer $form_id The unique form ID
     */
    public static function uninitializeForm($form_id)
    {
        Core::$db->query("
            UPDATE  {PREFIX}forms
            SET     is_initialized = 'no'
            WHERE   form_id = :form_id
        ");
        Core::$db->bind("form_id", $form_id);
        Core::$db->execute();
    }


    /**
     * Examines a form to see if it contains a file upload field.
     * @param integer $form_id
     * @return boolean
     */
    public static function getNumFileUploadFields($form_id)
    {
        $db = Core::$db;

        $db->query("
            SELECT count(*) as c
            FROM   {PREFIX}form_fields ff, {PREFIX}field_types fft
            WHERE  ff.form_id = :form_id AND
                   ff.field_type_id = fft.field_type_id AND
                   fft.is_file_field = 'yes'
        ");
        $db->bind("form_id", $form_id);
        $db->execute();

        $result = $db->fetch();
        $count = $result["c"];

        return $count > 0;
    }


    /**
     * This checks to see the a form exists in the database. It's just used to confirm a form ID is valid.
     * @param integer $form_id
     * @param boolean $allow_incompleted_forms an optional value to still return TRUE for incomplete forms
     * @return boolean
     */
    public static function checkFormExists($form_id, $allow_incompleted_forms = false)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
        $form = $db->fetch();

        $is_valid_form_id = false;
        if (($form && $allow_incompleted_forms) || ($form["is_initialized"] == "yes" && $form["is_complete"] == "yes")) {
            $is_valid_form_id = true;
        }

        return $is_valid_form_id;
    }


    /**
     * Called by the administrator only. Updates the list of clients on a public form's omit list.
     *
     * @param array $info
     * @param integer $form_id
     * @return array [0] T/F, [1] message
     */
    public static function updatePublicFormOmitList($info, $form_id)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        OmitLists::deleteFormOmitList($form_id);

        $client_ids = (isset($info["selected_client_ids"])) ? $info["selected_client_ids"] : array();
        foreach ($client_ids as $account_id) {
            $db->query("
                INSERT INTO {PREFIX}public_form_omit_list (form_id, account_id)
                VALUES (:form_id, :account_id)
            ");
            $db->bindAll(array(
                "form_id" => $form_id,
                "account_id" => $account_id
            ));
            $db->execute();
        }

        return array(true, $LANG["notify_public_form_omit_list_updated"]);
    }


    /**
     * This returns the IDs of the previous and next forms, as determined by the administrators current
     * search and sort.
     *
     * Not happy with this function! Getting this info is surprisingly tricky, once you throw in the sort clause.
     * Still, the number of client accounts are liable to be quite small, so it's not such a sin.
     *
     * @param integer $form_id
     * @param array $search_criteria
     * @return array prev_form_id => the previous account ID (or empty string)
     *               next_form_id => the next account ID (or empty string)
     */
    public static function getFormPrevNextLinks($form_id, $search_criteria = array())
    {
        $db = Core::$db;

        $results = self::getSearchFormSqlClauses($search_criteria);

        $db->query("
            SELECT form_id
            FROM   {PREFIX}forms
            {$results["where_clause"]}
            {$results["order_clause"]}
        ");
        $db->execute();

        $sorted_form_ids = array();
        foreach ($db->fetchAll() as $row) {
            $sorted_form_ids[] = $row["form_id"];
        }
        $current_index = array_search($form_id, $sorted_form_ids);

        $return_info = array("prev_form_id" => "", "next_form_id" => "");
        if ($current_index === 0) {
            if (count($sorted_form_ids) > 1) {
                $return_info["next_form_id"] = $sorted_form_ids[$current_index + 1];
            }
        } else if ($current_index === count($sorted_form_ids)-1) {
            if (count($sorted_form_ids) > 1) {
                $return_info["prev_form_id"] = $sorted_form_ids[$current_index - 1];
            }
        } else {
            $return_info["prev_form_id"] = $sorted_form_ids[$current_index-1];
            $return_info["next_form_id"] = $sorted_form_ids[$current_index+1];
        }

        return $return_info;
    }


    /**
     * Returns a list of (completed, finalized) forms, ordered by form name.
     * @return array
     */
    public static function getFormList()
    {
        $db = Core::$db;

        $db->query("
            SELECT *
            FROM   {PREFIX}forms
            WHERE  is_complete = 'yes' AND
                   is_initialized = 'yes'
            ORDER BY form_name ASC
        ");
        $db->execute();

        return $db->fetchAll();
    }


    /**
     * Returns the name of a form. Generally used in presentation situations.
     * @param integer $form_id
     */
    public static function getFormName($form_id)
    {
        $db = Core::$db;

        $db->query("SELECT form_name FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
        $result = $db->fetch();

        return $result["form_name"];
    }


    /**
     * Returns all the column names for a particular form. The optional $view_id field lets you return
     * only those columns that are associated with a particular View. The second optional setting
     * lets you only return custom form fields (everything excep submission ID, submission date,
     * last modified date, IP address and is_finalized)
     *
     * N.B. Updated in 2.0.0 to query the form_fields table instead of the actual form table and extract
     * the form column names from that. This should be quicker & allows us to return the columns in the
     * appropriate list_order.
     *
     * @param integer $form_id the unique form ID
     * @param integer $view_id (optional) if supplied, returns only those columns that appear in a
     *     particular View
     * @param boolean $omit_system_fields
     * @return array A hash of form: [DB column name] => [column display name]. If the database
     *     column doesn't have a display name (like with submission_id) the value is set to the same
     *     as the key.
     */
    public static function getFormColumnNames($form_id, $view_id = "", $omit_system_fields = false)
    {
        $db = Core::$db;

        $db->query("
            SELECT col_name, field_title, is_system_field
            FROM {PREFIX}form_fields
            WHERE form_id = :form_id
            ORDER BY list_order
        ");
        $db->bind("form_id", $form_id);
        $db->execute();

        $view_col_names = array();
        if (!empty($view_id)) {
            $view_fields = ViewFields::getViewFields($view_id);
            foreach ($view_fields as $field_info) {
                $view_col_names[] = $field_info["col_name"];
            }
        }

        $col_names = array();
        foreach ($db->fetchAll() as $col_info) {
            if ($col_info["is_system_field"] == "yes" && $omit_system_fields) {
                continue;
            }
            if (!empty($view_id) && !in_array($col_info["col_name"], $view_col_names)) {
                continue;
            }
            $col_names[$col_info["col_name"]] = $col_info["field_title"];
        }

        return $col_names;
    }


    /**
     * This function updates the main form values in preparation for a test submission by the actual
     * form. It is called from step 2 of the form creation page when UPDATING an existing, incomplete
     * form.
     *
     * @param array $infohash This parameter should be a hash (e.g. $_POST or $_GET) containing the
     *             various fields from the step 2 add form page.
     * @return array Returns array with indexes:<br/>
     *               [0]: true/false (success / failure)<br/>
     *               [1]: message string<br/>
     */
    public static function setFormMainSettings($infohash)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        $success = true;
        $message = "";

        // check required infohash fields
        $rules = array();
        $rules[] = "required,form_name,{$LANG["validation_no_form_name"]}";
        $errors = validate_fields($infohash, $rules);

        if (!empty($errors)) {
            return array(false, General::getErrorListHTML($errors), "");
        }

        // extract values
        $access_type  = isset($infohash['access_type']) ? $infohash['access_type'] : "public";
        $client_ids   = isset($infohash['selected_client_ids']) ? $infohash['selected_client_ids'] : array();
        $form_id      = $infohash["form_id"];
        $form_name    = trim($infohash['form_name']);
        $is_multi_page_form   = isset($infohash["is_multi_page_form"]) ? $infohash["is_multi_page_form"] : "no";
        $redirect_url = isset($infohash['redirect_url']) ? trim($infohash['redirect_url']) : "";

        if ($is_multi_page_form == "yes")
            $form_url = $infohash["multi_page_urls"][0];
        else
            $form_url = $infohash["form_url"];


        // all checks out, so update the new form
        $db->query("
            UPDATE {PREFIX}forms
            SET    access_type = :access_type,
                   is_active = 'no',
                   is_complete = 'no',
                   is_multi_page_form = :is_multi_page_form,
                   form_name = :form_name,
                   form_url = :form_url,
                   redirect_url = :redirect_url
            WHERE  form_id = :form_id
        ");
        $db->bindAll(array(
            "access_type" => $access_type,
            "is_multi_page_form" => $is_multi_page_form,
            "form_name" => $form_name,
            "form_url" => $form_url,
            "redirect_url" => $redirect_url,
            "form_id" => $form_id
        ));
        $db->execute();

        Forms::deleteClientForms($form_id);

        foreach ($client_ids as $client_id) {
            $db->query("INSERT INTO {PREFIX}client_forms (account_id, form_id) VALUES (:client_id, :form_id)");
            $db->bindAll(array("client_id" => $client_id, "form_id" => $form_id));
            $db->execute();
        }

        // set the multi-page form URLs
        $db->query("DELETE FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        if ($is_multi_page_form == "yes") {
            $page_num = 1;
            foreach ($infohash["multi_page_urls"] as $url) {
                if (empty($url)) {
                    continue;
                }
                $db->query("INSERT INTO {PREFIX}multi_page_form_urls (form_id, form_url, page_num) VALUES (:form_id, :url, :page_num)");
                $db->bindAll(array(
                "form_id" => $form_id,
                "url" => $url,
                "page_num" => $page_num
                ));
                $db->execute();
                $page_num++;
            }
        }

        extract(Hooks::processHookCalls("end", compact("infohash", "success", "message"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }


    /**
     * Called by test form submission during form setup procedure. This stores a complete form submission in the database
     * for examination and pruning by the administrator. Error / notification messages are displayed in the language of
     * the currently logged in administrator.
     *
     * It works with both submissions sent through process.php and the API.
     *
     * @param array $form_data a hash of the COMPLETE form data (i.e. all fields)
     */
    public static function initializeForm($form_data)
    {
        $LANG = Core::$L;
        $db = Core::$db;

        $textbox_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("textbox");

        $display_notification_page = isset($form_data["form_tools_display_notification_page"]) ?
            $form_data["form_tools_display_notification_page"] : true;

        $form_id = $form_data["form_tools_form_id"];

        // check the form ID is valid
        if (!self::checkFormExists($form_id, true)) {
            Errors::showErrorCode(Errors::$CODES["100"]);
        }

        $form_info = self::getForm($form_id);

        // if this form has already been completed, exit with an error message
        if ($form_info["is_complete"] == "yes") {
            Errors::showErrorCode(Errors::$CODES["101"]);
        }

        // since this form is still incomplete, remove any old records from form_fields concerning this form
        Fields::clearFormFields($form_id);

        // remove irrelevant key-values
        unset($form_data["form_tools_initialize_form"]);
        unset($form_data["form_tools_submission_id"]);
        unset($form_data["form_tools_form_id"]);
        unset($form_data["form_tools_display_notification_page"]);

        $db->beginTransaction();

        try {
            Fields::addSubmissionIdSystemField($form_id, $textbox_field_type_id);
            $order = Fields::addFormFields($form_id, $form_data, 2); // 2 = the second field (we just added submission ID)
            $order = Fields::addFormFileFields($form_id, $_FILES, $order);
            Fields::addSystemFields($form_id, $textbox_field_type_id, $order);

            $db->processTransaction();

        } catch (PDOException $e) {
            $db->rollbackTransaction();
            Errors::showErrorCode(Errors::$CODES["103"], $e->getMessage());
        }

        // finally, set this form's "is_initialized" value to "yes", so the administrator can proceed to
        // the next step of the Add Form process.
        self::setFormInitialized($form_id);

        // alert a "test submission complete" message. The only time this wouldn't be outputted would be
        // if this function is being called programmatically, like with the blank_form module
        if ($display_notification_page) {

            // not an error! Find to re-purpose the template but rename it to notification.tpl
            $page_vars = array(
                "message" => $LANG["processing_init_complete"],
                "message_type" => "notify",
                "title" => $LANG["phrase_test_submission_received"]
            );
            Themes::displayPage("error.tpl", $page_vars);
            exit;
        }
    }


    public static function setFormInitialized($form_id)
    {
        Core::$db->query("
            UPDATE  {PREFIX}forms
            SET     is_initialized = 'yes'
            WHERE   form_id = :form_id
        ");
        Core::$db->bind("form_id", $form_id);

        try {
            Core::$db->execute();
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }


    /**
     * This function "finalizes" the form, i.e. marks it as completed and ready to go.
     *
     * This is where the excitement happens. This function is called when the user has completed step
     * 4 of the Add Form process, after the user is satisfied that the data that is stored is correct.
     * This function does the following:
     * <ul>
     * <li>Adds a new record to the <b>form_admin_fields</b> table listing which of the database fields are
     * to be visible in the admin interface panel for this form.</li>
     * <li>Creates a new form table with the column information specified in infohash.</li>
     * </ul>
     *
     * @param array $infohash This parameter should be a hash (e.g. $_POST or $_GET) containing the
     *             various fields from the Step 4 Add Form page.
     */
    public static function finalizeForm($form_id)
    {
        $LANG = Core::$L;
        $db = Core::$db;
        $db_table_charset = Core::getDbTableCharset();
        $FIELD_SIZES = FieldSizes::get();

        $form_fields = Fields::getFormFields($form_id);
        $query = "
            CREATE TABLE {PREFIX}form_$form_id (
            submission_id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY(submission_id),\n";

        foreach ($form_fields as $field) {
            if ($field["is_system_field"] == "yes") {
                continue;
            }
            $sql_size = $FIELD_SIZES[$field["field_size"]]["sql"];
            $query .= "{$field['col_name']} $sql_size,\n";
        }

        $query .= "submission_date DATETIME NOT NULL,
            last_modified_date DATETIME NOT NULL,
            ip_address VARCHAR(15),
            is_finalized ENUM('yes','no') default 'yes')
            DEFAULT CHARSET = $db_table_charset";

        try {
            $db->query($query);
            $db->execute();
        } catch (PDOException $e) {
            return array(
                "success" => "0",
                "message" => $LANG["notify_create_form_failure"],
                "sql_error" => $e->getMessage()
            );
        }

        // now the form is complete. Update it as is_complete and enabled
        $db->query("
            UPDATE {PREFIX}forms
            SET    is_initialized = 'yes',
                   is_complete = 'yes',
                   is_active = 'yes',
                   date_created = :now
            WHERE  form_id = :form_id
        ");
        $db->bindAll(array(
            "now" => General::getCurrentDatetime(),
            "form_id" => $form_id
        ));

        try {
            $db->execute();
        } catch (PDOException $e) {
            return array(
                "success"   => "0",
                "sql_error" => $e->getMessage()
            );
        }

        // finally, add the default View
        Views::addDefaultView($form_id);

        extract(Hooks::processHookCalls("end", compact("form_id"), array()), EXTR_OVERWRITE);

        return array(
            "success" => 1,
            "message" => ""
        );
    }


    /**
     * Called on step 5 of the Add Form process. It processes the Mass Smart Filled field values, add / updates the
     * appropriate field types, field sizes and option lists.
     */
    public static function setFormFieldTypes($form_id, $info)
    {
        $db = Core::$db;

        extract(Hooks::processHookCalls("start", compact("info", "form_id"), array("info")), EXTR_OVERWRITE);

        $textbox_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("textbox");

        // set a 10 minute maximum execution time for this request. For long forms it can take a long time. 10 minutes
        // is extremely excessive, but what the hey
        @set_time_limit(600);

        $form_fields = Fields::getFormFields($form_id);

        // update the field types and sizes
        $option_lists = array();
        foreach ($form_fields as $field_info) {
            if ($field_info["is_system_field"] == "yes") {
                continue;
            }
            $field_id = $field_info["field_id"];

            // update all the field types
            $field_type_id = $textbox_field_type_id;
            if (isset($info["field_{$field_id}_type"])) {
                $field_type_id = $info["field_{$field_id}_type"];
            }
            $field_size = "medium";
            if (isset($info["field_{$field_id}_size"])) {
                $field_size = $info["field_{$field_id}_size"];
            }

            $db->query("
                UPDATE {PREFIX}form_fields
                SET    field_type_id = :field_type_id,
                       field_size = :field_size
                WHERE  field_id = :field_id
            ");
            $db->bindAll(array(
                "field_type_id" => $field_type_id,
                "field_size" => $field_size,
                "field_id" => $field_id
            ));
            $db->execute();

            // if this field is an Option List field, store all the option list info. We'll add them at the end
            if (isset($info["field_{$field_id}_num_options"]) && is_numeric($info["field_{$field_id}_num_options"])) {
                $num_options = $info["field_{$field_id}_num_options"];
                $options = array();
                for ($i=1; $i<=$num_options; $i++) {
                    $options[] = array(
                        "value" => $info["field_{$field_id}_opt{$i}_val"],
                        "text" => $info["field_{$field_id}_opt{$i}_txt"]
                    );
                }
                $option_lists[$field_id] = array(
                    "field_type_id"    => $field_type_id,
                    "option_list_name" => $field_info["field_title"],
                    "options"          => $options
                );
            }
        }

        // finally, if there were any Option List defined for any of the form field, add the info!
        if (!empty($option_lists)) {
            $field_types = FieldTypes::get();
            $field_type_id_to_option_list_map = array();
            foreach ($field_types as $field_type_info) {
                $field_type_id_to_option_list_map[$field_type_info["field_type_id"]] = $field_type_info["raw_field_type_map_multi_select_id"];
            }

            while (list($field_id, $option_list_info) = each($option_lists)) {
                $list_id = OptionLists::createUniqueOptionList($form_id, $option_list_info);
                $raw_field_type_map_multi_select_id = $field_type_id_to_option_list_map[$option_list_info["field_type_id"]];
                if (is_numeric($list_id)) {
                    FieldSettings::addSetting($field_id, $raw_field_type_map_multi_select_id, $list_id);
                }
            }
        }
    }


    /**
     * Completely removes a form from the database. This includes deleting all form fields, emails, Views,
     * View fields, View tabs, View filters, client-form, client-view and public omit list (form & View),
     * and anything else!
     *
     * It also includes an optional parameter to remove all files that were uploaded through file fields in the
     * form; defaulted to FALSE.
     *
     * @param integer $form_id the unique form ID
     * @param boolean $remove_associated_files A boolean indicating whether or not all files that were
     *              uploaded via file fields in this form should be removed as well.
     */
    public static function deleteForm($form_id, $remove_associated_files = false)
    {
        $db = Core::$db;

        extract(Hooks::processHookCalls("start", compact("form_id"), array()), EXTR_OVERWRITE);
        $form_fields = Fields::getFormFields($form_id, array("include_field_type_info" => true));

        $success = true;
        $message = "";

        $file_delete_problems = array();
        if ($remove_associated_files) {
            $file_delete_problems = Files::removeFormFiles($form_id, $form_fields);
        }

        // remove the table
        $db->query("DROP TABLE IF EXISTS {PREFIX}form_$form_id");
        $db->execute();

        // remove any reference to the form in form_fields
        $db->query("DELETE FROM {PREFIX}form_fields WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        // remove any reference to the form in forms table
        $db->query("DELETE FROM {PREFIX}forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        Forms::deleteClientForms($form_id);

        $db->query("DELETE FROM {PREFIX}form_email_fields WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        OmitLists::deleteFormOmitList($form_id);

        $db->query("DELETE FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}list_groups WHERE group_type = :group_type");
        $db->bind("group_type", "form_{$form_id}_view_group");
        $db->execute();

        // delete all email templates for the form
        foreach (Emails::getEmailTemplateList($form_id) as $email_template_info) {
            Emails::deleteEmailTemplate($email_template_info["email_id"]);
        }

        // delete all form Views
        $view_ids = Views::getViewIds($form_id);
        foreach ($view_ids as $view_id) {
            Views::deleteView($view_id);
        }

        // remove any field settings
        foreach ($form_fields as $field_info) {
            FieldSettings::deleteSettings($field_info["field_id"]);
        }

        // as with many things in the script, potentially we need to return a vast range of information from this last function. But
        // we'll limit it to the file delete problems
        if (!$success) {
            $message = $file_delete_problems;
        }

        return array($success, $message);
    }


    /**
     * Called by client accounts, allowing them to update the num_submissions_per_page and auto email
     * settings.
     *
     * TODO: this used?
     *
     * @param array $infohash a hash containing the various form values to update.
     */
    public static function clientUpdateFormSettings($infohash)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        extract(Hooks::processHookCalls("start", compact("infohash"), array("infohash")), EXTR_OVERWRITE);

        $success = true;
        $message = $LANG["notify_form_settings_updated"];

        // validate $infohash fields
        $rules = array();
        $rules[] = "required,form_id,{$LANG["validation_no_form_id"]}";
        $rules[] = "required,is_active,{$LANG["validation_is_form_active"]}";
        $rules[] = "required,num_submissions_per_page,{$LANG["validation_no_num_submissions_per_page"]}";
        $rules[] = "digits_only,num_submissions_per_page,{$LANG["validation_invalid_num_submissions_per_page"]}";
        $errors = validate_fields($infohash, $rules);

        $db->query("
            UPDATE {PREFIX}forms
            SET    is_active = :is_active,
                   auto_email_admin = :auto_email_admin,
                   auto_email_user = :auto_email_user,
                   num_submissions_per_page = :num_submissions_per_page,
                   printer_friendly_format = :printer_friendly_format,
                   hide_printer_friendly_empty_fields = :hide_printer_friendly_empty_fields
            WHERE  form_id = :form_id
        ");
        $db->bindAll(array(
            "is_active" => $infohash['is_active'],
            "auto_email_admin" => $infohash['auto_email_admin'],
            "auto_email_user" => $infohash['auto_email_user'],
            "num_submissions_per_page" => $infohash['num_submissions_per_page'],
            "printer_friendly_format" => $infohash['printer_friendly_format'],
            "hide_printer_friendly_empty_fields" => $infohash['hide_empty_fields'],
            "form_id" => $infohash['form_id']
        ));

        try {
            $db->execute();
        } catch (PDOException $e) {
            return array(false, $LANG["notify_form_not_updated_notify_admin"]);
        }

        extract(Hooks::processHookCalls("end", compact("infohash", "success", "message"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }


    /**
     * Returns a list of (completed, finalized) forms, ordered by form name, and all views, ordered
     * by view_order. This is handy for any time you need to just output the list of forms & their Views.
     *
     * @return array
     */
    public static function getFormViewList()
    {
        $db = Core::$db;

        $db->query("
            SELECT form_id, form_name
            FROM   {PREFIX}forms
            WHERE  is_complete = 'yes' AND
                   is_initialized = 'yes'
            ORDER BY form_name ASC
        ");
        $db->execute();

        $results = array();
        foreach ($db->fetchAll() as $row) {
            $form_id = $row["form_id"];

            $db->query("
                SELECT view_id, view_name
                FROM   {PREFIX}views
                WHERE  form_id = :form_id
            ");
            $db->bind("form_id", $form_id);
            $db->execute();

            $views = array();
            foreach ($db->fetchAll() as $row2) {
                $views[] = array(
                    "view_id"   => $row2["view_id"],
                    "view_name" => $row2["view_name"]
                );
            }

            $results[] = array(
                "form_id"   => $form_id,
                "form_name" => $row["form_name"],
                "views"     => $views
            );
        }

        return $results;
    }


    /**
     * Called by administrators; updates the content stored on the "Main" tab in the Edit Form pages.
     *
     * @param integer $infohash a hash containing the contents of the Edit Form Main tab.
     * @return array returns array with indexes:<br/>
     *               [0]: true/false (success / failure)<br/>
     *               [1]: message string<br/>
     */
    public static function updateFormMainTab($infohash, $form_id)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        extract(Hooks::processHookCalls("start", compact("infohash", "form_id"), array("infohash")), EXTR_OVERWRITE);

        // check required POST fields
        $rules = array();
        $rules[] = "required,form_name,{$LANG["validation_no_form_name"]}";
        $rules[] = "required,edit_submission_page_label,{$LANG["validation_no_edit_submission_page_label"]}";
        $errors = validate_fields($infohash, $rules);

        if (!empty($errors)) {
            return array(false, General::getErrorListHTML($errors), "");
        }

        $is_active = "";
        if (!empty($infohash["active"])) {
            $is_active = "is_active = '{$infohash['active']}',";
        }

        $submission_type = $infohash["submission_type"];
        $access_type = $infohash["access_type"];
        $client_ids = isset($infohash["selected_client_ids"]) ? $infohash["selected_client_ids"] : array();
        $is_multi_page_form = isset($infohash["is_multi_page_form"]) ? $infohash["is_multi_page_form"] : "no";

        if ($submission_type == "direct") {
            $is_multi_page_form = "no";
        }

        if ($is_multi_page_form == "yes") {
            $form_url = $infohash["multi_page_urls"][0];
        } else {
            $form_url = $infohash["form_url"];
        }

        try {
            $db->query("
                UPDATE {PREFIX}forms
                SET    $is_active
                       form_type = :form_type,
                       submission_type = :submission_type,
                       is_multi_page_form = :is_multi_page_form,
                       form_url = :form_url,
                       form_name = :form_name,
                       redirect_url = :redirect_url,
                       access_type = :access_type,
                       auto_delete_submission_files = :auto_delete_submission_files,
                       submission_strip_tags = :submission_strip_tags,
                       edit_submission_page_label = :edit_submission_page_label,
                       add_submission_button_label = :add_submission_button_label
                WHERE  form_id = :form_id
            ");
            $db->bindAll(array(
                "form_type" => $infohash["form_type"],
                "submission_type" => $submission_type,
                "is_multi_page_form" => $is_multi_page_form,
                "form_url" => $form_url,
                "form_name" => $infohash["form_name"],
                "redirect_url" => isset($infohash["redirect_url"]) ? $infohash["redirect_url"] : "",
                "access_type" => $access_type,
                "auto_delete_submission_files" => $infohash["auto_delete_submission_files"],
                "submission_strip_tags" => $infohash["submission_strip_tags"],
                "edit_submission_page_label" => $infohash["edit_submission_page_label"],
                "add_submission_button_label" => $infohash["add_submission_button_label"],
                "form_id" => $form_id
            ));

            $db->execute();
        } catch (PDOException $e) {
            Errors::queryError(__CLASS__ , __FILE__, __LINE__, $e->getMessage());
            exit;
        }

        // update the list of clients associated with this form
        Forms::updateFormAccess($form_id, $access_type, $client_ids);

        // update the multi-page form URLs
        $db->query("DELETE FROM {PREFIX}multi_page_form_urls WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();

        // if this is a multi-page form, add the list of pages in the form. One minor thing to note: the first page in the form
        // is actually stored in two locations: one in the main "form_url" value in the form, and two, here in the
        // multi_page_form_urls table. It's not necessary, of course, but it makes the code a little simpler
        if ($is_multi_page_form == "yes") {
            $page_num = 1;
            foreach ($infohash["multi_page_urls"] as $url) {
                if (empty($url)) {
                    continue;
                }

                $db->query("
                    INSERT INTO {PREFIX}multi_page_form_urls (form_id, form_url, page_num)
                    VALUES (:form_id, :form_url, :page_num)
                ");
                $db->bindAll(array(
                    "form_id" => $form_id,
                    "form_url" => $form_url,
                    "page_num" => $page_num
                ));
                $db->execute();

                $page_num++;
            }
        }

        $success = true;
        $message = $LANG["notify_form_updated"];

        extract(Hooks::processHookCalls("end", compact("infohash", "form_id", "success", "message"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }


    public static function updateFormAccess($form_id, $access_type, $client_ids)
    {
        $db = Core::$db;

        if ($access_type === "public") {
            Forms::deleteClientForms($form_id);
            foreach ($client_ids as $client_id) {
                $db->query("
                    INSERT INTO {PREFIX}client_forms (account_id, form_id)
                    VALUES  (:client_id, :form_id)
                ");
                $db->bindAll(array(
                    "client_id" => $client_id,
                    "form_id" => $form_id
                ));
                $db->execute();
            }
        }

        // delete all client_view, client_form, public_form_omit_list, and public_view_omit_list entries concerning this
        // form & its Views. Since only the administrator can see the form, no client can see any of its sub-parts
        if ($access_type == "admin") {
            Forms::deleteClientForms($form_id);
            Views::deleteClientViewsByFormId($form_id);
        }

        // remove any records from the client_view and public_view_omit_list tables concerned clients NOT associated
        // with this form.
        if ($access_type == "private") {
            OmitLists::deleteFormOmitList($form_id);

            $client_clauses = array();
            foreach ($client_ids as $client_id) {
                $client_clauses[] = "account_id != $client_id";
            }

            // there WERE clients associated with this form. Delete the ones that AREN'T associated
            if (!empty($client_clauses)) {
                $client_id_clause = implode(" AND ", $client_clauses);
                $db->query("DELETE FROM {PREFIX}client_views WHERE form_id = :form_id AND $client_id_clause");
                $db->bind("form_id", $form_id);
                $db->execute();

                // also delete any orphaned records in the View omit list
                $view_ids = Views::getViewIds($form_id);
                foreach ($view_ids as $view_id) {
                    $db->query("DELETE FROM {PREFIX}public_view_omit_list WHERE view_id = :view_id AND $client_id_clause");
                    $db->bind("view_id", $view_id);
                    $db->execute();
                }

                // for some reason, the administrator has assigned NO clients to this private form. So, delete all clients
                // associated with the Views
            } else {
                $view_ids = Views::getViewIds($form_id);
                foreach ($view_ids as $view_id) {
                    Views::deleteClientViews($view_id);
                    Views::deletePublicViewOmitList($view_id);
                }
            }
        }
    }


    public static function deleteClientForms($form_id)
    {
        $db = Core::$db;
        $db->query("DELETE FROM {PREFIX}client_forms WHERE form_id = :form_id");
        $db->bind("form_id", $form_id);
        $db->execute();
    }


    public static function deleteClientFormsByAccountId($account_id)
    {
        $db = Core::$db;
        $db->query("DELETE FROM {PREFIX}client_forms WHERE account_id = :account_id");
        $db->bind("account_id", $account_id);
        $db->execute();
    }


    /**
     * Called by administrators; updates the content stored on the "Fields" tab in the Edit Form pages.
     *
     * TODO refactor.
     *
     * @param integer $form_id the unique form ID
     * @param array $infohash a hash containing the contents of the Edit Form Display tab
     * @return array returns array with indexes:<br/>
     *               [0]: true/false (success / failure)<br/>
     *               [1]: message string<br/>
     */
    public static function updateFormFieldsTab($form_id, $infohash)
    {
        $LANG = Core::$L;
        $success = true;
        $message = $LANG["notify_field_changes_saved"];

        extract(Hooks::processHookCalls("start", compact("infohash", "form_id"), array("infohash")), EXTR_OVERWRITE);

        // JS-generated stuff from the page
        $sortable_id = $infohash["sortable_id"];
        $field_ids = explode(",", $infohash["{$sortable_id}_sortable__rows"]);
        $order = $infohash["sortable_row_offset"];
        $new_sort_groups = explode(",", $infohash["{$sortable_id}_sortable__new_groups"]);

        // parse the POST data to get the info in a simple format
        $field_info = Forms::editFieldsPageGetFormattedPostData($field_ids, $infohash, $order, $new_sort_groups);

        // delete any extended field settings for those fields whose field type just changed
        Forms::editFieldsPageUpdateFieldSettings($field_info);

        // update database table structure + form field record
        Forms::editFieldsPageUpdateDatabaseCols($form_id, $field_info);

        // okay! now add any new fields that the user just added
        $new_fields = array();
        $num_existing_fields = 0;
        foreach ($field_info as $curr_field) {
            if ($curr_field["is_new_field"]) {
                $new_fields[] = $curr_field;
            } else {
                $num_existing_fields++;
            }
        }
        if (!empty($new_fields)) {
            list($is_success, $error) = Fields::addFormFieldsAdvanced($form_id, $new_fields, $num_existing_fields+1);

            // if there was a problem adding any of the new fields, inform the user
            if (!$is_success) {
                $success = false;
                $message = $error;
            }
        }

        // Lastly, delete the necessary fields. Since some field types (e.g. files) may have additional functionality
        // needed at this stage (e.g. deleting the actual files that had been uploaded via the form). This occurs regardless
        // of whether the add fields step worked or not
        $deleted_field_ids = explode(",", $infohash["{$sortable_id}_sortable__deleted_rows"]);
        extract(Hooks::processHookCalls("delete_fields", compact("deleted_field_ids", "infohash", "form_id"), array()), EXTR_OVERWRITE);

        // now actually delete the fields
        Fields::deleteFormFields($form_id, $deleted_field_ids);

        extract(Hooks::processHookCalls("end", compact("infohash", "field_info", "form_id"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }


    // --------------------------------------------------------------------------------------------

    // Private methods

    /**
     * Used in Forms::searchForms and Forms::getFormPrevNextLinks, this function looks at the current search and figures
     * out the WHERE and ORDER BY clauses so that the calling function can retrieve the appropriate form results in
     * the appropriate order.
     *
     * @param array $search_criteria
     * @return array $clauses
     */
    private static function getSearchFormSqlClauses($search_criteria)
    {
        $order_clause = self::getOrderClause($search_criteria["order"]);
        $status_clause = self::getStatusClause($search_criteria["status"]);
        $keyword_clause = self::getKeywordClause($search_criteria["keyword"]);
        $form_clause = self::getFormClause($search_criteria["account_id"]);
        $omitted_forms = OmitLists::getPublicFormOmitListByAccountId($search_criteria["account_id"]);
        $admin_clause = (!$search_criteria["is_admin"]) ? "is_complete = 'yes' AND is_initialized = 'yes'" : "";

        // add up the where clauses
        $where_clauses = array();
        if (!empty($status_clause)) {
            $where_clauses[] = $status_clause;
        }
        if (!empty($keyword_clause)) {
            $where_clauses[] = "($keyword_clause)";
        }
        if (!empty($form_clause)) {
            $where_clauses[] = $form_clause;
        }
        if (!empty($admin_clause)) {
            $where_clauses[] = $admin_clause;
        }

        if (!empty($where_clauses)) {
            $where_clause = "WHERE " . join(" AND ", $where_clauses);
        } else {
            $where_clause = "";
        }

        return array(
            "order_clause" => $order_clause,
            "where_clause" => $where_clause,
            "omitted_forms" => $omitted_forms
        );
    }


    private static function getOrderClause($order)
    {
        if (!isset($order) || empty($order)) {
            $search_criteria["order"] = "form_id-DESC";
        }

        $order_map = array(
            "form_id-ASC" => "form_id ASC",
            "form_id-DESC" => "form_id DESC",
            "form_name-ASC" => "form_name ASC",
            "form_name-DESC" => "form_name DESC",
            "form_type-ASC" => "form_type ASC",
            "form_type-DESC" => "form_type DESC",
            "status-ASC" => "is_active = 'yes', is_active = 'no', (is_initialized = 'no' AND is_complete = 'no')",
            "status-DESC" => "(is_initialized = 'no' AND is_complete = 'no'), is_active = 'no', is_active = 'yes'",
        );

        if (isset($order_map[$order])) {
            $order_clause = $order_map[$order];
        } else {
            $order_clause = "form_id DESC";
        }
        return "ORDER BY $order_clause";
    }


    private static function getStatusClause($status)
    {
        if (!isset($status) || empty($status)) {
            return "";
        }

        switch ($status) {
            case "online":
                $status_clause = "is_active = 'yes' ";
                break;
            case "offline":
                $status_clause = "(is_active = 'no' AND is_complete = 'yes')";
                break;
            case "incomplete":
                $status_clause = "(is_initialized = 'no' OR is_complete = 'no')";
                break;
            default:
                $status_clause = "";
                break;
        }
        return $status_clause;
    }


    // TODO
    private static function getKeywordClause($keyword)
    {
        $keyword_clause = "";
        if (isset($keyword) && !empty($keyword)) {
            $search_criteria["keyword"] = trim($keyword);
            $string = $search_criteria["keyword"];
            $fields = array("form_name", "form_url", "redirect_url", "form_id");

            $clauses = array();
            foreach ($fields as $field) {
                $clauses[] = "$field LIKE '%$string%'";
            }

            $keyword_clause = join(" OR ", $clauses);
        }
        return $keyword_clause;
    }


    /**
     * Used in the search query to ensure the search limits the results to whatever forms a particular account may view.
     * @param $account_id
     * @return string
     */
    private static function getFormClause($account_id)
    {
        if (empty($account_id)) {
            return "";
        }

        $db = Core::$db;

        $clause = "";
        if (!empty($account_id)) {

            // a bit weird, but necessary. This adds a special clause to the query so that when it searches for a
            // particular account, it also (a) returns all public forms and (b) only returns those forms that are
            // completed. This is because incomplete forms are still set to access_type = "public". Note: this does NOT
            // take into account the public_form_omit_list - that's handled by self::getFormOmitList
            $is_public_clause = "(access_type = 'public')";
            $is_setup_clause = "is_complete = 'yes' AND is_initialized = 'yes'";

            // first, grab all those forms that are explicitly associated with this client
            $db->query("
                SELECT *
                FROM   {PREFIX}client_forms
                WHERE  account_id = :account_id
            ");
            $db->bind("account_id", $account_id);
            $db->execute();

            $form_clauses = array();
            foreach ($db->fetchAll() as $row) {
                $form_clauses[] = "form_id = {$row['form_id']}";
            }

            if (count($form_clauses) > 1) {
                $clause = "(((" . join(" OR ", $form_clauses) . ") OR $is_public_clause) AND ($is_setup_clause))";
            } else {
                $clause = isset($form_clauses[0]) ? "(({$form_clauses[0]} OR $is_public_clause) AND ($is_setup_clause))" :
                    "($is_public_clause AND ($is_setup_clause))";
            }
        }

        return $clause;
    }


    /**
     * Helper method used on the Edit Form -> Fields page to clean up the POST data into a simple array.
     */
    private static function editFieldsPageGetFormattedPostData($field_ids, $post, $order, $sort_groups)
    {
        $field_info = array();
        foreach ($field_ids as $field_id) {
            $is_new_field = preg_match("/^NEW/", $field_id) ? true : false;
            $display_name        = (isset($post["field_{$field_id}_display_name"])) ? $post["field_{$field_id}_display_name"] : "";
            $form_field_name     = (isset($post["field_{$field_id}_name"])) ? $post["field_{$field_id}_name"] : "";
            $include_on_redirect = (isset($post["field_{$field_id}_include_on_redirect"])) ? "yes" : "no";
            $field_size          = (isset($post["field_{$field_id}_size"])) ? $post["field_{$field_id}_size"] : "";
            $col_name            = (isset($post["col_{$field_id}_name"])) ? $post["col_{$field_id}_name"] : "";
            $old_field_size      = (isset($post["old_field_{$field_id}_size"])) ? $post["old_field_{$field_id}_size"] : "";
            $old_col_name        = (isset($post["old_col_{$field_id}_name"])) ? $post["old_col_{$field_id}_name"] : "";
            $is_system_field     = (in_array($field_id, $post["system_fields"])) ? "yes" : "no";

            // this is only sent for non-system fields
            $field_type_id       = isset($post["field_{$field_id}_type_id"]) ? $post["field_{$field_id}_type_id"] : "";

            // won't be defined for new fields
            $old_field_type_id   = (isset($post["old_field_{$field_id}_type_id"])) ? $post["old_field_{$field_id}_type_id"] : "";

            $field_info[] = array(
                "is_new_field"        => $is_new_field,
                "field_id"            => $field_id,
                "display_name"        => $display_name,
                "form_field_name"     => $form_field_name,
                "field_type_id"       => $field_type_id,
                "old_field_type_id"   => $old_field_type_id,
                "include_on_redirect" => $include_on_redirect,
                "is_system_field"     => $is_system_field,
                "list_order"          => $order,
                "is_new_sort_group"   => (in_array($field_id, $sort_groups)) ? "yes" : "no",

                // column name info
                "col_name"            => $col_name,
                "old_col_name"        => $old_col_name,
                "col_name_changed"    => ($col_name != $old_col_name) ? "yes" : "no",

                // field size info
                "field_size"          => $field_size,
                "old_field_size"      => $old_field_size,
                "field_size_changed"  => ($field_size != $old_field_size) ? "yes" : "no"
            );
            $order++;
        }

        return $field_info;
    }

    /**
     * Delete any extended field settings for those fields whose field type just changed. Two comments:
     *    1. this is compatible with editing the fields in the dialog window. When that happens & the user updates it,
     *       the code updates the old_field_type_id info in the page so this is never called.
     *    2. with the addition of Shared Characteristics, this only deletes fields that aren't mapped between the two
     *       fields types (old and new)
     */
    private static function editFieldsPageUpdateFieldSettings($field_info)
    {
        $changed_fields = array();
        foreach ($field_info as $curr_field_info) {
            if ($curr_field_info["is_new_field"] || $curr_field_info["is_system_field"] == "yes" ||
            $curr_field_info["field_type_id"] == $curr_field_info["old_field_type_id"]) {
                continue;
            }
            $changed_fields[] = $curr_field_info;
        }

        if (!empty($changed_fields)) {
            $field_type_settings_shared_characteristics = Settings::get("field_type_settings_shared_characteristics");
            $field_type_map = FieldTypes::getFieldTypeIdToIdentifierMap();

            $shared_settings = array();
            foreach ($changed_fields as $changed_field_info) {
                $field_id = $changed_field_info["field_id"];
                $shared_settings[] = FieldTypes::getSharedFieldSettingInfo($field_type_map, $field_type_settings_shared_characteristics, $field_id, $changed_field_info["field_type_id"], $changed_field_info["old_field_type_id"]);
                Fields::deleteExtendedFieldSettings($field_id);
                FieldValidation::delete($field_id);
            }

            foreach ($shared_settings as $setting) {
                foreach ($setting as $setting_info) {
                    $field_id      = $setting_info["field_id"];
                    $setting_id    = $setting_info["new_setting_id"];
                    $setting_value = $setting_info["setting_value"];
                    FieldSettings::addSetting($field_id, $setting_id, $setting_value);
                }
            }
        }
    }

    /**
     * Called on the Edit Form -> Fields page. This examines the list of updated fields to see if any of them has had
     * the database column renamed. If so, updates it along with the corresponding form field record.
     */
    private static function editFieldsPageUpdateDatabaseCols($form_id, $field_info)
    {
        $db = Core::$db;
        $LANG = Core::$L;
        $debug_enabled = Core::isDebugEnabled();
        $FIELD_SIZES = FieldSizes::get();

        // the database column name and size field both affect the form's actual database table structure. If either
        // of those changed, we need to update the database
        $table_name = "{PREFIX}form_{$form_id}";
        foreach ($field_info as $curr_field_info) {
            if ($curr_field_info["col_name_changed"] == "no" && $curr_field_info["field_size_changed"] == "no") {
                continue;
            }
            if ($curr_field_info["is_new_field"]) {
                continue;
            }

            $field_id       = $curr_field_info["field_id"];
            $old_col_name   = $curr_field_info["old_col_name"];
            $new_col_name   = $curr_field_info["col_name"];
            $new_field_size_sql = $FIELD_SIZES[$curr_field_info["field_size"]]["sql"];

            // we group the table column changes with the record updates in a single transaction to ensure nothing gets
            // out of sync.
            try {
                $db->beginTransaction();

                // first update the actual table column
                // TODO!!!!! confirm that if this fails, the next thing doesn't fire. VERY bloody important to know this!!!!
                General::alterTableColumn($table_name, $old_col_name, $new_col_name, $new_field_size_sql);

                // if any of the database column names just changed we need to update any View filters that relied on them
                Fields::updateFieldFilters($field_id);

                // now update the form field record

                $db->processTransaction();
            } catch (PDOException $e) {
                continue;
            }

//                // if there have already been successful database column name changes already made, update the database.
//                // This helps prevent things getting out of whack
//                if (!empty($db_col_changes)) {
//                    while (list($field_id, $changes) = each($db_col_changes)) {
//                        $col_name   = $changes["col_name"];
//                        $field_size = $changes["field_size"];
//
//                        $db->query("
//                            UPDATE {PREFIX}form_fields
//                            SET    col_name = :col_name,
//                                   field_size = :field_size
//                            WHERE  field_id = :field_id
//                        ");
//                        $db->bindAll(array(
//                            "col_name" => $col_name,
//                            "field_size" => $field_size,
//                            "field_id" => $field_id
//                        ));
//                        $db->execute();
//                    }
//                }
//                $message = $LANG["validation_db_not_updated_invalid_input"];
//                if ($debug_enabled) {
//                    $message .= " \"$err_message\"";
//                }
//                return array(false, $message);
//            }
        }

        // loop through each
        //Fields::temporaryUpdateFields();
    }
}
