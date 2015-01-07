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

    private function _getConfig()
    {
        $infos = (array)\Gini\Config::get('gapper.auth');
        $info = (object)$infos['NK'];
        return $info;
    }

    /** TODO
        * @brief 一卡通密码验证
        *
        * @param $username
        * @param $password
        *
        * @return  boolean
     */
    private function _verify($username, $password)
    {
        return true;
    }

    /** TODO
        * @brief 一卡通用户信息获取
        *
        * @param $username
        * @param $password
        *
        * @return (object)
     */
    private static function _getUserInfo($username, $password)
    {
        return (object)[
            'isTeacher'=> true,
            'email'=> 'test@test.com',
            'password'=> '123456',
            'name'=> 'NAME',
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

        // 如果以username已经存在，直接报错
        // 其实不应该出现这个问题，如果出现，应该是用户自己在捣鬼
        $info = self::getRPC()->user->getInfo($username);
        if ($info['id']) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
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

        // 验证用户一卡通和密码是否匹配
        // 如果不匹配，应该是用户自己不怀好意的改了
        if (!$this->_verify($username, $password)) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
        }

        $info = self::_getUserInfo($username, $password);
        if (!$info || !$info->isTeacher) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
        }

        // 注册gapper用户
        $uid = self::getRPC()->user->registerUser([
            'username'=> $username,
            'password'=> $password,
            'name'=> $name,
            'email'=> $email
        ]);

        if (!$uid) {
            return $this->showJSON(T('用户激活失败, 请重试!'));
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
        // TODO 应该不会失败，如果失败怎么办？
        if (!$bool) {
            return $this->showJSON(T('用户激活成功，但是暂时无法访问该网站，请联系网站客服解决问题.'));
        }

        // 创建成功，直接以新建用户和组登录app
        $bool = $this->_autoLogin($username, $gid);

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
        $info = self::_getUserInfo($username, $password);
        if (!$info) {
            return $this->showJSON(T('获取用户信息失败, 暂时无法激活. 请重试!'));
        }
        
        // 只有老师才能在注册测时候激活，学生需要由老师添加账号
        if (!$info->isTeacher) {
            return $this->showJSON(T('请联系课题组管理员将您加入相应分组.'));
        } 

        $config = $this->_getConfig();

        return $this->_showActiveDialog([
            'username'=> $username,
            'password'=> $password,
            'name'=> $info->name,
            'email'=> $info->email,
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
        $info = self::getRPC()->user->getInfo($username);
        if (!$info || !$info['id']) {
            return $this->_showActiveME($username, $password);
        }

        // 用户已经存在，正常登录
        $result = \Gini\Gapper\Client::loginByUserName($username);
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
            'type'=> $config->type
        ]);
    }
}
