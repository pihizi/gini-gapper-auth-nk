<?php
/**
* @file Wiscom.php
* @brief 调用金智数据
* @author Hongjie Zhu
* @version 0.1.0
* @date 2015-01-08
 */

namespace Gini\Gapper\Auth\NK;

abstract class Wiscom
{

    use \Gini\Module\Gapper\Client\LoggerTrait;

    protected $_db;

    public function __construct($username, $password, $tnsnames, $character='UTF-8') 
    {
        $db_conn = \oci_connect($username, $password, $tnsnames, $character);

        if (!$db_conn) {
            $this->ocilog();
        }

        $this->_db = $db_conn;

        return $this;
    }

    public function ocilog()
    {
        $error = \oci_error();
        $this->error($error['message']);
    }

    public function __destruct() 
    {
        if ($this->_db) {
            \oci_close($this->_db);
        }
    }
}
