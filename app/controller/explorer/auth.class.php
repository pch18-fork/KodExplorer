<?php
/*
 * @link http://kodcloud.com/
 * @author warlee | e-mail:kodcloud@qq.com
 * @copyright warlee 2014.(Shanghai)Co.,Ltd
 * @license http://kodcloud.com/tools/license/license.txt
 */

/**
 * 文档操作权限旁路拦截；
 * 
 * 如下是配置单个文档操作，统一path参数进行权限检测
 * 其他拦截：path为虚拟目录只支持列表模式 explorer.list.path;
 */
class explorerAuth extends Controller {
	private $actions;
	private $actionPathCheck;
	function __construct() {
		parent::__construct();
		$this->isShowError = true; //检测时输出报错;
		$this->actionPathCheck = array(
			'show'		=> array("explorer.list"=>'path'),
			'view'		=> array('explorer.index'=>'fileOut,fileView,fileThumb','explorer.editor' =>'fileGet'),
			'download'	=> array(''),// 下载/复制;下载/复制/文件预览打印
			'upload'	=> array('explorer.upload'=>'fileUpload,serverDownload'),
			'edit'		=> array(
				'explorer.index'	=>'mkdir,mkfile,setDesc,fileSave,pathRename,pathPast,pathCopyTo,pathCuteTo',
				'explorer.editor' 	=>'fileSave'
			),
			'remove'	=> array('explorer.index'=>''),	//批量中处理
			'share'		=> array('explorer.userShare'=>'add'),
			'comment'	=> array('explorer.index'=>''),
			'event'		=> array('explorer.index'=>'pathLog'),
			'root'		=> array('explorer.index'=>'setAuth'),
		);
		$this->actionCheckSpace = array(//空间大小检测
			'explorer.upload'	=> 'fileUpload,serverDownload',
			'explorer.index'	=> 'mkdir,mkfile,fileSave,pathPast,pathCopyTo,pathCuteTo',
			'explorer.editor' 	=> 'fileSave',
			'explorer.share'	=> 'fileUpload',
		);
		$this->actions = array(
			'list'		=> 'explorer.list.path',
			'delete'	=> 'explorer.index.pathDelete',
		);	
		foreach ($this->actions as &$val) {
			$val = strtolower($val);
		}
		Hook::bind('SourceModel.createBefore','explorer.auth.checkSpaceOnCreate');
	}

	// 文档操作权限统一拦截检测
	public function autoCheck(){
		$theMod 	= strtolower(MOD);
		$theAction 	= strtolower(ACTION);
		if($theMod !== 'explorer') return;

		$this->targetSpaceCheck();//空间大小检测
		//直接检测；定义在actionPathCheck中的方法；参数为path，直接检测
		$actionParse = $this->actionParse();
		if(isset($actionParse[$theAction])){
			return $this->can($this->in['path'],$actionParse[$theAction]);
		}

		// 多个请求或者包含来源去向的，分别进行权限判别；
		switch ($theAction) {//小写
			case 'explorer.index.pathinfo':$this->checkAuthArray('show');break;
			case 'explorer.index.zip':$this->checkAuthArray('edit');break;
			case 'explorer.index.zipdownload':$this->checkAuthArray('download');break;
			case 'explorer.index.unzip':
				$this->canRead($this->in['path']);
				$this->canWrite($this->in['pathTo']);
				break;
			case 'explorer.index.pathdelete':$this->checkAuthArray('remove');break;
			case 'explorer.index.pathcopy':$this->checkAuthArray('download');break;
			case 'explorer.index.pathcute':
				$this->checkAuthArray('download');
				$this->checkAuthArray('remove');
				break;
			case 'explorer.index.pathcopyto':
				$this->checkAuthArray('download');
				$this->canRead($this->in['path']);
				break;
			case 'explorer.index.pathcuteto':
				$this->checkAuthArray('download');
				$this->checkAuthArray('remove');
				$this->canWrite($this->in['path']);
				break;
			default:break;
		}
	}
	
	public function targetSpaceCheck(){
		$actions = array();
		foreach ($this->actionCheckSpace as $controller => $stActions) {
			$stActions = explode(',',trim($stActions,','));
			foreach ($stActions as $action) {
				$actions[] = strtolower($controller.'.'.$action);
			}
		}
		if(!in_array(strtolower(ACTION),$actions)) return;
		$parse  = KodIO::parse($this->in['path']);
		$info 	= IO::infoAuth($parse['pathBase']);//目标存储;
		$space  = Action("explorer.list")->targetSpace($info);
		if(!$space || $space['sizeMax']==0 ) return; // 没有大小信息,或上限为0则放过;
		if($space['sizeMax'] <= $space['sizeUse']){
			show_json(LNG('explorer.spaceIsFull'),false);
		}
	}
	
