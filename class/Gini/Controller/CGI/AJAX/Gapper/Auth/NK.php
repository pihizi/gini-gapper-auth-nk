<?php
/**
* @file NK.php
* @brief 南开一卡通登录模块
* @author Hongjie Zhu
* @version 0.1.0
* @date 2015-01-06
 */

namespace Gini\Controller\CGI\AJAX\Gapper\Auth;

class NK extends \Gini\Controller\CGI
{
    use \Gini\Module\Gapper\Client\RPCTrait;
    use \Gini\Module\Gapper\Client\CGITrait;
    use \Gini\Module\Gapper\Client\LoggerTrait;

    private $identitySource = 'nankai';

    private function _getConfig()
    {
        $infos = (array)\Gini\Config::get('gapper.auth');
        $info = (object)$infos['NK'];
        return $info;
    }

    private function _makeUserName($username)
    {
        return \Gini\Auth::makeUserName($username, 'ids.nankai.edu.cn');
    }

    /** 
        * @brief 一卡通密码验证
        *
        * @param $username
        * @param $password
        *
        * @return  boolean
     */
    private function _verify($username, $password)
    {
        $token = $this->_makeUserName($username);
        $auth = \Gini\IoC::construct('\Gini\Auth', $token);
        if ($auth->verify($password)) {
            return true;
        }
        return false;
    }

    /** 
        * @brief 一卡通用户信息获取
        *
        * @param $username 一卡通卡号
        *
        * @return (object)
     */
    private static function _getWiscomInfo($username)
    {
        $config = $this->_getConfig();
        $config = $config['wiscom'];

        $handler = \Gini\IoC::construct('\Gini\Gapper\Auth\NK\Wiscom\Teacher', $config['username'], $config['password'], $config['tnsnames'], $config['character']);

        $info = $handler->getInfo($username);

        if (empty($info)) return;

        return (object)[
            'id'=> $info['uid'],
            'name'=> $info['name'],
            'ref_no'=> $info['ref_no'],
            'org_code'=> $info['org_code'],
            'org_name'=> $info['org_name'],
            'id_card_no'=> $info['id_card_no'],
            'phone'=> $info['phone'],
            'email'=> $info['email']
        ];
    }

    /**
        * @brief 返回modal的json数据
        *
        * @param $view
        * @param $vars
        *
        * @return 
     */
    private function _showActiveDialog($vars=[])
    {
        return $this->showJSON([
            'type'=> 'modal',
            'message'=> (string)V('gapper/auth/nk/active', $vars)
        ]);
    }

    /**
        * @brief 直接以username和group登录
        *
        * @param $username
        * @param $gid: group id
        *
        * @return boolean
     */
    private function _autoLogin($username, $gid)
    {
        $bool = \Gini\Gapper\Client::loginByUserName($username);
        if (!$bool) return false;
        $bool = \Gini\Gapper\Client::chooseGroup((int)$gid);
        return $bool;
    }

    /**
        * @brief 验证用户输入
        *
        * @param $key
        * @param $value
        *
        * @return 
     */
    private static function _validate($key, $value)
    {
        switch ($key) {
        case 'name':
            if (!strlen($value)) {
                return T("Full name must not be empty!");
            }
            break;
        case 'email':
            $pattern = '/^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/';
            if (!preg_match($pattern, $value)) {
                return T('Invalid email address!');
            }
            break;
        case 'group':
            $pattern = '/^[a-z0-9\-\.\_\@]{3,}$/i';
            if (!preg_match($pattern, $value)) {
                return T('Invalid characters!');
            }
            break;
        case 'title':
            if (!strlen($value)) {
                return T("group\004Title must not be empty!");
            }
            break;
        }
    }

    /**
        * @brief 将一卡通用户直接注册为gapper用户，并创建分组，绑定当前app
        *
        * @return 
     */
    public function actionActive()
    {
        $form = $this->form('post');
        $username = trim($form['username']);
        $password = $form['password'];
        $name = trim($form['name']);
        $email = trim($form['email']);
        $group = trim($form['group']);
        $title = trim($form['title']);

        $config = $this->_getConfig();
        $dialogVars = [
            'username'=> $username,
            'password'=> $password,
            'name'=> $name,
            'email'=> $email,
            'group'=> $group,
            'title'=> $title,
            'icon'=> $config->icon,
            'type'=> $config->name
        ];

        // 如果用户输入不符合规范，直接报错
        $error = ['name'=>$name, 'email'=>$email, 'group'=>$group, 'title'=>$title];
        foreach ($error as $k=>$v) {
            $r = self::_validate($k, $v);
            if (!$r) {
                unset($error[$k]);
                continue;
            }
            $error[$k] = $r;
        }
        if (!empty($error)) {
            return $this->_showActiveDialog(array_merge($dialogVars, [
                'error'=> $error
            ]));
        }

        // 验证用户一卡通和密码是否匹配
        // 如果不匹配，应该是用户自己不怀好意的改了
        if (!$this->_verify($username, $password)) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
        }

