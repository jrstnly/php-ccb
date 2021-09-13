<?php

namespace CCB;

class CCB {
	private $xml_class;
	private $curl;
	private $church;
	private $user;
	private $pass;
	private $base_url;
	private $predictable;
	private $headers;
	private $format = "XML";
	private $debug = false;

	/**
	 * Initialize the CCB connection
	 * @param string $church (Your Subdomain)
	 * @param string $user (API User)
	 * @param string $pass (API Password)
	 * @param boolean $predictable (Wait for burst limit to reset before making the call)
	 */
	public function __construct($church, $user, $pass, $predictable = false) {
		$this->xml_class = new XML2Array;
		$this->church = $church;
		$this->user = $user;
		$this->pass = $pass;
		$this->base_url = "https://" . $this->church . ".ccbchurch.com/api.php";
		$this->predictable = $predictable;
		$this->curl = curl_init();
	}
	public function __destruct()  { curl_close($this->curl); }

	/**
	 * Select Data Type
	 * @param string $format
	 * Format Options:
	 *   XML - Return Raw XML
	 *   OBJ - Return StdClass Object (Resource Intensive)
	 *   ARR - Return Associative Array (Resource Intensive)
	 *   SAR - Return Associative Array without attributes (SimpleXML Array - Non resource intensive)
	 *   JSON - Return JSON (Resource Intensive)
	 */
	public function format($format) { $this->format = $format; }

