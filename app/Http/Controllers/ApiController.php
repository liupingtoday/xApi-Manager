<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\apiRequest;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\ApiList;
use App\Models\ApiDetail;
use App\Models\ApiParam;
use App\Models\Audit;
use App\Models\Classify;
use App\Models\User;
use App\Models\Message;
use Input;

class ApiController extends Controller
{
    const CHCHETIME = 5*60;
    protected $limit = 20;
    protected $cls;// 接口分类
    protected $apistatus; //接口状态(1已上线,2待发布,3废弃,4删除)
    protected $request_type; //请求类型
    protected $proid;
    protected $envid;
    protected $request;
    
    public function __construct(Request $request){
        
        //请求数据
        $this->request = $request;
        //当前项目id
        if(!empty($this->request['sys']['Project']['proid'])){
            $this->proid = $this->request['sys']['Project']['proid'];
            $this->envid = $this->request['sys']['Project']['env']['id'];
        }else{
            $this->proid = 0;
            $this->envid = 0;
        }
        //分类数据
        $this->cls = cache::get('classify');
        if(empty($this->cls)){
            $this->cls = Classify::getClassify($this->proid, 0);
            Cache::put('classify', $this->cls, self::CHCHETIME);
        }
        $this->apistatus = array(
            1=>'已审核',
            2=>'待审核',
            3=>'已废弃',
            4=>'已删除',
            5=>'已拒绝'
        );
        $this->request_type = array(
            1=>'GET',2=>'POST',3=>'PUT',4=>'DELETE'
        );
        
    }
    
