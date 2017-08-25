<?php
if(class_exists('Extension_PluginSetup')):
class WgmHipchat_Setup extends Extension_PluginSetup {
	const POINT = 'wgmhipchat.setup';
	
	function render() {
		$tpl = DevblocksPlatform::services()->template();

		$params = array(
			'api_token' => DevblocksPlatform::getPluginSetting('wgm.hipchat','api_token',''),
			'api_room' => DevblocksPlatform::getPluginSetting('wgm.hipchat','api_room','')
		);
		$tpl->assign('params', $params);
		
		$tpl->display('devblocks:wgm.hipchat::setup.tpl');
	}
	
	function save(&$errors) {
		try {
			@$api_token = DevblocksPlatform::importGPC($_REQUEST['api_token'],'string','');
			@$api_room = DevblocksPlatform::importGPC($_REQUEST['api_room'],'string','');
			
			if(empty($api_token))
				throw new Exception("The API token is required.");
				
			$hipchat = WgmHipchat_API::getInstance();
			$hipchat->setCredentials($api_token);
			
			$response = $hipchat->sendMessageToRoom(
				$api_room,
				'Cerb',
				'(This is an automated test of Cerb integration)',
				'text',
				'green',
				false
			);
			
			if(empty($response))
				throw new Exception("There was a problem connecting!  Please check your auth token.");
			
			if(is_array($response) && isset($response['error']))
				throw new Exception($response['error']['message']);
			
			DevblocksPlatform::setPluginSetting('wgm.hipchat','api_token',$api_token);
			DevblocksPlatform::setPluginSetting('wgm.hipchat','api_room',$api_room);

			return true;
			
		} catch (Exception $e) {
			$errors[] = $e->getMessage();
			return false;
		}
	}
};
endif;

class WgmHipchat_API {
	static $_instance = null;
	private $_api_token = null;
	
	private function __construct() {
		$this->_api_token = DevblocksPlatform::getPluginSetting('wgm.hipchat','api_token','');
	}
	
	/**
	 * @return WgmHipchat_API
	 */
	static public function getInstance() {
		if(null == self::$_instance) {
			self::$_instance = new WgmHipchat_API();
		}

		return self::$_instance;
	}
	
	public function setCredentials($api_token) {
		$this->_api_token = $api_token;
	}
	
	/**
	 *
	 * @param string $path
	 * @param string $post
	 * @return HTTPResponse
	 */
	private function _request($path, array $query=array()) {
		$url = sprintf('https://api.hipchat.com/v1/%s?auth_token=%s', $path, $this->_api_token);
		
		$ch = DevblocksPlatform::curlInit();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POST, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query));
		
		$response = DevblocksPlatform::curlExec($ch);
		curl_close($ch);
		return $response;
	}

	public function sendMessageToRoom($room_id, $from, $message, $message_format='text', $color=null, $notify=true) {
		if(strlen($from) > 15)
			$from = substr($from, 0, 15);
		
		if(strlen($message) > 10000)
			$message = substr($message, 0, 10000);
		
		$query = array(
			'room_id' => $room_id,
			'from' => $from,
			'message' => $message,
		);

		if(!empty($message_format) && in_array($message_format, array('text','html')))
			$query['message_format'] = $message_format;
		
		if(!empty($color) && in_array($color, array('yellow','red','green','purple','gray','random')))
			$query['color'] = $color;
		
		$query['notify'] = ($notify) ? 1 : 0;
		
		$response = $this->_request('rooms/message', $query);
		$response = json_decode($response, true);
		
		return $response;
	}
};

class WgmHipchat_EventActionPost extends Extension_DevblocksEventAction {
	function render(Extension_DevblocksEvent $event, Model_TriggerEvent $trigger, $params=array(), $seq=null) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('params', $params);
		
		if(!is_null($seq))
			$tpl->assign('namePrefix', 'action'.$seq);
		
		$default_room = DevblocksPlatform::getPluginSetting('wgm.hipchat','api_room','');
		$tpl->assign('default_room', $default_room);
		
		$tpl->display('devblocks:wgm.hipchat::action_post_hipchat.tpl');
	}
	
	function simulate($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$out = '';
		
		$tpl_builder = DevblocksPlatform::services()->templateBuilder();
		
		@$room = $tpl_builder->build($params['room'], $dict);
		@$from = $tpl_builder->build($params['from'], $dict);
		@$content = $tpl_builder->build($params['content'], $dict);
		@$run_in_simulator = $params['run_in_simulator'];
		
		if(empty($room))
			return "[ERROR] No room is defined.";
		
		if(empty($from))
			return "[ERROR] No from is defined.";
		
		if(empty($content))
			return "[ERROR] No content is defined.";
		
		if(!empty($content)) {
			$out .= sprintf(">>> Posting to HipChat in room: %s\n\n%s: %s\n",
				$room,
				$from,
				$content
			);
		}
		
		// Run in simulator?
		if($run_in_simulator) {
			$this->run($token, $trigger, $params, $dict);
		}
		
		return $out;
	}
	
	function run($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$hipchat = WgmHipchat_API::getInstance();

		// Translate message tokens
		$tpl_builder = DevblocksPlatform::services()->templateBuilder();
		
		@$room = $tpl_builder->build($params['room'], $dict);
		@$from = $tpl_builder->build($params['from'], $dict);
		@$content = $tpl_builder->build($params['content'], $dict);
		
		if(empty($room) || empty($from) || empty($content))
			return false;
		
		@$is_html = $params['is_html'];
		@$color = $params['color'];
		
		if(empty($is_html)) {
			$messages = DevblocksPlatform::parseCrlfString($content);
		} else {
			$messages = array($content);
		}
		
		if(is_array($messages))
		foreach($messages as $message) {
			$response = $hipchat->sendMessageToRoom(
				$room,
				$from,
				$message,
				($is_html ? 'html' : 'text'),
				$color,
				true
			);
		}
	}
};