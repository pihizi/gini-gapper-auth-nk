<?php
/**
* @file User.php
* @brief 获取金智一卡通用户信息
* @author Hongjie Zhu
* @version 0.1.0
* @date 2015-01-08
 */

namespace Gini\Gapper\Auth\NK\Wiscom;

class Teacher extends \Gini\Gapper\Auth\NK\Wiscom
{

    //用户信息使用view
    const VIEW_USER_INFOS = 'V_JZG_ZGH';

    //通过组织机构的结构树状表
    const GROUP_INFO = 'V_ZZJG';

    /*
     * V_JZG_ZGH
     * 获取用户相关信息
     * uid          职工号
     * name         姓名
     * ref_no       工资号
     * org_code     单位代码
     * org_name     单位名称
     * id_card_no   身份证号
     * phone        联系电话
     * email        电子信箱
     */
    public function getInfo($uid) 
    {
        $sql = sprintf("SELECT * FROM %s WHERE ZGH='%s'", self::VIEW_USER_INFOS, $uid);

        $stmt = \oci_parse($this->_db, $sql);

        if (!$stmt || !@\oci_execute($stmt)) {
            $this->ocilog();
        }

        $info = \oci_fetch_array($stmt, \OCI_ASSOC + \OCI_RETURN_LOBS);

        $ret = [];
        if ($info) {
            $ret['uid'] = $info['ZGH'];
            $ret['name'] = $info['XM'];
            $ret['ref_no'] = $info['GZH'];
            $ret['org_code'] = $info['DWDM'];
            $ret['org_name'] = $info['DWMC'];
            $ret['id_card_no'] = $info['SFZH'];
            $ret['phone'] = $info['LXDH'];
            $ret['email'] = $info['DZXX'];
        }

        return $ret;
    }

    public function getGroups()
    {
        $sql = sprintf('SELECT * FROM %s', self::GROUP_INFO);

        $stmt = \oci_parse($this->_db, $sql);

        if (!$stmt || !@\oci_execute($stmt)) {
            $this->ocilog();
        }

        $groups = [];
        while($row = \oci_fetch_array($stmt, \OCI_ASSOC + \OCI_RETURN_LOBS)) {
            $groups[$row['DWDM']] = $row;
        }

        return $groups;
    }
}