	public function pathSpaceCheck($space){
		if(!$space || $space['sizeMax']==0 ) return; // 没有大小信息,或上限为0则放过;
		if($space['sizeMax'] <= $space['sizeUse']){
			show_json(LNG('explorer.spaceIsFull'),false);
		}
	}
	public function checkSpaceOnCreate($sourceInfo){
		if($sourceInfo['targetType'] == SourceModel::TYPE_GROUP){
			$space = Model('User')->getInfo($sourceInfo['targetID']);
		}else if($sourceInfo['targetType'] == SourceModel::TYPE_USER){
			$space = Model('User')->getInfo($sourceInfo['targetID']);
		}else{
			return;
		}

		$space['sizeMax'] = $space['sizeMax']*1024*1024*1024;
		$space['sizeUse'] = intval($space['sizeUse']);
		if($space['sizeMax']==0 ) return; // 上限为0则放过;
		if($space['sizeMax']  <= $space['sizeUse']+ $sourceInfo['size'] ){
			show_json(LNG('explorer.spaceIsFull'),false);
		}
	}
	
	// 外部获取文件读写权限; Action("explorer.auth")->fileCanRead($path);
	public function fileCanRead($file){
		$this->isShowError = false;
		$result = $this->canView($file) && $this->canRead($file);
		$this->isShowError = true;
		return $result;
	}
	public function fileCanWrite($file){
		$this->isShowError = false;
		$result = $this->canWrite($file);
		$this->isShowError = true;
		return $result;
	}

	/**
	 * 检测文档权限，是否支持$action动作
	 * 目录解析：拦截只支持列目录，但当前方法为其他操作的
	 * 获取目录信息：自己的文档，则放过
	 * 
	 * 权限判断：
	 * 1. 是不是：是不是自己的文档；是的话则跳过权限检测
	 * 2. 能不能：
	 * 		a. 是我所在的部门的文档：则通过权限&动作判别
	 * 		b. 是内部协作分享给我的：检测分享信息，通过权限&动作判别
	 * 		c. 其他情况：一律做权限拦截；
	 * 
	 * 操作屏蔽：remove不支持根目录：用户根目录，部门根目录，分享根目录；
	 */
	public function can($path,$action){
		$theAction 	= strtolower(ACTION);
		$parse  = KodIO::parse($path);
		$ioType = $parse['type'];
		// 物理路径 io路径拦截；只有管理员且开启了访问才能做相关操作;
		if( $ioType == KodIO::KOD_IO || $ioType == false ){
			if(request_url_safe($path) && !@file_exists($path)){
				if($action == 'view') return true;
			}else{
				if($GLOBALS['isRoot'] && $this->config["ADMIN_ALLOW_IO"]) return true;
			}
			return $this->errorMsg(LNG('explorer.pathNotSupport'),1001);
		}
		
		//个人挂载目录；跨空间移动复制根据身份处理；
		if( $ioType == KodIO::KOD_USER_DRIVER ){
			return true;
		}

		// 如果是获取列表动作，排除只有读取列表权限
		// 虚拟目录检测;只能查看列表，不能做其他任何操作(新建重命名等)
		// 此类型io可以新建重命名等操作；其他的都是纯虚拟路径只能列表查看
		$allowAction = array( 
			KodIO::KOD_IO,
			KodIO::KOD_SOURCE,
			KodIO::KOD_SHARE_ITEM,
		);
		if(!in_array($ioType,$allowAction)){
			if($theAction == $this->actions['list']){
				return true;//其他虚拟目录只允许列目录
			}else{
				return $this->errorMsg(LNG('explorer.pathNotSupport'),1002);
			}
		}

		//分享内容;分享子文档所属分享判别，操作分享权限判别；
		if( $ioType == KodIO::KOD_SHARE_ITEM){
			return $this->checkShare($parse['id'],trim($parse['param'],'/'),$action);
		}

		// source 类型; 新建文件夹 {source:10}/新建文件夹; 去除
		//文档类型检测：屏蔽用户和部门之外的类型；
		if($GLOBALS['isRoot'] && $this->config["ADMIN_ALLOW_SOURCE"]) return true;
		$pathInfo 	= IO::infoAuth($parse['pathBase']);
		$targetType = $pathInfo['targetType'];
		if( $targetType != 'user' && $targetType != 'group' ){
			return $this->errorMsg(LNG('explorer.noPermissionAction'),1003);
		}
		
		//个人文档；不属于自己
		if( $targetType == 'user' && $pathInfo['targetID'] != USER_ID ){
			return $this->errorMsg(LNG('explorer.noPermissionAction'),1004);
		}

		//部门文档：权限拦截；会自动匹配权限；我在的部门会有对应权限
		if($targetType == 'group'){
			$auth  = $pathInfo['auth']['authValue'];
			return $this->checkAuthMethod($auth,$action);
		}
		// 删除操作：拦截根文件夹；用户根文件夹，部门根文件夹
		if( $pathInfo['parentID'] == '0' && 
			$theAction == $this->actions['delete'] ){
			return $this->errorMsg(LNG('explorer.noPermissionAction'),1100);	
		}
		return true;
	}
	