	private function buildGET($srv, $parameters = null) {
		$url = $this->base_url . '?srv=' . $srv;
		if ($parameters && is_array($parameters)) {
			foreach ($parameters as $key => $value) {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}
		return $url;
	}
	private function buildPOST($parameters, $return = NULL) {
		$data = "";
		foreach ($parameters as $keyA => $value) {
			if (is_array($value)) {
				foreach ($value as $keyB => $val) {
					$data .= '&' . $keyA . '[' . $keyB . ']=' . urlencode($val);
				}
			} else {
				$data .= '&' . $keyA . '=' . urlencode($value);
			}
		}
		if ($return == 'count') { return count(explode('&', $data)) - 1; }
		else { return $data; }
	}
 	public function xml2array($xmlObject, $out = array()) {
		foreach ( (array) $xmlObject as $index => $node ) {
			$index = str_replace("@", "", $index);
			if (is_object($node)) {
				$out[$index] = $this->xml2array($node);
			} else if (is_array($node)) {
				$out[$index] = $this->xml2array($node);
			} else {
				$out[$index] = $node;
			}
		}
		return $out;

	}
	private function xmlParser($xml) {
		$p = xml_parser_create();
		xml_parse_into_struct($p, $xml, $vals, $index);
		xml_parser_free($p);
		return $vals;
	}
	private function formatData($data) {
		if ($this->format == 'XML' ) { return $data; }
		if ($this->format == 'SOBJ' ) { return simplexml_load_string($data); }
		if ($this->format == 'OBJ' ) { return $this->xml_class->createArray($data); }
		if ($this->format == 'ARR' ) { return $this->xml2array($this->xml_class->createArray($data)); }
		if ($this->format == 'SAR' ) { return $this->xml2array(simplexml_load_string($data)); }
		if ($this->format == 'JSON') { return json_encode($this->xml2array($this->xml_class->createArray($data))); }
	}

	private function get($srv, $parameters = NULL) {
		curl_setopt_array($this->curl, array(
			CURLOPT_HEADER => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $this->buildGET($srv, $parameters),
			CURLOPT_USERPWD => $this->user.':'.$this->pass
		));
		$ret = $this->execute($this->curl);
		$this->headers = $ret['headers'];
		return $this->formatData($ret["body"]);
	}
	private function post($srv, $parameters, $get_params = null) {
		if ($get_params != null) $url = $this->buildGET($srv, $get_params);
		else $url = $this->buildGET($srv);
		curl_setopt_array($this->curl, array(
			CURLOPT_HEADER => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $url,
			CURLOPT_POST => $this->buildPOST($parameters, 'count'),
			CURLOPT_POSTFIELDS => $this->buildPOST($parameters),
			CURLOPT_USERPWD => $this->user.':'.$this->pass
		));
		$ret = $this->execute($this->curl);
		$this->headers = $ret['headers'];
		return $this->formatData($ret["body"]);
	}
	private function send($srv, $file) {
		curl_setopt_array($this->curl, array(
			CURLOPT_HEADER => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $this->buildGET($srv),
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => array('filedata' => curl_file_create($file,mime_content_type($file),basename($file))),
			CURLOPT_USERPWD => $this->user.':'.$this->pass
		));
		$ret = $this->execute($this->curl);
		$this->headers = $ret['headers'];
		return $this->formatData($ret["body"]);
	}
	private function execute($ch) {
		$last_request = $this->db->getNameValueRecords("SELECT * FROM `ccb_settings` WHERE `Name`='X-RateLimit-Reset' OR `Name`='X-RateLimit-Limit' OR `Name`='X-RateLimit-Remaining'");
		if ((int)$last_request['X-RateLimit-Limit'] >= 120)
			$divisor = 15;
		elseif ((int)$last_request['X-RateLimit-Limit'] >= 60)
			$divisor = 10;
		elseif ((int)$last_request['X-RateLimit-Limit'] >= 30)
			$divisor = 5;
		elseif ((int)$last_request['X-RateLimit-Limit'] >= 15)
			$divisor = 3;
		else
			$divisor = 2;


		if ($this->predictable) {
			if (time() < $last_request['X-RateLimit-Reset']) {
				$offset = $last_request['X-RateLimit-Reset'] - time();
				if ($this->debug) echo "Delaying for burst limit: " . $offset . "\n";
				sleep($offset);
			}
		} else {
			if ((int)$last_request['X-RateLimit-Remaining'] < round((int)$last_request['X-RateLimit-Limit'] / $divisor)) {
				$sleep = round(60 / (int)$last_request['X-RateLimit-Limit']);
				if ($this->debug) echo "Delaying because burst requests almost depleted: " . $sleep . "\n";
				sleep($sleep);
			}
		}

		$ret = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$headers = [];
		$header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header_data = explode("\n", substr($ret, 0, $header_len));
		array_shift($header_data); // Remove status code from headers
		foreach($header_data as $part) {
			if (trim($part) != "") {
				if (strpos($part, ':') !== false) {
					$middle = explode(":",$part);
					$name = trim($middle[0]);
					$value = trim($middle[1]);
				} else {
					$name = trim($part);
					$value = '';
				}
				$this->db->performQuery("INSERT INTO ccb_settings (Name,Value) VALUES('$name', '$value') ON DUPLICATE KEY UPDATE Value='$value'");
				$headers[$name] = $value;
			}
		}
		$body = substr($ret, $header_len);
		$return = ["code"=>$code,"headers"=>$headers,"body"=>$body];

		if ($code == 429) {
			if ($this->debug) echo "Delaying because burst requests completely depleted: " . $headers["Retry-After"] . "\n";
			sleep($headers["Retry-After"]);
			$return = $this->execute($ch);
		}

		return $return;
	}
	public function getDataByAttribute($input, $atr, $val) {
		$return = null;
		foreach ($input as $key => $value) {
			if ($value['attributes'][$atr] == $val) {
				$return = $value['value'];
			}
		}
		return $return;
	}
	public function parseDate($date) {
		if ($date == '0000-00-00 00:00:00') $date = '1970-01-01 00:00:00';
		return date_create_from_format("Y-m-d H:i:s", $date);
	}



	/*********************** EVENT SERVICES ************************/
	public function add_individual_to_event($individual, $event_id, $status) {
		return $this->get('event_profiles', array('id'=>$individual,'event_id'=>$event_id,'status'=>$status));
	}
	public function get_attendance_profile($individual, $occurrence) {
		return $this->get('attendance_profile', array('id'=>$individual,'occurrence'=>$occurrence));
	}
	public function get_attendance_profiles($start_date, $end_date) {
		return $this->get('attendance_profiles', array('start_date'=>$start_date,'end_date'=>$end_date));
	}
	public function get_event_profiles($args = null) {
		if ($args != null) return $this->get('event_profiles', $args);
		else return $this->get('event_profiles');
	}
	public function get_event_profile($id, $args) {
		$args['id'] = $id;
		return $this->get('event_profile', $args);
	}
	public function create_event_attendance($file) {
		return $this->send('create_event_attendance', $file);
	}
	/*********************** FORM SERVICES *************************/
	public function get_form_list() {
		return $this->get('form_list');
	}
	public function get_form_detail($form) {
		return $this->get('form_detail', array('id'=>$form));
	}
	public function get_form_responses($id, $modified_since = null, $other_params = null) {
		$params = ['form_id'=>$id];
		if ($modified_since != null) $params['modified_since'] = $modified_since;
		if ($other_params) array_merge($params, $other_params);
		return $this->get('form_responses', $params);
	}
	/*********************** GROUP SERVICES ************************/
	public function add_individual_to_group($individual, $group, $status = 'add') {
		return $this->get('add_individual_to_group', array('id'=>$individual, 'group_id'=>$group, 'status'=>$status));
	}
	public function remove_individual_from_group($individual, $group) {
		return $this->get('remove_individual_from_group', array('id'=>$individual, 'group_id'=>$group));
	}
	public function get_group_participants($group) {
		return $this->get('group_participants', array('id'=>$group));
	}
	public function get_group_profiles($params = NULL) {
		/* @param: modified_since => datetime, include_participants => boolean, include_image_link => boolean, page => int, per_page => int */
		if ($params == NULL) return $this->get('group_profiles');
		else return $this->get('group_profiles', $params);
	}
	public function get_group_profile_from_id($id, $image = false) {
		$params = ["id"=>$id];
		if ($image) $params['include_image_link'] = 1;
		return $this->get('group_profile_from_id', $params);
	}
	public function get_position_list() {
		return $this->get('position_list');
	}
	/***************** INDIVIDUAL PROFILE SERVICES *****************/
	public function create_individual($first_name, $last_name, $params) {
		$data = array('first_name'=>$first_name,'last_name'=>$last_name);
		foreach ($params as $key => $value) {
			$data[$key] = $value;
		}
		return $this->post('create_individual', $data);
	}
	public function update_individual($id, $params) {
		if (count($params) > 0) {
			return $this->post('update_individual', $params, array('individual_id'=>$id));
		} else {
			return false;
		}
	}
	public function individual_search($params) {
		return $this->get('individual_search', $params);
	}
	public function get_family_detail($id) {
		return $this->get('family_detail', array('family_id'=>$id));
	}
	public function get_valid_individuals() {
		return $this->get('valid_individuals');
	}
	public function get_individual_profiles($params = NULL) {
		/* @param: modified_since => datetime, include_inactive => boolean, page => int, per_page => int */
		if ($params == NULL) return $this->get('individual_profiles');
		else return $this->get('individual_profiles', $params);
	}
	public function get_individual_profile_from_id($id) {
		return $this->get('individual_profile_from_id', array('individual_id'=>$id));
	}
	public function get_individual_id_from_login_password($username, $password) {
		return $this->post('individual_id_from_login_password', array('login'=>$username,'password'=>$password));
	}
	public function get_individual_profile_from_login_password($username, $password) {
		return $this->post('individual_profile_from_login_password', array('login'=>$username,'password'=>$password));
	}
	public function set_individual_credentials($id, $username, $password) {
		return $this->post('set_individual_credentials', array('id'=>$id,'username'=>$username,'password'=>$password));
	}
	public function get_merged_individuals($params = NULL) {
		/* @param: modified_since => datetime */
		if ($params == NULL) return $this->get('merged_individuals');
		else return $this->get('merged_individuals', $params);
	}
	public function get_individual_calendar_listing($id, $startTime, $endTime) {
		return $this->get('individual_calendar_listing', array('id'=>$id,'date_start'=>$startTime,'date_end'=>$endTime));
	}
	public function get_individual_fit() {
		return $this->get('individual_fit');
	}
	public function set_individual_fit($id, $params = []) {
		return $this->post('update_individual_fit', $params, array('individual_id'=>$id));
	}
	public function get_saved_search_listing() {
		return $this->get('search_list');
	}
	public function get_saved_search($id) {
		return $this->get('execute_search', array('id'=>$id));
	}
	/******************** LOOKUP TABLE SERVICES ********************/
	public function get_event_groupings() {
		return $this->get('event_grouping_list');
	}
	public function get_event_grouping($id) {
		return $this->get('event_grouping_detail', array('event_grouping_id'=>$id));
	}
	public function get_group_types() {
		return $this->get('group_type_list');
	}
	public function get_group_groupings() {
		return $this->get('group_grouping_list');
	}
	public function get_membership_types() {
		return $this->get('membership_type_list');
	}
	public function get_meeting_days() {
		return $this->get('meet_day_list');
	}
	public function get_meeting_times() {
		return $this->get('meet_time_list');
	}
	public function get_mobile_carriers() {
		return $this->get('mobile_carrier_list');
	}
	public function get_school_list() {
		return $this->get('school_list');
	}
	public function get_gift_list() {
		return $this->get('gift_list');
	}
	public function get_passion_list() {
		return $this->get('passion_list');
	}
	public function get_ability_list() {
		return $this->get('ability_list');
	}
	public function get_personality_list() {
		return $this->get('style_list');
	}
	public function get_individual_udf_list($type, $num) {
		// Types: pulldown
		return $this->get('udf_ind_'.$type.'_'.$num.'_list');
	}
	/******************** PROCESS SERVICES ********************/
	public function get_process_list($campus_id) {
		return $this->get('process_list', array('campus_id'=>$campus_id));
	}
	public function get_process_managers($process_id) {
		return $this->get('process_managers', array('id'=>$process_id));
	}
	public function get_queue_individuals($queue, $status = NULL) {
		$params = ['id'=>$queue];
		if ($status) $params['status'] = $status;
		return $this->get('queue_individuals', $params);
	}
	public function add_individual_to_queue($individual, $queue, $note = NULL) {
		$params = ['individual_id'=>$individual, 'queue_id'=>$queue];
		if ($note) $params['note'] = $note;
		return $this->get('add_individual_to_queue', $params);
	}
	/*********************** PUBLIC WEB TOOLS **********************/
	public function get_public_calendar_listing($date_start = NULL, $date_end = NULL) {
		$start = $date_start == NULL ? date("Y-m-d") : $date_start;
		$end = $date_end == NULL ? date("Y-m-d") : $date_end;
		return $this->get('public_calendar_listing', array('date_start'=>$start, 'date_end'=>$end));
	}
	public function get_campus_list() {
		return $this->get('campus_list');
	}
	public function get_custom_field_labels() {
		return $this->get('custom_field_labels');
	}
	/*********************** API TESTS **********************/
	public function get_rate_limit_test() {
		$this->debug = true;
		$this->get('rate_limit_test');
		return $this->headers;
		$this->debug = false;
	}
	public function post_rate_limit_test() {
		$this->debug = true;
		$this->post('rate_limit_test', []);
		return $this->headers;
		$this->debug = false;
	}

	/*********************** OTHER FUNCTIONS **********************/
	public function get_phone($type, $arr) {
		$number = "";
		if (isset($arr['phones']) && isset($arr['phones']['phone'])) {
			$phones = $arr['phones']['phone'];
			foreach ($phones as $phone) {
				if ($phone['attributes']['type'] == $type) {
					$number = preg_replace('/[^0-9,]|,[0-9]*$/','',$phone['value']);
				}
			}
		}
		return $number;
	}
	public function get_phone_number($type, $phones) {
		$number = "";
		if (isset($phones)) {
			foreach ($phones as $phone) {
				if ($phone->attributes()->type == $type && isset($phone[0])) {
					$number = preg_replace('/[^0-9,]|,[0-9]*$/','',$phone[0]);
				}
			}
		}
		return $number;
	}
	public function get_user_defined_field($num, $udf) {
		$ret = (object)array('label'=>'','selection'=>'');
		$str = "udf_".$num;
		foreach ($udf as $key => $value) {
			if ($value->name == $str) $ret = $value;
		}
		return $ret;
	}
	function get_address_by_type($type, $addresses) {
		$ret = false;
		if (count((array)$addresses) > 0) {
			foreach ($addresses as $key => $value) {
				if ($value->address->attributes()->type == $type) $ret = $value->address;
			}
		}
		return $ret;
	}

}
?>