        // 如果不是老师，不能再登录阶段激活
        $info = self::_getWiscomInfo($username);
        if (!$info) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
        }

        // 如果一卡通已经绑定了gapper用户，直接提示激活成功，让用户尝试登录
        $info = self::getRPC()->user->getUserByIdentity(self::$identitySource, $username);
        if ($info['id']) {
            return $this->showJSON(T('用户激活成功, 请继续登录!'));
        }

        // 如果以email为账号的用户已经存在，直接报错
        $info = self::getRPC()->user->getInfo($email);
        if ($info['id']) {
            return $this->_showActiveDialog(array_merge($dialogVars, [
                'error'=> [
                    'email'=> T('Email已被占用, 请换一个!')
                ]
            ]));
        }

        // 如果组标识已经被占用，直接报错
        $info = self::getRPC()->group->getInfo($group);
        if ($info['id']) {
            return $this->_showActiveDialog(array_merge($dialogVars, [
                'error'=> [
                    'group'=> T('组标识已被占用, 请换一个!')
                ]
            ]));
        }

        // 注册gapper用户, 以Email为用户名
        $uid = self::getRPC()->user->registerUser([
            'username'=> $email,
            'password'=> $password,
            'name'=> $name,
            'email'=> $email
        ]);

        if (!$uid) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
        }

        // 绑定identity
        // 绑定失败，导致email被占用，如果用户想在以这个email激活，将直接报错
        // 所以，需要联系网站客服
        $bool = self::getRPC()->user->linkIdentity((int)$uid, self::$identitySource, $username);
        if (!$bool) {
            return $this->showJSON(T('用户激活失败, 请联系网站客服!'));
        }

        // 创建分组
        $gid = self::getRPC()->group->create([
            'user'=> (int)$uid,
            'name'=> $group,
            'title'=> $title
        ]);

        if (!$gid) {
            return $this->showJSON(T('用户激活成功, 但是创建课题组失败, 请联系网站客服.'));
        }

        // 为新建分组开启当前APP的访问权限
        $config = \Gini\Config::get('gapper.rpc');
        $bool = self::getRPC()->app->installTo($config['client_id'], 'group', (int)$gid);
        if (!$bool) {
            return $this->showJSON(T('用户激活成功，但是暂时无法访问该网站，请联系网站客服解决问题.'));
        }

        // 创建成功，直接以新建用户和组登录app
        $bool = $this->_autoLogin($email, $gid);

        if (!$bool) return $this->showJSON(T('用户激活成功, 请继续登录!'));

        return $this->showJSON(true);
    }

    /**
        * @brief 展示激活表单
        *
        * @param $username
        * @param $password
        *
        * @return 
     */
    private function _showActiveME($username, $password)
    {
        // 获取一卡通用户信息
        // 只有老师才能在注册测时候激活，学生需要由老师添加账号
        $info = self::_getWiscomInfo($username);
        if (!$info) {
            return $this->showJSON(T('请联系课题组管理员将您加入相应分组.'));
        }
        
        $config = $this->_getConfig();

        return $this->_showActiveDialog([
            'username'=> $username,
            'password'=> $password,
            'name'=> $info->name,
            'email'=> $info->email,
            'group'=> $info->org_no,
            'title'=> $info->org_name,
            'icon'=> $config->icon,
            'type'=> $config->name
        ]);
    }

    /**
        * @brief 执行登录逻辑
        *
        * @return 
     */
    public function actionLogin()
    {
        // 如果用户已经登录
        if ($this->isLogin()) {
            return $this->showJSON(true);
        }

        $form = $this->form('post');
        $username = trim($form['username']);
        $password = $form['password'];

        if (!$username || !$password) {
            return $this->showJSON('请填写用户名和密码');
        }

        // 验证用户一卡通和密码是否匹配
        if (!$this->_verify($username, $password)) {
            return $this->showJSON('卡号密码不匹配');
        }

        // 以一卡通号获取gapper用户信息
        // 获取不到表示用户在gapper不存在，需要激活，展示激活表单
        $info = self::getRPC()->user->getUserByIdentity(self::$identitySource, $username);
        if (!$info || !$info['id']) {
            return $this->_showActiveME($username, $password);
        }

        // 用户已经存在，正常登录
        $result = \Gini\Gapper\Client::loginByUserName($info['username']);
        if ($result) {
            return $this->showJSON(true);
        }

        return $this->showJSON(T('Login failed! Please try again.'));
    }

    /**
        * @brief 获取登录表单
        *
        * @return 
     */
    public function actionGetForm()
    {
        $config = $this->_getConfig();
        return $this->showHTML('gapper/auth/nk/login', [
            'icon'=> $config->icon,
            'type'=> $config->name
        ]);
    }
}