	private function errorMsg($msg,$code=false){
		if($this->isShowError){
			return show_json($msg,false,$code);	
		}
		$this->lastError = $msg;
		return false;
	}
	
	
	public function canView($path){return $this->can($path,'view');}
	public function canRead($path){return $this->can($path,'download');}
	public function canWrite($path){return $this->can($path,'edit');}
	private function checkAuthArray($action){
		$data = json_decode($this->in['dataArr'],true);
		if(!is_array($data)){
			return $this->errorMsg('param error:dataArr!');
		}
		foreach ($data as $item) {
			$this->can($item['path'],$action);
		}
	}
	
	/**
	 * 根据权限值判别是否允许该动作
	 * $method: view,show,...   AuthModel::authCheckShow...
	 */
	private function checkAuthMethod($auth,$method){
		if($GLOBALS['isRoot'] && $this->config["ADMIN_ALLOW_SOURCE"]) return true;
		if(!$auth || $auth == 0){
			return $this->errorMsg(LNG('explorer.noPermissionAction'),1005);
		}
		$method   = strtoupper(substr($method,0,1)).substr($method,1);
		$method   = 'authCheck'.$method;
		$allow = Model('Auth')->$method($auth);
		if(!$allow){
			return $this->errorMsg(LNG('explorer.noPermissionAction'),1006);
		}
		return true;
	}
	
	/**
	 * 分享检测：
	 * 1. 分享存在；文档存在；
	 * 2. 文档属于该分享或该分享的子目录
	 * 2. 且自己在分享目标中; 权限不等于0 说明自己在该分享中；
	 * 3. 权限动作检测
	 * 
	 * 分享根文件夹不支持删除操作；
	 */
	public function checkShare($shareID,$sourceID,$method){
		$shareInfo = Model('Share')->getInfoAuth($shareID);
		$sharePath = $shareInfo['sourceID'];
		if(!$shareInfo || !$shareInfo['sourceInfo'] ){
			return $this->errorMsg("share not exists!");
		}
		if( $sharePath == $sourceID && ACTION == $this->actions['delete'] ){
			return $this->errorMsg("source share root can't remove !");
		}

		$sourceInfo = Model('Source')->sourceInfo($sourceID);
		$parent = Model('Source')->parentLevelArray($sourceInfo['parentLevel']);
		array_push($parent,$sourceID);
		// pr($parent,$sourceID,$method,$sourceInfo,$shareInfo);exit;
		
		if(!$sourceInfo || !in_array($sourceID,$parent) ){
			return $this->errorMsg("source not in share!");
		}

		// 自己的分享，不判断权限；协作中添加了自己或自己所在的部门；
		if( $sourceInfo['targetType'] == SourceModel::TYPE_USER && 
			$sourceInfo['targetID'] == USER_ID ){
			return true;
		}
		$auth = $shareInfo['auth']['authValue'];
		return $this->checkAuthMethod($auth,$method);
	}
	
	//解析上述配置到action列表；统一转为小写;
	private function actionParse(){
		$actionArray = array();
		foreach ($this->actionPathCheck as $authType => $modelActions) {
			foreach ($modelActions as $controller => $stActions) {
				if(!$stActions) continue;
				$stActions = explode(',',trim($stActions,','));
				foreach ($stActions as $action) {
					$fullAction = strtolower($controller.'.'.$action);
					$actionArray[$fullAction] = $authType;
				}
			}
		}
		return $actionArray;
	}
}