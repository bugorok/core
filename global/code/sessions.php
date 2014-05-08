<?php

/**
 * This file contains the sessions management code for database sessions. It's not enabled by default.
 * The code is based on code (I know it was open source) written by someone else. Unfortunately, I haven't
 * been able to locate it. Apologies to the writer(s)!
 */


// -------------------------------------------------------------------------------------------------


class SessionManager
{
  var $db_link;

  function SessionManager()
  {
  	global $g_link;

    $this->db_link = $g_link;

    // register this object as the session handler
    session_set_save_handler(
      array(&$this, "open"),
      array(&$this, "close"),
      array(&$this, "read"),
      array(&$this, "write"),
      array(&$this, "destroy"),
      array(&$this, "gc")
    );
  }

  function open($save_path, $session_name)
  {
    global $sess_save_path;
    $sess_save_path = $save_path;
    return true;
  }

  function close()
  {
    return true;
  }

  function read($id)
  {
    global $g_table_prefix;

    $data = "";

    // fetch session data from the selected database
    $time = time();

    $newid = mysql_real_escape_string($id, $this->db_link);
    $sql = "SELECT session_data FROM {$g_table_prefix}sessions WHERE session_id = '$newid' AND expires > $time";

    $rs = mysql_query($sql, $this->db_link);
    $a  = mysql_num_rows($rs);

    if ($a > 0)
    {
      $row = mysql_fetch_assoc($rs);
      $data = $row["session_data"];
    }

    return $data;
  }

  // this is only executed until after the output stream has been closed
  function write($id, $data)
  {
    global $g_table_prefix;

    $life_time = 1400;
    if (isset($_SESSION["ft"]["account"]["sessions_timeout"]))
      $life_time = $_SESSION["ft"]["account"]["sessions_timeout"] * 60;

    $time = time() + $life_time;

    $newid   = mysql_real_escape_string($id, $this->db_link);
    $newdata = mysql_real_escape_string($data, $this->db_link);

    $sql = "REPLACE {$g_table_prefix}sessions (session_id, session_data, expires) VALUES('$newid', '$newdata', $time)";
    mysql_query($sql, $this->db_link);

    return true;
  }

  function destroy($id)
  {
  	global $g_table_prefix;

    $newid = mysql_real_escape_string($id);
    $sql = "DELETE FROM {$g_table_prefix}sessions WHERE session_id = '$newid'";

    mysql_query($sql, $this->db_link);
    return true;
  }

  function gc()
  {
    global $g_table_prefix;

    // delete all records who have passed the expiration time
    $sql = "DELETE FROM {$g_table_prefix}sessions WHERE expires < UNIX_TIMESTAMP()";
    mysql_query($sql);
    return true;
  }
}