<?php
if(class_exists('Extension_PluginSetup')):
class WgmHipchat_Setup extends Extension_PluginSetup {
	const POINT = 'wgmhipchat.setup';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();

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
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_POST, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		
		$response = curl_exec($ch);
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
		
		if(!$notify)
			$query['notify'] = 0;
		
		$response = $this->_request('rooms/message', $query);
		$response = json_decode($response, true);
		
		return $response;
	}
};

if(class_exists('Extension_DevblocksEventAction')):
class WgmHipchat_EventActionPost extends Extension_DevblocksEventAction {
	function render(Extension_DevblocksEvent $event, Model_TriggerEvent $trigger, $params=array(), $seq=null) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('params', $params);
		
		if(!is_null($seq))
			$tpl->assign('namePrefix', 'action'.$seq);
		
		$default_room = DevblocksPlatform::getPluginSetting('wgm.hipchat','api_room','');
		$tpl->assign('default_room', $default_room);
		
		$tpl->display('devblocks:wgm.hipchat::action_post_hipchat.tpl');
	}
	
	function simulate($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$out = '';
		
		@$room = $params['room'];
		
		if(empty($room))
			return "[ERROR] No room is defined.";
		
		$tpl_builder = DevblocksPlatform::getTemplateBuilder();
		if(false !== ($content = $tpl_builder->build($params['content'], $dict))) {
			$out .= sprintf(">>> Posting to HipChat (%s):\n%s\n",
				$room,
				$content
			);
		}
		
		return $out;
	}
	
	function run($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$hipchat = WgmHipchat_API::getInstance();

		// Translate message tokens
		$tpl_builder = DevblocksPlatform::getTemplateBuilder();
		if(false !== ($content = $tpl_builder->build($params['content'], $dict))) {
			@$room = $params['room'];
			@$from = $params['from'];
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
	}
};
endif;