    /**
     * 获取Api列表
     * @return api列表
     */
    public function getApiList()
    {
        //字母分类
        $letter = array('A','B','C','D','E','F','G','H','I','J','K',
            'L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z');
        
        $gather = array();
        foreach ($this->cls as $class){
            foreach ($class['child'] as $sub){
                $key = pinyin($sub['classifyname'], 'one');
                $key = strtoupper($key);
                $gather[$key][] = array(
                    'id'    => $sub['id'],
                    'classifyname'  => $sub['classifyname'],
                );
            }
        }
        //分页   
        $totalCount = ApiList::where(['proid'=>$this->proid, 'status'=>1])->count();
        
        $result = array(
            'letter' => array_keys($gather),
            'gather' => $gather,
            'pageCount' => ceil($totalCount/$this->limit)
        );
        return view('api.list', ['list'=>$result]);
    }
    /**
     * 获取 ajax Api列表
     * @return api列表
     */
    public function ajaxApiList(Request $request){
        
        $proid = Input::get('proid');
        if(empty($proid)){
            $proid = $this->proid;
        }
        $type = Input::get('type');
        if($type=='search'){
            return $this->searchApiList($proid, $this->envid);
        }else{
            return $this->normalApiList($proid, $this->envid);
        }
    }
    /**
     * 获取所有 Api列表
     * @return api列表
     */
    public function normalApiList($proid, $envid){
        
        //获取查询条件
        $subClassifyId = Input::get('subClassify');
        //$envid = Input::get('env');
        $page = Input::get('page');
        $page = !empty($page) ? $page : 1;
        $start = ($page - 1) * ($this->limit);
        $where = array(
            'proid' => $proid,
            'status' => 1,
            'envid' => $envid
        );
        if(!empty($subClassifyId)){
            $where[] = array('classify','=',intval($subClassifyId));
        }
        //查询列表信息
        $list = ApiList::where($where)->orderBy('id', 'desc')
            ->offset($start)
            ->limit($this->limit)->get();
        $totalCount = ApiList::where($where)->count();
        $list = !empty($list) ? $list->toArray() : array();
        $listIds = array();
        foreach ($list as $value){
            $listIds[] = $value['id'];
        }
        //查询列表详情信息
        $detail = ApiDetail::whereIn('listid', $listIds)->whereIn('status',array(1,2,3,5))->get();
        $detail = !empty($detail) ? $detail->toArray() : array();
        $listDetail = array();
        $author = array();
        foreach ($detail as $value){
            $listDetail[$value['listid']][] = array(
                'id'    => $value['id'],
                'listid' => $value['listid'],
                'URI'   => $value['URI'],
                'version'   => $value['version'],
                'author'    => $value['author'],
                'apistatus' => $this->apistatus[$value['status']],
                'status'    => $value['status'],
                'ctime'   => date('Y-m-d',$value['ctime']),
                'type' => !empty($this->request_type[$value['type']]) ? $this->request_type[$value['type']] : 'GET',
            );
            $author[] = $value['author'];
        }
        //批量查询用户信息
        $user = User::whereIn('uid', $author)->get();
        $user = !empty($user) ? $user->toArray() : array();
        foreach ($user as $value){
            $userGather[$value['uid']] = $value['username'];
        }
        foreach ($listDetail as $key=>&$details){
            foreach ($details as $kt=>&$dts){
                $dts['username'] = $userGather[$dts['author']];
            }
        }
        $result = array();
        foreach ($list as $apiInfo){
            $result[] = array(
                'listid' => $apiInfo['id'],
                'apiname' => $apiInfo['apiname'],
                'info' => !empty($listDetail[$apiInfo['id']]) ? $listDetail[$apiInfo['id']] : array()
            );
        }
        return response()->json([
            'status'=>200,
            'data'=>array(
                'pageCount'=>ceil($totalCount/$this->limit), 
                'list'=>$result
            )
        ]);
    }
    /**
     * 获取搜索符合条件的 Api列表，同一类型接口只显示匹配到的结果
     * @return api列表
     */
    public function searchApiList($proid, $envid){
    
        $page = Input::get('page');
        $page = !empty($page) ? $page : 1;
        $limit = Input::get('limit');
        $limit = !empty($limit) ? $limit : $this->limit;
        $start = ($page - 1) * ($limit);
        //分类判断
        $classify = Input::get('classify');
        $subClassify = Input::get('subClassify');
        if(!empty($subClassify)){
            $subClass = array($subClassify);
        }elseif(!empty($classify)){
            $apicls = array();
            foreach ($this->cls as $class){
                if(!empty($class['child'])){
                    foreach ($class['child'] as $sub){
                        $apicls[$class['id']][] = $sub['id'];
                    }
                }
            }
            //分类值不为空，子分类为空，此时查询无数据，令$subClass = array(0);
            $subClass = !empty($apicls[$classify]) ? $apicls[$classify] : array(0);
        }else{
            $subClass = array();
        }
        //获取查询条件
        $param = array(
            'proid' => $proid,
            'envid' =>intval($envid),
            'classify' => $subClass,
            'apiname' => Input::get('apiname'),
            'URI'   => Input::get('URI'),
            'author' => array(),
        );
        $author = Input::get('author');
        if(!empty($author)){
            $user = User::where('username', 'like', "%{$author}%")->get();
            $user = !empty($user) ? $user->toArray() : array();
            $uids = array();
            foreach ($user as $value){
                $uids[] = $value['uid'];
            }
            //9999999 当查询用户不存在时，默认的uid
            $param['author'] = !empty($uids) ? array_unique($uids) : array(9999999);
        }
        //获取列表详情
        $ApiStatus = Input::get('status');
        if(!empty($ApiStatus)){
            $status = explode(',', $ApiStatus);
        }else{
            $status = array(1,2,3,5);
        }
        $alt = new ApiList();
        $list = $alt->getApiDetail($param, $status ,$start, $this->limit);
        $authorId = array();
        foreach ($list['info'] as &$lst){
            $authorId[] = $lst['author'];
        }
        //批量查询用户信息
        $user = User::whereIn('uid', array_unique($authorId))->get();
        $user = !empty($user) ? $user->toArray() : array();
        $userGather = array();
        foreach ($user as $value){
            $userGather[$value['uid']] = $value['username'];
        }
        $data = array();
        if(!empty($list['info'])){
            foreach ($list['info'] as $apiinfo){
                $data[$apiinfo['listid']][] = array(
                    'id'    => $apiinfo['id'],
                    'listid' => $apiinfo['listid'],
                    'apiname' => $apiinfo['apiname'],
                    'URI'   => $apiinfo['URI'],
                    'version'   => $apiinfo['version'],
                    'username'    => $userGather[$apiinfo['author']],
                    'apistatus' => $this->apistatus[$apiinfo['status']],
                    'status'    => $apiinfo['status'],
                    'ctime'   => date('Y-m-d',$apiinfo['ctime']),
                    'type' => !empty($this->request_type[$apiinfo['type']]) ? $this->request_type[$apiinfo['type']] : 'GET',
                );
            }
        }
        $result = array();
        foreach ($data as $value){
            $result[] = array(
                'listid' => $value[0]['listid'],
                'apiname' => $value[0]['apiname'],
                'info' => $value
            );
        }
        
        return response()->json([
            'status'=>200, 
            'data'=>array(
                'pageCount'=>ceil($list['totalCount']/$this->limit), 
                'list'=>$result
            )
        ]);
    }
    /**
     * API接口添加页面
     */
    public function infoApi(){
        
        $data = $this->ApiDetail();
        
        return view('api.info', ['data'=>$data]);
    }
    /**
     * 获取当前分类
     * @param $classify 分类集合
     * @param $classifyId  分类id
     */
    public function currentClassify($classify, $classifyId){
        
        $class = Classify::where('id', $classifyId)->first();
        $class = !empty($class) ? $class->toArray() : array();
        
        if(!empty($class) && !empty($classify)){
            $result = array(
                'classifyId'  => $class['pid'],
                'classifyName' => !empty($classify[$class['pid']]) ? $classify[$class['pid']] : '',
                'subClassifyId' => $class['id'],
                'subClassifyName' => !empty($classify[$class['id']]) ? $classify[$class['id']] : '',
            );
        }
        
        return $result;
    }
    /**
     * 获取接口详情页面
     */
    public function getApiDetail(Request $request){
        
        $apiEnv = $request['sys']['ApiEnv'];
        $env = array();
        foreach ($apiEnv as $value){
            $env[] = $value['envname'];
        }
        $data = $this->ApiDetail();
        $data['envinfo'] = implode(' > ', $env);

        return view('api.detail', ['data'=>$data]);
    }
    /**
     * 获取接口详情数据
     */
    public function ApiDetail(){
        //多级联动分类
        $info = array();
        foreach ($this->cls as $key=>$value){
            $info[$key]['id'] = $value['id'];
            $info[$key]['name'] = $value['classifyname'];
            $classify[$value['id']] = $value['classifyname'];
            if(!empty($value['child'])){
                $child = array();
                foreach ($value['child'] as $kol=>$vol){
                    $classify[$vol['id']] = $vol['classifyname'];
                    $child[$kol]['id'] = $vol['id'];
                    $child[$kol]['ct'] = $vol['classifyname'];
                }
                $info[$key]['child'] = $child;
            }
        }
        $data['classify'] = json_encode($info);
        //初始化参数
        $data['detail'] = array(
                'header' => array(1),
                'request'=>array(1),
                'response'=>array(1),
                'statuscode'=>array(1)
            );
        //添加版本
        $version_type = Input::get('version_type');
        $listid = intval(Input::get('lid'));
        $detailid = Input::get('did');
        if($version_type=='add' && !empty($listid)){
            $apilist = ApiList::where('id', $listid)->first();
            $apilist = !empty($apilist) ? $apilist->toArray() : array();
            $data['apiname'] = $apilist['apiname'];
            $data['lid'] = $listid;
            $data['version_type'] = 'add';
            //当前接口分类
            if(!empty($apilist)){
                $data['currentClassify'] = $this->currentClassify($classify, $apilist['classify']);
            }
        }
        //接口详情
        if($version_type!='add' && !empty($detailid)){
            $data['detail'] = ApiDetail::where('id', $detailid)->first();
            $data['detail'] = !empty($data['detail']) ? $data['detail']->toArray() : array();
            if(!empty($data['detail'])){
                $apilist = ApiList::where('id', $data['detail']['listid'])->first();
                $apilist = !empty($apilist) ? $apilist->toArray() : array();
                $data['apiname'] = $apilist['apiname'];
            }
            //审核状态
            $audit = Audit::where('did', $data['detail']['id'])->first();
            $audit = !empty($audit) ? $audit->toArray() : array();
            if(!empty($audit)){
                $auditor = User::where('uid', $audit['auditor'])->value('username');
                $data['audit'] = array(
                    'status'    => $audit['status'],
                    'remark'    => $audit['remark'],
                    'auditor'   => $auditor,
                );
            }
            //负责人
            $editor = $data['detail']['editor'];
            $user = User::whereIn('uid', explode(',', $editor))->get();
            $user = !empty($user) ? $user->toArray() : array();
            foreach ($user as $value){
                $userInfo[$value['uid']] = $value['username'];
            }
            if(!empty($userInfo)){
                $data['editor'] = array(
                    'username'  => implode(',', $userInfo),
                    'mtime' => date('Y-m-d H:i', $data['detail']['mtime'])
                );
            }
            //当前接口分类
            if(!empty($apilist)){
                $data['currentClassify'] = $this->currentClassify($classify, $apilist['classify']);
            }
            //常规参数、状态码
            $way  = array('header', 'request', 'response', 'statuscode');
            foreach ($way as $value){
                $data['detail'][$value] = json_decode($data['detail'][$value], true);
            }
            //去掉返回示例中的空白字符
            $data['detail']['example'] = str_replace(array(" ","　","\t","\n","\r"), array("","","","",""), $data['detail']['goback']);
            
            $mockUrl = is_HTTPS() ? 'https://' : 'http://';
            $data['detail']['mockUrl'] = $mockUrl.$_SERVER["HTTP_HOST"].'/Mock'.$data['detail']['gateway'];
        }
        return $data;
    }
    /**
     * 接口审核/审核页面
     * @param Request $request
     * @return 审核结果或页面
     */
    public function audit(Request $request){
        
        if(!empty($request->isMethod('post'))){
            $did = Input::get('did');
            $status = intval(Input::get('status'));
            $status = in_array($status, array(1,2)) ? $status : 2;
            //实例化审核对象
            $audit = Audit::find($did);
            if(empty($audit)){
                $audit = new Audit();
            }
            //保存或更新审核表
            if(!empty($audit)){
                $audit->auditor = Session::get('uid');
                $audit->did = $did;
                $audit->status = $status;
                $audit->isdel = 2;
                $audit->remark = Input::get('des');
                $audit->ctime = time();
                $auditStatus = $audit->save();
            }
            //更新API详情表中的接口状态
            $detail = ApiDetail::find($did);
            if(!empty($detail)){
                $detail->status = ($status==1) ? 1 : 5;
                $detail->save();
            }
            //发送审核通知
            Message::sendMessage(
                array(
                    'sender'    => 1,
                    'receiver'  => $detail->author,
                    'pid'   => 0,
                    'subject'   => 'Api 审核通知',
                    'content'   => "Api:{$detail->gateway} 审核不通过，原因：".$audit->remark.'。请修改后提交',
                    'sendtime'  => time(),
                )    
            );
            //返回操作状态
            if(!empty($auditStatus) && !empty($detail->id)){
                return response()->json(['status'=>200, 'message'=>'操作成功', 'auditStatus'=>$status]);
            }else{
                return response()->json(['status'=>2010, 'message'=>'操作失败，请稍后重试!']);
            }
            
        }
        
        return view('api.audit', ['data'=>array()]);
    }
    /**
     * API接口搜索页面
     */
    public function getSearch(){
        
        //多级联动分类
        $info = array();
        foreach ($this->cls as $key=>$value){
            $info[$key]['id'] = $value['id'];
            $info[$key]['name'] = $value['classifyname'];
            $classify[$value['id']] = $value['classifyname'];
            if(!empty($value['child'])){
                foreach ($value['child'] as $kol=>$vol){
                    $classify[$vol['id']] = $vol['classifyname'];
                    $child[$kol]['id'] = $vol['id'];
                    $child[$kol]['ct'] = $vol['classifyname']; 
                }
                $info[$key]['child'] = $child;
            }
        }
        $first = array(
            0 => array('id'=>0,'name'=>'请选择','child'=>array())
        );
        $info = array_merge($first, $info);
        
        $data['classify'] = json_encode($info);
        return view('api.search', ['data'=>$data]);
    }
    /**
     * 接口保存
     * 每一种请求方式下只能有一个同名接口（同路径）
     */
    public function apiStore(apiRequest $request){
        
        //防止post频繁快速提交
        Session::put('quickTime', time());
        $listid = Input::get('lid');
        $version_type = Input::get('version_type');
        $version_lid = Input::get('version_lid');
        $proid = $request['sys']['Project']['proid'];
        $envid = $request['sys']['ApiEnv'][0]['id'];
        
        //保存接口列表
        if(!empty($envid)){
            //添加版本接口
            if($version_type=='add' && !empty($version_lid)){
                $listid = $version_lid;
            }else{
                $listid = $this->apiListStore($_POST, $proid, $envid, $listid);
            }
        }
        //保存接口详情
        if(!empty($listid)){
            $detailid = $this->apiDetailStore($_POST, $listid);
        }
        if(!empty($detailid)){
            return response()->json(['status'=>200, 'message'=>'添加成功']);
        }else{
            return response()->json(['status'=>4010, 'data'=>'添加失败']);
        }
    }
    /**
     * 将Api保存到列表中
     * @param $data   接口信息
     * @param $proid  项目id
     * @param $envid  Api环境id
     * @param $listid 列表id
     */
    public function apiListStore($data, $proid, $envid, $listid){
        
        if(!empty($listid)){
            $list = ApiList::find($listid);
        }
        if(empty($list)){
            $list = new ApiList();
            $list->envid = $envid;
            $list->proid = $proid;
        }
        $list->classify = $data['subClassify'];
        $list->apiname = $data['apiname'];
        $list->status = 1;
        $list->save();
        
        return $list->id;
    }
    /**
     * 将Api保存到详情中
     * @param $data 接口信息
     * @param $listid 接口列表id
     */
    public function apiDetailStore($data, $listid){
        
        $editor = array();
        $detailid = $data['did'];
		if(!empty($detailid)){
			$detail = ApiDetail::find($detailid);
			if(!empty($detail)){
				$editor = explode(',', $detail->editor);
				$status = $detail->status = 5 ? 2 : $detail->status;
			}
		}else{
			$detail = new ApiDetail();
			$detail->ctime = time();
			$status = 2;
		}
		$editor[] = session::get('uid');
		//格式化数据
		$network = !empty($data['network']) ? $data['network'] : array(2);
		$field = array('header', 'request', 'response');
		foreach ($field as $value){
		    if(!empty($data['param'][$value])){
		        $$value = fieldParamSort($data['param'][$value], 'field');
		    }else{
		        $$value = array ( 0 => array ( 'field' => '', 'fieldType' => '1', 'must' => '1', 'des' => ''));
		    }
		}
		if(!empty($data['scode'])){
		    $statuscode = fieldParamSort($data['scode'], 'status');
		}else{
		    $statuscode = array ( 0 => array ( 'status' => '200', 'des' => '成功'));
		}
		
        if(!empty($detail)){
            $detail->listid = $listid;
            $url = parse_url($data['gateway']);
            $detail->URI = $url['path'];
            $detail->version = $data['version'];
            $detail->gateway = $data['gateway'];
            $detail->local = $data['local'];
            $detail->description = $data['description'];
            $detail->author = session::get('uid');
            $detail->editor = implode(',', $editor);
            $detail->network = $network;
            $detail->type = $data['request_type'];
            $detail->response_type = $data['response_type'];
            $detail->isheader = $data['isheader'];
            $detail->header = json_encode($header, JSON_UNESCAPED_UNICODE);
            $detail->request = json_encode($request, JSON_UNESCAPED_UNICODE);
            $detail->response = json_encode($response, JSON_UNESCAPED_UNICODE);
            $detail->statuscode = json_encode($statuscode, JSON_UNESCAPED_UNICODE);
            $detail->goback = $data['goback'];
            $detail->mtime = time();
            $detail->status = $status;
            $detail->save();
        }
        if($detail->id){
            //保存时删除已拒绝的审核记录
            $did = $detail->id;
            Audit::where(['did'=>$did, 'status'=>2])->delete();
        }
        
        return $detail->id;
    }
    /**
     * Api删除或发布操作
     */
    public function operate(){
        
        $did = Input::get('did');
        $type = intval(Input::get('type'));
        $envid = Input::get('envid');
        if($type==1){
            $result = $this->del($did, $envid);
        }elseif($type==2){
            $result = $this->publish($did, $envid);
        }else{
            $result = response()->json(['status'=>4010, 'message'=>'不支持的操作类型']);
        }
        
        return $result;
    }
    /**
     * Api删除
     */
    public function del($did, $envid){
        $detail = ApiDetail::find($did);
        $arr = array();
        if(!empty($detail)){
            $listid = $detail->listid;
            if(!empty($listid)){
                $arr = ApiDetail::where('listid',$listid)->where('id','!=',$did)->where('status','!=',4)->get();
                $arr = !empty($arr) ? $arr->toArray() : array();
            }
            if(empty($arr)){
                $list = ApiList::find($listid);
                $list->status = 2;
                $list->save();
            }
            $detail->status = 4;
            $detail->save();
        }
        if($detail->id){
            return response()->json(['status'=>200, 'message'=>'删除成功']);
        }else{
            return response()->json(['status'=>4011, 'message'=>'删除失败']);
        }
    }
    /**
     * Api发布
     */
    public function publish($did, $envid){
        
        //环境检测
        $apienv = cache::get('apienv');
        $env = array();
        foreach ($apienv as $value){
            $sysEnv[$value['id']] = $value['envname']; 
            $env[] = $value['id'];
        }
        sort($env);
        $key = array_search($envid, $env);
        //待发布环境id
        if(!empty($env[$key+1])){
            $next_env = $env[$key+1];
        }else{
            return response()->json(['status'=>4011, 'message'=>'下级环境不存在，请确认后重试']);
        }
        //获取详情信息
        $detailObj = ApiDetail::where('id', $did)->first();
        $detail = !empty($detailObj) ? $detailObj->toArray() : array();
        if(!empty($detail) && $detail['status']==1){
            $listid = $detail['listid'];
            //获取列表信息
            $listOjb = ApiList::where('id', $listid)->first();
            $list = !empty($listOjb) ? $listOjb->toArray() : array();
            //同步列表信息
            $syslid = $this->syncApiList($list, $envid, $next_env);
            //同步详情信息
            $sysdid = $this->syncApiDetail($detail, $syslid);
        }
        if(!empty($syslid) && !empty($sysdid)){
            $cenv = !empty($sysEnv[$next_env]) ? $sysEnv[$next_env] : '下级环境';
            return response()->json([
                'status'=>200, 
                'message'=>'已同步到'.$cenv.'<br/>请切换到该环境后查看Api列表'
            ]);
        }else{
            return response()->json(['status'=>4010, 'message'=>'同步失败']);
        }
        
    }
    /**
     * 同步列表信息
     * @param $data  列表信息
     * @param $envid 当前环境
     * @param $next_env 下一个环境
     */
    public function syncApiList($data, $envid, $next_env){
        
        $initid = ($data['initid'] == 0) ? $data['id'] : $data['initid'];
        $lid = ApiList::where('envid', $next_env)->where('initid',$initid)->value('id');
        if(!empty($lid)){
            $list = ApiList::find($lid);
            $list->id = $lid;
        }
        if(empty($list)){
            $list = new ApiList();
        }
        $list->proid = $this->proid;
        $list->envid = $next_env;
        $list->classify = $data['classify'];
        $list->apiname = $data['apiname'];
        $list->initid = $initid;
        $list->status = 1;
        $list->save();
        
        return $list->id;
    }
    /**
     * 同步详情信息
     * @param $data 详情信息
     * @param $lid  列表id
     */
    public function syncApiDetail($data, $lid){
        
        if(empty($lid)){
            return 0;
        }
        $version = $data['version'];
        $id = ApiDetail::where('listid', $lid)->where('version', $version)->value('id');
        if(!empty($id)){
            $detail = ApiDetail::find($id);
            $detail->id = $id;
        }
        if(empty($detail)){
            $detail = new ApiDetail();
        }
        $data['listid'] = $lid;
        $field = array('listid', 'URI', 'version', 'gateway', 'local', 'description',
            'author', 'editor', 'network', 'type', 'response_type', 'isheader', 'header',
            'request', 'response', 'statuscode', 'goback' , 'auth', 'status', 'mtime', 'ctime'
        );
        foreach ($field as $value){
            $detail->$value = $data[$value];
            if($value=='mtime'){
                $detail->$value = time();
            }
        }
        $detail->save();
        
        return $detail->id;
        
    }
    /**
     * 同步参数信息
     * @param $data 参数信息
     * @param $did  详情id
     */
    public function syncApiParam($data, $did){
        if(empty($did)){
            return 0;
        }
        $id = ApiParam::where('detailid', $did)->value('id');
        if(!empty($id)){
            $params = ApiParam::find($id);
            $params->id = $id;
        }
        if(empty($params)){
            $params = new ApiParam();
        }
        $data['detailid'] = $did;
        $field = array('detailid', 'type', 'header', 'request', 'response', 'statuscode');
        foreach ($field as $value){
            $params->$value = $data[$value];
        }
        $params->save();
        
        return $params->id;
        
    }
    /**
     * 接口废弃
     */
    public function discard(){
        
        $did = Input::get('did');
        $detail = ApiDetail::find($did);
        if(!empty($detail)){
            $detail->status = 3;
            $detail->save();
        }
        if(!empty($detail->id)){
            return response()->json(['status'=>200, 'message'=>'废弃成功']);
        }else{
            return response()->json(['status'=>4010, 'message'=>'废弃失败']);
        }
        
    }
}
